# Laravel Worktree

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Tests](https://img.shields.io/github/actions/workflow/status/mozex/laravel-worktree/checks.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-worktree/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-worktree/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)

Work on a feature branch without touching your main checkout. One command turns a branch into a git worktree that has its own Laravel Herd site, its own application and test databases, and a rewritten `.env`. A second command finishes the branch, whether that means opening a pull request, merging it, or throwing it away, and then drops the databases and removes the worktree. No leftover databases, no stale `.test` sites, no shared state between branches.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-worktree/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [How It Works](#how-it-works)
- [Creating a Worktree](#creating-a-worktree)
- [Finishing a Worktree](#finishing-a-worktree)
- [Configuration](#configuration)
  - [Herd Modes](#herd-modes)
  - [Databases](#databases)
  - [Host Rewriting](#host-rewriting)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Installation

> **Requires [PHP 8.3+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-worktree/v1/requirements)

Install it as a dev dependency:

```bash
composer require mozex/laravel-worktree --dev
```

Publish the config file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=laravel-worktree-config
```

That's it. The two Artisan commands are ready to use.

## How It Works

Git worktrees let you check out several branches at once, each in its own directory, all backed by one `.git` folder. That solves the code side of running two branches side by side, but it leaves the environment behind. The new directory has no `vendor`, no `node_modules`, no `.env`, and it points at the same database as your main checkout. Run migrations in one and you have changed the other.

This package fills that gap. `worktree:setup` runs from your main repository and does the rest of the work for you:

1. Creates the worktree next to your project (or wherever you configure).
2. Serves it through Herd, so `blog` on branch `feature/login` becomes `blog-feature-login.test`.
3. Copies your `.env`, then rewrites the database name and every reference to the old host.
4. Creates a fresh application database and a separate test database, and writes the test database name into `phpunit.xml`.
5. Runs `composer install`, migrates the new database, then runs your own extra steps (npm, storage link, whatever you list).

Because it all runs from the main repo, you never `cd` into a half-built directory. And because it's an Artisan command, it works the same whether you call it by hand, from a Composer script, or from a terminal shortcut.

## Creating a Worktree

Pass a branch name:

```bash
php artisan worktree:setup feature/login
```

Leave it off and a branch is generated for you (`feature/auto-260714-193000`):

```bash
php artisan worktree:setup
```

When you're done you'll see where everything landed:

```
Worktree ready.
+---------------+------------------------------------------+
| Path          | /Users/you/Sites/blog-feature-login      |
| Branch        | feature/login                            |
| URL           | https://blog-feature-login.test          |
| Database      | blog_feature_login                       |
| Test database | blog_feature_login_testing               |
+---------------+------------------------------------------+
```

A few options change what runs:

| Option | What it does |
|---|---|
| `--base=develop` | Branch off `develop` instead of the configured base branch |
| `--seed` | Seed the database after migrating |
| `--no-migrate` | Create the databases but skip migrations |
| `--no-database` | Skip databases and PHPUnit entirely |
| `--no-install` | Skip `composer install` inside the worktree |

If the branch already exists, its worktree is checked out as-is instead of branching from scratch.

## Finishing a Worktree

When the work is done, run:

```bash
php artisan worktree:teardown
```

It lists your worktrees, asks how you want to finish, and then cleans up. You can skip the questions with flags:

```bash
# Push the branch and open a pull request with the GitHub CLI
php artisan worktree:teardown feature/login --pr

# Merge the branch into main, then clean up
php artisan worktree:teardown feature/login --into=main

# Throw the branch away
php artisan worktree:teardown feature/login --abandon --force
```

`--pr` commits any pending changes first, pushes the branch, and opens the PR with `gh`. Set the commit message with `--message="..."` if you don't want the default.

Whichever path you pick, the cleanup is the same: drop the application and test databases, unsecure the Herd site, remove the worktree, and delete the branch (except after a pull request, where the branch stays for the open PR). The databases to drop are worked out from the worktree's own name, never from the copied `.env`, so teardown can't touch your main database. Pass `--keep-database` if you want the databases left alone.

## Configuration

Every part of the workflow is driven by `config/worktree.php`, so the package adapts to your stack instead of forcing one setup. Here are the parts you're most likely to touch.

### Herd Modes

The `herd` option decides how the site is served:

```php
'herd' => env('WORKTREE_HERD', 'secure'),
```

- `secure` serves the worktree over HTTPS with `herd secure` and sets `APP_URL` to `https://`.
- `link` serves it over HTTP with `herd link`, which suits a Vite dev server.
- `none` skips Herd, for when you serve the site some other way.

### Databases

Each worktree gets its own application database named after the worktree (`blog_feature_login`) and a test database with the `_testing` suffix. The name is lowercased, with anything that isn't a letter or number turned into an underscore, so it stays valid on both MySQL and PostgreSQL. Postgres is handled correctly too: databases are created against the `postgres` maintenance connection and dropped with `WITH (FORCE)`.

The `migrate` option controls what happens after creation:

```php
'migrate' => env('WORKTREE_MIGRATE', 'fresh'),
```

`fresh` runs `migrate:fresh`, which gives you a clean schema every time, even when you reuse a branch name and its old database is still lying around. Use `migrate` for a plain migration, or `none` to handle it yourself.

### Host Rewriting

When `remap_source_host` is on, every mention of the old host in the copied `.env` is repointed at the worktree. So `blog.test` becomes `blog-feature-login.test` across `APP_URL`, mail addresses, and any custom domain keys you keep. Hostnames that only happen to contain the old one, like `myblog.test` or `sub.blog.test`, are left alone.

## Resources

Visit the [documentation site](https://mozex.dev/docs/laravel-worktree/v1) for searchable docs auto-updated from this repository.

- **[AI Integration](https://mozex.dev/docs/laravel-worktree/v1/ai-integration)**: Use this package with AI coding assistants via Context7 and Laravel Boost
- **[Requirements](https://mozex.dev/docs/laravel-worktree/v1/requirements)**: PHP, Laravel, and dependency versions
- **[Changelog](https://mozex.dev/docs/laravel-worktree/v1/changelog)**: Release history with linked pull requests and diffs
- **[Contributing](https://mozex.dev/docs/laravel-worktree/v1/contributing)**: Development setup, code quality, and PR guidelines
- **[Questions & Issues](https://mozex.dev/docs/laravel-worktree/v1/questions-and-issues)**: Bug reports, feature requests, and help
- **[Security](mailto:hello@mozex.dev)**: Report vulnerabilities directly via email

## License

The MIT License (MIT). Please see the [LICENSE file](LICENSE.md) for more information.
