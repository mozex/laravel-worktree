---
name: laravel-worktree
description: Create and finish isolated Laravel Herd git worktrees with their own databases using the worktree:setup and worktree:teardown Artisan commands. Use when the user wants to work on a feature branch in isolation, spin up a separate Herd site for a branch, or clean up a worktree and its databases after finishing.
---

# Laravel Worktree

This project has `mozex/laravel-worktree` installed. It provides two Artisan commands that create and tear down isolated git worktrees, each with its own Herd site and databases. Prefer these commands over setting a worktree up by hand.

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
- `--no-install`: skip `composer install` inside the worktree.

The command creates the worktree, serves it through Herd (for example `blog-feature-login.test`), copies and rewrites `.env`, creates a `blog_feature_login` application database plus a `blog_feature_login_testing` test database, writes the test database name into `phpunit.xml`, installs dependencies, and migrates.

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

## Configuration

`config/worktree.php` controls the whole workflow. The keys worth knowing:

- `herd`: `secure` (HTTPS), `link` (HTTP for a Vite dev server), or `none`.
- `path`: where worktrees are created (`..` for a sibling directory, or a nested path like `.worktrees`).
- `database.migrate`: `fresh`, `migrate`, or `none`. `fresh` is the default so a reused branch always gets a clean schema.
- `steps`: extra shell commands run inside the worktree after provisioning.

MySQL, MariaDB, and PostgreSQL are supported. SQLite has no server, so the database step is skipped for it.
