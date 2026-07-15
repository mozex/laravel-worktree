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
- **DatabaseManager connects without a database in the DSN**, so `CREATE DATABASE` works when the target does not exist. Postgres connects to the `postgres` maintenance db, checks `pg_database`, and drops `WITH (FORCE)`. MySQL/MariaDB use `IF NOT EXISTS` / `IF EXISTS`.
- **DB names are lowercased and non-alphanumerics collapse to `_`** (`Worktree::slug()`), keeping them valid on both MySQL and Postgres without quoting headaches.
- **Host remapping uses lookbehind/lookahead** so `blog.test` is rewritten but `myblog.test` and `sub.blog.test` are not.
- **PhpunitConfig uses DOMDocument**, not regex, so a stock Laravel `phpunit.xml` with commented-out DB lines gets a real `<env>` appended rather than a comment edited.
- **Config drives everything** (herd mode, worktree path, host template, db naming, migrate mode, steps) so the package is not mozex-specific.

## Testing

`composer test` runs Pint, PHPStan (level 6, larastan), 100% type coverage, then Pest.

- Pure classes are unit-tested (`WorktreeTest`, `EnvFileTest`, `PhpunitConfigTest`, `DatabaseManagerTest`, `WorktreeListTest`, `EnumsTest`).
- `CommandsTest` runs the commands against a **real temporary git repo** created in the system temp dir. It sets `database.default` to `sqlite` (so `DatabaseManager` reports the driver unsupported and the DB step is skipped) and `herd` to `none`, which exercises the real git worktree creation plus `.env`/`phpunit.xml` rewriting without needing a database or Herd. CI installs git and configures a global identity for this.
- Tests use `WithWorkbench` + `testbench.yaml`.

## Development notes

- The commands run real processes through the `Process` facade. Structured calls (git, herd, php artisan) use array commands; user-configured `steps` are strings run through the shell so flags like `--if-present` work.
- `larastan.noEnvCallsOutsideOfConfig` is ignored for `config/worktree.php` in `phpstan.neon.dist` (env() in a config file is correct).
- Target is PHP 8.2+, Laravel 11/12/13. Keep source 8.2-safe: no unwrapped `new Foo()->bar()` chaining, no typed class constants. Pest is constrained to `^3.8.2|^4.0.0` because Pest 4 requires PHP 8.3, and pinning it to `^4.0` alone would drag the whole package's floor up to 8.3.
- No facade, no migrations, no views. Do not add a `down()` to any migration (there are none), and follow the Mozex conventions (protected over private, guard clauses over else, explicit types).
