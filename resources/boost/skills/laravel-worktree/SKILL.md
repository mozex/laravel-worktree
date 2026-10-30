---
name: laravel-worktree
description: Create and finish isolated git worktrees for a Laravel app, each with its own databases, .env, and local site, using the worktree:setup, worktree:adopt, worktree:teardown, worktree:prune, worktree:list, worktree:path, worktree:hook and worktree:install-agent Artisan commands. Use when the user wants to work on a feature branch in isolation, run several branches or agents side by side, give a worktree an agent or editor made its own databases and site, set up Conductor, Paradev, Cursor, Codex, Zed, Devin Desktop or a git hook so the worktrees they make get provisioned, have Claude Code's worktrees (claude --worktree, subagents with isolation: worktree) provisioned through hooks, serve a branch as its own local site (Herd, Valet, or another server), start a branch with a copy of their local database, see which worktrees exist, resolve where a branch's worktree lives, clean up a worktree and its databases after finishing, or clean up after worktrees whose directory was deleted without a teardown.
---

# Laravel Worktree

This project has `mozex/laravel-worktree` installed. It provides Artisan commands that create, adopt, list, locate, tear down, and prune isolated git worktrees, plus the hook Claude Code runs to have its own worktrees made the same way, each with its own databases and a local site served by the project's site driver (Herd, Valet, configured commands, or none). Prefer these commands over setting a worktree up by hand, or over a plain `git worktree add`, which leaves the new checkout on the main app's databases.

Every command must run from the main repository. Run from inside a worktree, they fail with an error naming the main checkout; `cd` there first.

## When to use this skill

- The user wants to work on a branch without disturbing the main checkout.
- Several branches, or several agents, need to work in parallel: give each its own worktree with `worktree:setup` instead of switching branches in the main checkout.
- The user asks for a separate `.test` site or a separate database for a branch.
- A worktree another tool made (Cursor, Codex, Conductor, a plain `git worktree add`) needs its own databases and site, or the user wants every worktree such a tool makes set up that way.
- The user wants the worktrees Claude Code makes (`claude --worktree`, subagents with `isolation: worktree`) to get their own databases and site, or asks what to do with the worktrees its subagents left.
- The user is done with a branch and wants to merge or ship it and remove the worktree.

## Creating a worktree

Run this from the main repository, not from inside a worktree:

```bash
php artisan worktree:setup feature/login
```

Leaving the branch off generates one automatically. Useful options:

- `--base=develop`: branch off `develop` instead of the configured base branch.
- `--seed`: seed the database after migrating.
- `--clone`: start the worktree's databases as a copy of the main repository's instead of empty ones. `--no-clone` starts empty even when the config turns cloning on. Use `--clone` when the user wants their local data (users, records, settings) in the branch.
- `--no-migrate`: create the databases but skip migrations.
- `--no-database`: skip database creation and PHPUnit patching.
- `--no-install`: skip `composer install`. The migrations and the configured `steps` need the worktree's own vendor directory, so they are skipped too (with a warning).
- `--print-path`: send every message to stderr and print only the resolved worktree path to stdout, so a shell can `cd "$(php artisan worktree:setup ... --print-path)"` into the new worktree. This is what the Warp integration uses.

The command creates the worktree, serves it through the configured site driver (for example as `blog-feature-login.test`), copies and rewrites `.env`, creates a `blog_feature_login` application database plus a `blog_feature_login_testing` test database, writes the test database name into every configured PHPUnit file, installs dependencies, and migrates.

The branch argument is normalized before use: surrounding quotes and whitespace are stripped, so a blank shell parameter that arrives as the literal `''` auto-generates a branch instead of creating a `repo-''` worktree. A name that would push the host past 63 characters (the most a TLS certificate takes) is cut and given a short hash; the directory and database names follow it, so always get a worktree's path from `worktree:path` or `worktree:list` rather than building it from the branch.

Setup records each worktree in `.git/laravel-worktree/registry.json` (its name, site driver, and every database with its server), which teardown and `worktree:list` read back. Never edit or delete that file to clean up: run `worktree:teardown`, or `worktree:prune` for a worktree whose directory is already gone. Two setup or adopt runs on one repository at once take turns at checking the name, creating and recording the worktree, and the site driver's commands; a message that another worktree command is running means one is waiting for the other. A name that would give two worktrees the same databases (`blog-feature_login` beside `blog-feature-login`, or the main site's name) is refused.

Setup is safe to re-run. If the branch's worktree already exists (say a provisioning step failed halfway), the command resumes provisioning instead of erroring, so re-running `worktree:setup` is the right fix for an interrupted setup. Gitignored extra env files listed in `env.copy` (`.env.testing` by default) are copied in with the host rewrite and any `env.replace` rewrites applied. A stock Laravel `.gitignore` doesn't ignore `.env.testing`, so setup skips it with a warning until the project adds it to `.gitignore`.

## Adopting a worktree another tool made

```bash
php artisan worktree:adopt ../agent-worktrees/blog-3f2a --name=agent
```

Provisions an existing worktree (site, `.env`, databases, dependencies, migrations, PHPUnit files, steps) without creating it, with setup's options (`--clone`, `--seed`, `--no-migrate`, `--no-database`, `--no-install`) and `--no-secure` to serve it over http. The path must be a linked worktree `git worktree list` shows for this repository. `--name` takes the branch's place in the host template (`blog-agent.test`, `blog_agent`); without it adopt uses Conductor's workspace name when run from Conductor's setup for that worktree, else a short hash of the path. `--tool=cursor` records what made it. Re-running adopt resumes under the name it got first. A worktree `worktree:setup` made is refused with the `worktree:setup <branch>` command to run instead. When the worktree's `.env`, `vendor`, `node_modules`, SQLite file or PHPUnit config is a link or hard link to something outside it (some tools link them to the main checkout), adopt removes the link, never its target, and gives the worktree its own; `--no-install` and `--no-database` leave the links for what they skip.

From a tool's setup script, which runs inside the new worktree before it has a `vendor` directory, use the committed shim: `php artisan vendor:publish --tag=worktree-adopt` writes `worktree-adopt.php` to the project root, and `php worktree-adopt.php [options]` runs `worktree:adopt` for its worktree from the main checkout. `php artisan worktree:install-agent <tool>` sets up a tool so every worktree it makes runs the shim (it publishes `worktree-adopt.php` when missing, and the shim must be committed and pushed on the branch the tool's worktrees start from). Run it only when the user asks for it. `conductor` writes `.conductor/settings.local.toml`, which Conductor runs in place of the repository's own setup script; `paradev` (formerly Superset) adds the command to the `after` list of `setup` in `.superset/config.local.json`; `cursor`, `codex`, `zed` and `devin` (Devin Desktop, formerly Windsurf) only print what to add to the file the team shares (`.cursor/worktrees.json`, `.zed/tasks.json`, `.devin/hooks.json` or `.windsurf/hooks.json`) or to the ChatGPT desktop app's Local environments setting, so add it there only with the user's say-so. The command must run after anything in the tool's setup that copies `.env`, or the copy points the worktree back at the main database. The worktrees are served over http unless the installer gets `--secure`; setup's options pass on to adopt. `worktree:install-agent git` instead writes a `post-checkout` hook into `.git/hooks` that adopts every worktree `git worktree add` makes (not the ones `worktree:setup` or Claude Code's hook make, nor any made with `LARAVEL_WORKTREE_SKIP_ADOPT=1` set); a failed adopt never fails the add, and `worktree:adopt <path>` finishes it. Cursor and Codex turn git's hooks off; Zed (in a trusted project) and Paradev run them, so with the git hook installed their own setup would adopt each worktree twice. None of these tools runs teardown when it deletes a worktree: `worktree:prune` cleans up after them.

An adopted worktree is finished with `worktree:teardown <name>` like any other, which also removes the tool's worktree directory and deletes its branch (except with `--pr`). A name that matches two worktrees is refused; pass the path instead. Teardown of a tool's worktree that was never adopted leaves its site and databases alone, with a warning.

## Claude Code's worktrees

`php artisan worktree:install-agent claude` writes Claude Code's WorktreeCreate and WorktreeRemove hooks into `.claude/settings.local.json` (never the shared `.claude/settings.json`). Run it only when the user asks for it: it changes how Claude makes every worktree in the project. After that, `claude --worktree`, `EnterWorktree`, background sessions and subagents with `isolation: worktree` run `worktree:hook claude create`, which makes a worktree on branch `worktree-<name>` with its own databases, `.env`, dependencies and site, served over http unless the installer got `--secure`, which leaves HTTPS to `site.secure` as setup does. The installer passes `--base`, `--clone`, `--no-clone`, `--seed`, `--no-migrate`, `--no-database` and `--no-install` on to the hook, and `--timeout` (1800 seconds by default) sets Claude's limit per hook. Rerunning it replaces the hooks it wrote; it refuses when either settings file has another WorktreeCreate or WorktreeRemove hook. It names PHP and `artisan` by full path and writes the base branch in, so rerun it after the PHP it ran on is removed, the project moves, or `base_branch` changes. With the hook in place Claude no longer reads `.worktreeinclude`; gitignored env files belong in `env.copy`.

Removing a session's worktree runs `worktree:hook claude remove`, which finishes it like `worktree:teardown --abandon --keep-branch`, refusing one with uncommitted or untracked changes or commits no branch holds. Claude never removes a subagent's worktree: `worktree:list` shows them with `claude` in the Tool column, and each is finished with `worktree:teardown worktree-<name>` (`--into`, `--pr` or `--abandon`) once its work is merged or no longer needed. Don't run `worktree:hook` by hand except to test it, with the JSON piped in (`echo '{"name":"try-it"}' | php artisan worktree:hook claude create`); from a terminal with no input it stops with an error.

## Listing worktrees

```bash
php artisan worktree:list
```

Prints each worktree's branch, path, what made it (`setup`, `adopt` or the adopting tool, `claude` for Claude Code's hook, `-` with no record), and URL (`-` when the site driver serves nothing), plus its database names (as setup recorded them, for a worktree it recorded) when the package named them (server databases, and SQLite files placed next to a main database outside the repository). Use it to see what exists before a teardown, or to find a URL.

## Finishing a worktree

```bash
php artisan worktree:teardown
```

With no flags it lists the worktrees and asks how to finish. Name one by its branch, its directory, or its adopted name, and drive it directly with:

- `--pr`: commit any pending changes, push, and open a pull request with the GitHub CLI. The branch is kept for the open PR. Set the commit message with `--message="..."`.
- `--into=main`: merge the branch into `main`, then clean up.
- `--abandon --force`: discard the branch without merging.
- `--keep-database`: leave the databases in place during cleanup.
- `--keep-branch`: keep the branch, whichever way the worktree is finished.

Cleanup drops the application and test databases (and the `{name}_test_{token}` databases parallel test runs derived from them, unless another worktree has that name as its own database), stops serving the site, removes the worktree, and deletes the branch (except after a pull request). It goes by what setup recorded: the driver that served the site and the databases it created, even if the config changed since. A recorded database whose connection now points at another server is left, with a warning naming it. A detached worktree has no branch to push or merge, so `--pr` and `--into` refuse it; use `--abandon`, which still drops its recorded databases.

Teardown refuses to drop any database that matches one of the main repository's (ignoring case). If git unregisters the worktree but can't delete every file (a file another program holds open, a Windows path over 260 characters), teardown deletes the rest and finishes. A worktree git refuses to remove because it is locked, or has changes without `--force`, still stops teardown; don't delete such a directory by hand to get past it.

Each site driver command may take `site.timeout` seconds (60 by default). If setup or teardown warns that a command did not finish, the server is waiting on something the user has to answer: a dialog or an elevation prompt in the Herd app, a Valet password prompt (`valet trust` once on macOS avoids it), or an elevation prompt on Windows. Ask the user to check it. The driver's remaining commands are skipped, and each is named in a warning so it can be run by hand once the server responds. A warning that Herd "does not list" the new site means `herd link` reported success without linking it; the warning names the commands to run once the Herd app responds.

## Cleaning up after deleted worktrees

```bash
php artisan worktree:prune
php artisan worktree:prune --force
```

When a worktree's directory was deleted without a teardown (agent apps and editors delete their own worktrees), its site and databases stay behind. `worktree:prune` lists the recorded worktrees whose directory is gone and changes nothing; `--force` stops each site through the driver that served it, drops the databases its record names (only on the server each was created on, never a main database), forgets the record and runs `git worktree prune`. It drops nothing the record doesn't name, so a gone worktree's parallel-test databases stay for `--orphans`, and a worktree keeps its record when one of its databases is in use by the main app or another worktree now, when a guard refuses, or when the database server can't be reached. Always run it without `--force` first and check the list: a missing directory may be a moved worktree or an unmounted drive. A worktree folder moved or renamed by hand needs `git worktree repair <new path>` before pruning, or `git worktree prune` cuts it off from the repository. Prune skips a gone worktree that another listed worktree has taken over (same branch, same name, or a database it records or names), moving the record to it only when that is a single unrecorded worktree whose `.env` or PHPUnit config names the gone one's databases, and skips one git has locked. Adopt refuses a worktree that uses a gone worktree's databases (one moved without its record following) and says to run `worktree:prune --force` first. Setup and adopt print how many recorded worktrees are gone; that is a reminder, not something to act on without the user.

`php artisan worktree:prune --orphans` lists databases named like this repository's worktrees that no record, listed worktree or main database (the main `.env`, PHPUnit config, every `env.copy` file, or any connection in `config/database.php`) accounts for. With `--force` it also prunes the gone worktrees, then asks about each database by name, defaulting to no, and drops none of them under `--no-interaction`. Never answer those questions for the user: another project on the server may use a matching name.

## Finding a worktree

```bash
php artisan worktree:path feature/login
```

Prints the directory for a branch, or for an adopted worktree's name, without creating anything: where git has the branch checked out, else the path setup would give it. Use it whenever you need to `cd` into a worktree or build a shell alias, rather than assembling the path by hand.

## Configuration

`config/worktree.php` controls the whole workflow. If the project doesn't have one yet, `php artisan worktree:install` publishes it. The keys worth knowing:

- `site.driver` (`WORKTREE_DRIVER`): how each worktree is served. `auto` (the default) picks Herd when the `herd` command is installed, else Valet, else `none`. The others are `herd`, `valet` (also valet-linux and valet-windows), `command` (the `serve`, `secure`, `check`, `unsecure`, and `unserve` commands under `site.drivers.command`), `none`, or the name of a class implementing `Mozex\Worktree\Contracts\SiteDriver`. `site.secure` (`WORKTREE_SECURE`) turns HTTPS on; left empty, only Herd secures. A driver chosen by name whose command isn't installed serves nothing, and setup warns. An older config's `herd` key (`secure`, `link`, or `none`) still decides while `site.driver` is empty. Under `none`, `APP_URL` still points at `http://{host}`. `env.app_url_key => null` skips that rewrite, though the host rewrite (`host.remap_source_host`) still swaps the main site's host for the worktree's. When Lerd is installed, setup also warns if it serves the repository, because Lerd provisions every new worktree of its sites by itself.
- `path`: where worktrees are created (`..` for a sibling directory, or a nested path like `.worktrees`).
- `env.replace`: per-worktree rewrites for env keys the package doesn't handle on its own, like a Redis or cache prefix. Each entry is `KEY => template`. The template expands the worktree tokens (`{repo}`, `{branch}`, `{name}`, `{slug}`, `{host}`, `{tld}`) plus `{value}`, which holds the key's current value in the file; a key that isn't in the file yet is added. A stock Laravel `.env` leaves `REDIS_PREFIX` and `CACHE_PREFIX` out (config builds them from `APP_NAME`), so `{value}` is empty for them and the whole value must be written: `'REDIS_PREFIX' => '{slug}-database-'`, `'CACHE_PREFIX' => '{slug}-cache-'`. Use `{value}` only for a key the `.env` sets (`REDIS_PREFIX=shop_` with `'{value}{slug}_'` gives `shop_blog_feature_login_`). These apply to the copied `.env` and to every file in `env.copy`.
- `database.connections`: the connections to isolate per worktree. Each entry names the Laravel connection (`null` is the app default), the `.env` key holding its database name, a `name` template, and an optional `test` block (`env` for the phpunit key, `name` for the test database). A stock single-connection app needs no change here. Add an entry per extra connection, each with a distinct `name`. The env key has to be named explicitly because Laravel resolves `env()` at boot, so the package can't infer which variable a connection's database came from.
- `database.migrate`: `fresh`, `migrate`, or `none`. `fresh` is the default so a reused branch always gets a clean schema. Only the default connection is migrated; a second connection is migrated by your own migrations pinning their connection. On a cloned database `fresh` runs `migrate` instead, so the copied data survives.
- `database.clone`: `enabled` (`WORKTREE_CLONE`, off by default) copies each connection's main database into the worktree's on setup, then migrates forward so only the branch's new migrations run. `structure_only` lists tables (with `*` wildcards) copied without rows; the default covers the queue (`jobs`, `job_batches`, `failed_jobs`, so a worktree worker never re-runs the main app's pending jobs), `cache`, `cache_locks`, `sessions`, `telescope_*`, and `pulse_*`. Names also match without the connection's table prefix; renamed queue tables must be added. Setting the list replaces the default. Every connection in `database.connections` is cloned together. Test databases are never cloned, `database.seed` is skipped on a clone (only `--seed` seeds one), and re-running setup re-clones.
- `database.phpunit_files`: the PHPUnit configs patched with the worktree's test database names, defaulting to `['phpunit.xml', 'phpunit.xml.dist']`. Every listed file that exists is patched, and each is read for the connection its own suite runs on. A project that runs a second suite from a second config (browser tests, say) lists it here, or that suite keeps the main checkout's test database name and runs against the main checkout's database from inside the worktree. Leave out configs that only run on CI: their database names belong to the runner.
- `dependencies`: the directories provisioned before the steps. Each entry (`vendor`, `node_modules`) installs with its `install` command, unless `copy` is on and the worktree's lock file matches the main repository's, in which case the directory is copied from the main repository and the install is skipped. Copying is much faster than installing but only correct when the lock is unchanged, so a branch that bumped a dependency installs fresh. Off by default (`WORKTREE_COPY_VENDOR`, `WORKTREE_COPY_NODE_MODULES`). An entry whose manifest is missing (no `package.json`) is skipped, so no npm runs. The entries belong to the user: deleting one from the published config (say `node_modules`) removes it, and an added entry is provisioned the same way.
- `steps`: extra shell commands run inside the worktree after its dependencies are provisioned; the defaults build assets and link storage. Note `npm ci` installs `node_modules` in `dependencies`, not here. It's `npm ci` not `npm install` because Laravel's `package.json` has no `name`, so `npm install` would rewrite the tracked `package-lock.json` with the worktree's directory name; switch to `npm install` only if the project has no committed lockfile.

## Databases

Each worktree gets its own database on every connection in `database.connections`. A stock single-connection app needs no config; a second connection is one more entry. The behaviour depends on the driver:

- **MySQL, MariaDB, PostgreSQL**: the server is shared, so the worktree gets its own database named after each connection (`blog_feature_login`), plus a test database when the entry has a `test` block, and teardown drops them all. For the default connection the test database lands on whatever connection the PHPUnit file runs the suite on (even if it differs from the app's), read per file; a named connection keeps its name. Names longer than the server's identifier limit are truncated with a short hash automatically.
- **SQLite**: the file lives inside the worktree, so it is already isolated. Nothing is named, created on a server, or dropped; the package only makes sure the file exists. A `DB_DATABASE` holding an absolute path back into the main checkout is repointed at the worktree. A stock Laravel app, whose suite runs on in-memory SQLite, is left completely alone. A SQLite file kept outside the repository (absolute, or relative with `../`) is treated like a server: the worktree gets its own file next to it, named by the connection's `name` template (`/path/blog_feature_login.sqlite`), and teardown deletes it, so the main file is never shared or wiped.

With cloning on, MySQL and MariaDB copy server side (no `mysqldump` needed), PostgreSQL copies the main database as a template and falls back to `pg_dump`/`pg_restore` when other sessions (a queue worker, a database GUI) are connected to it, and SQLite copies the file with `VACUUM INTO`. If a Postgres clone fails because those tools are missing, the fix is to close the other connections to the main database or install the PostgreSQL client tools; never terminate the main database's sessions. Setup refuses any worktree database name (application or test) that matches the main database before it creates anything.

Never suggest pointing a worktree's `.env` at the main application's database or hand-editing a PHPUnit config in a worktree: `worktree:setup` handles both, and each rewrite is deliberately marked `skip-worktree` so it never reaches a commit. That bit is also why `git merge` inside a worktree can abort on a PHPUnit config while `git status` shows nothing; `git ls-files -v` prints `S` for those files.
