---
name: laravel-worktree
description: Create and finish isolated Laravel Herd git worktrees with their own databases using the worktree:setup, worktree:teardown, and worktree:path Artisan commands. Use when the user wants to work on a feature branch in isolation, spin up a separate Herd site for a branch, resolve where a branch's worktree lives, or clean up a worktree and its databases after finishing.
---

# Laravel Worktree

This project has `mozex/laravel-worktree` installed. It provides three Artisan commands that create, locate, and tear down isolated git worktrees, each with its own Herd site and databases. Prefer these commands over setting a worktree up by hand.

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

## Finishing a worktree

```bash
php artisan worktree:teardown
```

With no flags it lists the worktrees and asks how to finish. Drive it directly with:

- `--pr`: commit any pending changes, push, and open a pull request with the GitHub CLI. The branch is kept for the open PR. Set the commit message with `--message="..."`.
- `--into=main`: merge the branch into `main`, then clean up.
- `--abandon --force`: discard the branch without merging.
- `--keep-database`: leave the databases in place during cleanup.

Cleanup drops the application and test databases, unsecures the Herd site, removes the worktree, and deletes the branch (except after a pull request).

## Finding a worktree

```bash
php artisan worktree:path feature/login
```

Prints the resolved directory for a branch without creating anything. Use it whenever you need to `cd` into a worktree or build a shell alias, rather than assembling the path by hand.

## Configuration

`config/worktree.php` controls the whole workflow. The keys worth knowing:

- `herd`: `secure` (HTTPS), `link` (HTTP for a Vite dev server), or `none`.
- `path`: where worktrees are created (`..` for a sibling directory, or a nested path like `.worktrees`).
- `database.migrate`: `fresh`, `migrate`, or `none`. `fresh` is the default so a reused branch always gets a clean schema.
- `steps`: extra shell commands run inside the worktree after provisioning. The default Node step is `npm ci`, not `npm install`: Laravel's `package.json` has no `name`, so `npm install` rewrites the tracked `package-lock.json` with the worktree's directory name, while `npm ci` installs from the lockfile without touching it. Switch to `npm install` only if the project has no committed lockfile, and add a `name` to `package.json` if so.

## Databases

The behaviour depends on the driver, and neither case needs configuring:

- **MySQL, MariaDB, PostgreSQL**: the server is shared, so the worktree gets its own database named after it (`blog_feature_login`) plus a `_testing` one, and teardown drops both. If `phpunit.xml` runs the suite on the same server, the test database name is written into it.
- **SQLite**: the file lives inside the worktree, so it is already isolated. Nothing is named, created on a server, or dropped; the package only makes sure the file exists. A `DB_DATABASE` holding an absolute path back into the main checkout is repointed at the worktree. A stock Laravel app, whose suite runs on in-memory SQLite, is left completely alone.

Never suggest pointing a worktree's `.env` at the main application's database or hand-editing `phpunit.xml` in a worktree: `worktree:setup` handles both, and the `phpunit.xml` rewrite is deliberately marked `skip-worktree` so it never reaches a commit.
