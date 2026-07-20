---
name: laravel-worktree
description: Create and finish isolated Laravel Herd git worktrees with their own databases using the worktree:setup, worktree:teardown, worktree:list, and worktree:path Artisan commands. Use when the user wants to work on a feature branch in isolation, spin up a separate Herd site for a branch, see which worktrees exist, resolve where a branch's worktree lives, or clean up a worktree and its databases after finishing.
---

# Laravel Worktree

This project has `mozex/laravel-worktree` installed. It provides four Artisan commands that create, list, locate, and tear down isolated git worktrees, each with its own Herd site and databases. Prefer these commands over setting a worktree up by hand.

Every command must run from the main repository. Run from inside a worktree, they fail with an error naming the main checkout; `cd` there first.

## When to use this skill

- The user wants to work on a branch without disturbing the main checkout.
- The user asks for a separate `.test` site or a separate database for a branch.
- The user is done with a branch and wants to merge or ship it and remove the worktree.

## Creating a worktree

Run this from the main repository, not from inside a worktree:

```bash
php artisan worktree:setup feature/login
```

Leaving the branch off generates one automatically. Useful options:

- `--base=develop`: branch off `develop` instead of the configured base branch.
- `--seed`: seed the database after migrating.
- `--no-migrate`: create the databases but skip migrations.
- `--no-database`: skip database creation and PHPUnit patching.
- `--no-install`: skip `composer install`. The migrations and the configured `steps` need the worktree's own vendor directory, so they are skipped too (with a warning).
- `--print-path`: send every message to stderr and print only the resolved worktree path to stdout, so a shell can `cd "$(php artisan worktree:setup ... --print-path)"` into the new worktree. This is what the Warp integration uses.

The command creates the worktree, serves it through Herd (for example `blog-feature-login.test`), copies and rewrites `.env`, creates a `blog_feature_login` application database plus a `blog_feature_login_testing` test database, writes the test database name into `phpunit.xml`, installs dependencies, and migrates.

The branch argument is normalized before use: surrounding quotes and whitespace are stripped, so a blank shell parameter that arrives as the literal `''` auto-generates a branch instead of creating a `repo-''` worktree.

Setup is safe to re-run. If the branch's worktree already exists (say a provisioning step failed halfway), the command resumes provisioning instead of erroring, so re-running `worktree:setup` is the right fix for an interrupted setup. Gitignored extra env files listed in `env.copy` (`.env.testing` by default) are copied in with the host rewrite and any `env.replace` rewrites applied.

## Listing worktrees

```bash
php artisan worktree:list
```

Prints each worktree's branch, path, and URL, plus its database name when the default connection is a server. Use it to see what exists before a teardown, or to find a URL.

## Finishing a worktree

```bash
php artisan worktree:teardown
```

With no flags it lists the worktrees and asks how to finish. Drive it directly with:

- `--pr`: commit any pending changes, push, and open a pull request with the GitHub CLI. The branch is kept for the open PR. Set the commit message with `--message="..."`.
- `--into=main`: merge the branch into `main`, then clean up.
- `--abandon --force`: discard the branch without merging.
- `--keep-database`: leave the databases in place during cleanup.

Cleanup drops the application and test databases, removes the Herd site, removes the worktree, and deletes the branch (except after a pull request). A detached worktree has no branch to push or merge, so `--pr` and `--into` refuse it; use `--abandon`.

## Finding a worktree

```bash
php artisan worktree:path feature/login
```

Prints the resolved directory for a branch without creating anything. Use it whenever you need to `cd` into a worktree or build a shell alias, rather than assembling the path by hand.

## Configuration

`config/worktree.php` controls the whole workflow. The keys worth knowing:

- `herd`: `secure` (HTTPS), `link` (HTTP for a Vite dev server), or `none`.
- `path`: where worktrees are created (`..` for a sibling directory, or a nested path like `.worktrees`).
- `env.replace`: per-worktree rewrites for env keys the package doesn't handle on its own, like a Redis or cache prefix. Each entry is `KEY => template`. The template expands the worktree tokens (`{repo}`, `{branch}`, `{name}`, `{slug}`, `{host}`, `{tld}`) plus `{value}`, which holds the key's current value, so `'REDIS_PREFIX' => '{value}{slug}_'` turns `laravel_database_` into `laravel_database_blog_feature_login_`. Drop `{value}` and the value is replaced outright; a key that isn't in the file yet is added. These apply to the copied `.env` and to every file in `env.copy`.
- `database.connections`: the connections to isolate per worktree. Each entry names the Laravel connection (`null` is the app default), the `.env` key holding its database name, a `name` template, and an optional `test` block (`env` for the phpunit key, `name` for the test database). A stock single-connection app needs no change here. Add an entry per extra connection, each with a distinct `name`. The env key has to be named explicitly because Laravel resolves `env()` at boot, so the package can't infer which variable a connection's database came from.
- `database.migrate`: `fresh`, `migrate`, or `none`. `fresh` is the default so a reused branch always gets a clean schema. Only the default connection is migrated; a second connection is migrated by your own migrations pinning their connection.
- `dependencies`: the directories provisioned before the steps. Each entry (`vendor`, `node_modules`) installs with its `install` command, unless `copy` is on and the worktree's lock file matches the main repository's, in which case the directory is copied from the main repository and the install is skipped. Copying is much faster than installing but only correct when the lock is unchanged, so a branch that bumped a dependency installs fresh. Off by default (`WORKTREE_COPY_VENDOR`, `WORKTREE_COPY_NODE_MODULES`). An entry whose manifest is missing (no `package.json`) is skipped, so no npm runs.
- `steps`: extra shell commands run inside the worktree after its dependencies are provisioned; the defaults build assets and link storage. Note `npm ci` installs `node_modules` in `dependencies`, not here. It's `npm ci` not `npm install` because Laravel's `package.json` has no `name`, so `npm install` would rewrite the tracked `package-lock.json` with the worktree's directory name; switch to `npm install` only if the project has no committed lockfile.

## Databases

Each worktree gets its own database on every connection in `database.connections`. A stock single-connection app needs no config; a second connection is one more entry. The behaviour depends on the driver:

- **MySQL, MariaDB, PostgreSQL**: the server is shared, so the worktree gets its own database named after each connection (`blog_feature_login`), plus a test database when the entry has a `test` block, and teardown drops them all. For the default connection the test database lands on whatever connection `phpunit.xml` runs the suite on (even if it differs from the app's); a named connection keeps its name. Names longer than the server's identifier limit are truncated with a short hash automatically.
- **SQLite**: the file lives inside the worktree, so it is already isolated. Nothing is named, created on a server, or dropped; the package only makes sure the file exists. A `DB_DATABASE` holding an absolute path back into the main checkout is repointed at the worktree. A stock Laravel app, whose suite runs on in-memory SQLite, is left completely alone.

Never suggest pointing a worktree's `.env` at the main application's database or hand-editing `phpunit.xml` in a worktree: `worktree:setup` handles both, and the `phpunit.xml` rewrite is deliberately marked `skip-worktree` so it never reaches a commit.
