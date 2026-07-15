# laravel-worktree

A Laravel dev-tool package that provisions and tears down isolated git worktrees for local development with Laravel Herd. Each worktree gets its own Herd site, its own application and test databases, and a rewritten `.env`. Three Artisan commands: `worktree:setup`, `worktree:teardown`, and `worktree:path` (which resolves a branch's directory for shell integrations).

## Architecture

Everything runs from the **main repository**, not from inside the worktree. The commands create the worktree and then shell out into it. This avoids the chicken-and-egg problem where a freshly created worktree has no `vendor` yet.

```
src/
  WorktreeServiceProvider.php   Registers config + all three commands (spatie/laravel-package-tools)
  Worktree.php                  Value object: derives name, host, path, and db names from a branch + config. Pure, no side effects.
  Commands/
    WorktreeCommand.php         Abstract base: process running (Laravel Process facade), git checks, config access
    SetupCommand.php            worktree:setup
    TeardownCommand.php         worktree:teardown
    PathCommand.php             worktree:path (prints a branch's resolved directory)
  Support/
    EnvFile.php                 String editor for .env (set key, remap host). Pure.
    PhpunitConfig.php           DOMDocument editor for phpunit.xml <env> entries. Ignores commented defaults.
    DatabaseManager.php         Driver-aware create/drop via raw PDO (mysql/mariadb/pgsql). Connects without selecting a db.
    WorktreeList.php            Parses `git worktree list --porcelain`. Pure.
  Enums/
    HerdMode.php                secure | link | none (drives scheme + herd command)
    MigrateMode.php             fresh | migrate | none
    FinishMode.php              pr | merge | abandon (teardown)
  Exceptions/WorktreeException.php   Named factory methods
config/worktree.php             The full configuration surface
```

## Key design decisions

- **Pure support classes.** `Worktree`, `EnvFile`, `PhpunitConfig`, `DatabaseManager` (statement/dsn builders), and `WorktreeList` hold the real logic and have no side effects, so they're unit-tested directly. The commands are thin orchestration over them.
- **Child processes get the main app's .env keys unset** (`WorktreeCommand::sourceEnvironment()`). This is the single most important thing in the package. Laravel putenv()s the app's own .env and Symfony passes the parent environment on to children, while phpdotenv refuses to overwrite a variable that is already set. So `php artisan migrate:fresh` run inside the worktree used to read the *main* app's `DB_DATABASE` and drop the *main* database while reporting success. Passing each key as `false` unsets it for the child, which then loads its own .env normally; anything not in the .env (PATH and friends) is still inherited. Any new child process must go through `process()`/`attempt()`/`capture()` so it keeps this.
- **Drivers split into server and file** (`DatabaseManager::isServer()` / `isFile()`), because the isolation problem differs. A server database (mysql/mariadb/pgsql) is shared, so each worktree needs its own named database created and dropped. A file database (sqlite) lives inside the worktree and is already isolated: nothing is named or dropped, and only an absolute `DB_DATABASE` pointing back at the source is redirected (`Worktree::mapPath()`). The file itself *is* created, because Laravel gitignores it (`database/.gitignore` holds `*.sqlite*`) so a fresh worktree never receives one from git and `migrate` would fail. This is what makes a stock Laravel app work untouched.
- **The phpunit rewrite follows the test connection, not the app's** (`SetupCommand::providesTestDatabase()`). A stock Laravel `phpunit.xml` pins the suite to in-memory sqlite; rewriting `DB_DATABASE` there would point sqlite at a file named after a database. Only a server test connection gets a test database and a rewrite.
- **DatabaseManager connects without a database in the DSN**, so `CREATE DATABASE` works when the target does not exist. Postgres connects to the `postgres` maintenance db, checks `pg_database`, and drops `WITH (FORCE)`. MySQL/MariaDB use `IF NOT EXISTS` / `IF EXISTS`.
- **DB names are lowercased and non-alphanumerics collapse to `_`** (`Worktree::slug()`), keeping them valid on both MySQL and Postgres without quoting headaches.
- **Host remapping uses lookbehind/lookahead** so `blog.test` and the cookie-domain form `.blog.test` are rewritten, but `myblog.test`, `sub.blog.test`, and `blog.testing` are not. The optional leading dot is captured and carried over, which is why `remapHost()` uses a callback rather than a replacement string.
- **Directory::delete() never follows a link.** `git worktree remove` leaves behind the `public/storage` link a storage:link step creates, and the surviving directory then blocks setting the branch up again. Laravel's `deleteDirectory()` cannot help: a Windows junction reports `is_link()` false, `is_dir()` false, and `unlink()` fails on it, so it is spotted by being none of link/dir/file and removed with `rmdir()`.
- **PhpunitConfig uses DOMDocument**, not regex, so a stock Laravel `phpunit.xml` with commented-out DB lines gets a real `<env>` appended rather than a comment edited.
- **The patched `phpunit.xml` is marked `git update-index --skip-worktree`.** A stock Laravel `phpunit.xml` is tracked and not gitignored, so patching it would leave every worktree permanently dirty: `teardown --into` would refuse to run, and `--pr` would commit the local test database name into the branch. The skip bit lives in that worktree's own index, so it dies with the worktree and never touches the main repo. `SetupCommand::patchPhpunit()` marks it through `attempt()` rather than `process()`, so a git quirk can never turn the mark into a failed setup.
- **EnvFile is CRLF-aware.** `pattern()` captures the trailing `\r` separately so it never leaks into a value and never gets dropped from a rewritten line. This matters: `TeardownCommand::isSourceDatabase()` compares the value with `===`, and a stray `\r` silently disabled the guard that refuses to drop the main database on Windows.
- **Config drives everything** (herd mode, worktree path, host template, db naming, migrate mode, steps) so the package is not mozex-specific.
- **`--into` leaves the main repo on the target branch.** Merging requires checking it out, and `git branch -d` needs the merge in `HEAD` to succeed, so teardown does not switch back. In the common case (main repo already on the merge target) nothing moves.

## Testing

`composer test` runs Pint, PHPStan (level 6, larastan), 100% type coverage, then Pest.

- Pure classes are unit-tested (`WorktreeTest`, `EnvFileTest`, `PhpunitConfigTest`, `DatabaseManagerTest`, `WorktreeListTest`, `DirectoryTest`, `EnumsTest`).
- `CommandsTest` runs the commands against a **real temporary git repo** created in the system temp dir, mirroring a stock Laravel app: `.env` gitignored, `phpunit.xml` tracked, and a minimal `composer.json` so `composer install` (and therefore `steps`) can run. `herd` is `none` throughout. CI configures a global git identity for this (git itself ships on the runner).
- **The server-database path runs against a real MySQL rather than a mock.** `useMysql()` points the app at `127.0.0.1:3306` as root, and those tests `->skip()` when nothing answers, so the suite still passes on a machine without one. CI provides a mysql service; locally Herd's MySQL is picked up automatically.
- The env-leak test guards the worst bug this package had. It sets `WT_LEAK_CHECK` through putenv **and** `$_ENV`/`$_SERVER`, because Symfony only forwards variables present in `$_SERVER`: using putenv alone makes the test pass whether the fix is there or not.
- Tests use `WithWorkbench` + `testbench.yaml`.

## Development notes

- The commands run real processes through the `Process` facade. Structured calls (git, herd, php artisan) use array commands; user-configured `steps` are strings run through the shell so flags like `--if-present` work.
- `larastan.noEnvCallsOutsideOfConfig` is ignored for `config/worktree.php` in `phpstan.neon.dist` (env() in a config file is correct).
- Target is PHP 8.2+, Laravel 11/12/13. Keep source 8.2-safe: no unwrapped `new Foo()->bar()` chaining, no typed class constants. Pest is constrained to `^3.8.2|^4.0.0` because Pest 4 requires PHP 8.3, and pinning it to `^4.0` alone would drag the whole package's floor up to 8.3.
- No facade, no migrations, no views. Do not add a `down()` to any migration (there are none), and follow the Mozex conventions (protected over private, guard clauses over else, explicit types).
