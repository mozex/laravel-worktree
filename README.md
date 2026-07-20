# Laravel Worktree

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Tests](https://img.shields.io/github/actions/workflow/status/mozex/laravel-worktree/checks.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-worktree/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-worktree/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-worktree?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)

Work on a feature branch without touching your main checkout. One command turns a branch into a git worktree that has its own Laravel Herd site, its own application and test databases, and a rewritten `.env`. A second command finishes the branch, whether that means opening a pull request, merging it, or throwing it away, and then drops the databases and removes the worktree. No leftover databases, no stale `.test` sites, no shared state between branches.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-worktree/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [How It Works](#how-it-works)
- [Creating a Worktree](#creating-a-worktree)
- [Finishing a Worktree](#finishing-a-worktree)
- [Listing Worktrees](#listing-worktrees)
- [Finding a Worktree](#finding-a-worktree)
- [Configuration](#configuration)
  - [Herd Modes](#herd-modes)
  - [Databases](#databases)
  - [Test Databases](#test-databases)
  - [Host Rewriting](#host-rewriting)
  - [Extra Env Files](#extra-env-files)
  - [Environment Replacements](#environment-replacements)
  - [Provisioning Steps](#provisioning-steps)
- [Warp Terminal](#warp-terminal)

## Support This Project

I maintain this package along with [several other open-source PHP packages](https://mozex.dev/docs) used by thousands of developers every day.

If my packages save you time or help your business, consider [**sponsoring my work on GitHub Sponsors**](https://github.com/sponsors/mozex). Your support lets me keep these packages updated, respond to issues quickly, and ship new features.

Business sponsors get logo placement in package READMEs. [**See sponsorship tiers →**](https://github.com/sponsors/mozex)

## Installation

> **Requires [PHP 8.2+](https://php.net/releases/)** - see [all version requirements](https://mozex.dev/docs/laravel-worktree/v1/requirements)

Install it as a dev dependency:

```bash
composer require mozex/laravel-worktree --dev
```

Publish the config file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=worktree-config
```

That's it. All three Artisan commands are ready to use.

## How It Works

Git worktrees let you check out several branches at once, each in its own directory, all backed by one `.git` folder. That solves the code side of running two branches side by side, but it leaves the environment behind. The new directory has no `vendor`, no `node_modules`, no `.env`, and it points at the same database as your main checkout. Run migrations in one and you've changed the other.

This package fills that gap. `worktree:setup` runs from your main repository and does the rest of the work for you:

1. Creates the worktree next to your project (or wherever you configure).
2. Serves it through Herd, so `blog` on branch `feature/login` becomes `blog-feature-login.test`.
3. Copies your `.env` (plus any extra env files you configure), then rewrites the database name and every reference to the old host.
4. Creates a fresh application database and a separate test database, and writes the test database name into `phpunit.xml`.
5. Runs `composer install`, migrates the new database, then runs your own extra steps (npm, storage link, whatever you list).

Because it all runs from the main repo, you never `cd` into a half-built directory. And because it's an Artisan command, it works the same whether you call it by hand, from a Composer script, or from a terminal shortcut.

Running from the main repository is a rule the commands enforce, not just a suggestion. Run any of them from inside a worktree and they stop with an error that points you back at the main checkout, because from there setup would derive the wrong names and teardown could pick the wrong directory to destroy.

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
| `--no-install` | Skip `composer install`, plus the migrations and steps that need it |
| `--print-path` | Send all output to stderr except the final worktree path, for shell integration |

If the branch already exists, its worktree is checked out as-is instead of branching from scratch.

Setup is also safe to run twice. If the worktree for that branch is already there, say because an `npm ci` step died halfway through the first run, the command picks it back up and finishes provisioning instead of demanding a teardown. A directory that belongs to some other branch is still refused.

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

Leave `--into` off and you'll be asked which branch to merge into. Because the merge happens in your main repository, that's the branch you'll be left on afterwards.

Whichever path you pick, the cleanup is the same: drop the application and test databases, remove the Herd site, remove the worktree, and delete the branch (except after a pull request, where the branch stays for the open PR). The databases to drop are worked out from the worktree's own name rather than the copied `.env`, and teardown refuses outright to drop one matching your main repository's `DB_DATABASE`. Pass `--keep-database` if you want them left alone.

A worktree left in detached HEAD state has no branch to push or merge, so `--pr` and `--into` refuse it with a clear message. Finish it with `--abandon`.

## Listing Worktrees

`worktree:list` shows every worktree of the repository, along with the URL and database each one was provisioned with:

```bash
php artisan worktree:list
```

```
+----------------+--------------------------------------+----------------------------------+---------------------+
| Branch         | Path                                 | URL                              | Database            |
+----------------+--------------------------------------+----------------------------------+---------------------+
| feature/login  | /Users/you/Sites/blog-feature-login  | https://blog-feature-login.test  | blog_feature_login  |
| feature/search | /Users/you/Sites/blog-feature-search | https://blog-feature-search.test | blog_feature_search |
+----------------+--------------------------------------+----------------------------------+---------------------+
```

The Database column only appears when your default connection is a server (MySQL, MariaDB, or PostgreSQL). A SQLite file belongs to each worktree's own `.env`, so there's no single name worth printing.

## Finding a Worktree

`worktree:path` prints where a branch's worktree lives. It creates nothing and touches nothing:

```bash
php artisan worktree:path feature/login
# /Users/you/Sites/blog-feature-login
```

The path is resolved from your config rather than guessed, so it stays correct even after you change `path` or the host template. That's what makes the `cd` in the Warp tab configs below land in the right place.

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

In both `secure` and `link` mode the site is linked first. Herd only serves parked and linked directories, and a worktree in a nested path such as `.worktrees` is neither: without the link, `herd secure` would happily mint a certificate for a site that never answers. Linking is harmless for worktrees that sit in a parked directory anyway, and teardown removes the link again.

If you do keep worktrees in a nested path, add that directory to your `.gitignore`. The worktrees would otherwise show up as untracked files in the main repository's `git status`.

### Databases

What happens here depends on the kind of database you use, because the isolation problem is different for each.

**MySQL, MariaDB, and PostgreSQL** put every worktree on one shared server, so each worktree gets a database of its own, named after it (`blog_feature_login`), plus a test database with the `_testing` suffix. The name is lowercased, with anything that isn't a letter or number turned into an underscore, so it stays valid everywhere. A name that would blow past the server's identifier limit (64 characters on MySQL, 63 on Postgres) gets cut and suffixed with a short hash, so a long repo plus a long branch can't produce a name the server rejects. Postgres works too: databases are created against the `postgres` maintenance connection and dropped `WITH (FORCE)`. Teardown drops both.

The server is reached through the connection's `host`, `port`, `username`, and `password` values. A connection configured through a single `DB_URL` or a `unix_socket` isn't parsed, so give the connection explicit host values if you use one of those.

**SQLite** needs none of that. The database is a file inside your project, so the worktree already has its own copy and nothing has to be named, created on a server, or dropped afterwards. The package makes sure the file exists so migrations can run, and leaves it alone otherwise. The one case it does step in is a `DB_DATABASE` holding an absolute path back into the main checkout, which gets repointed at the worktree so the two don't share a file. A database somewhere else entirely is left shared, with a warning, since that's usually deliberate.

The `database.migrate` option controls what happens after creation:

```php
'database' => [
    'migrate' => env('WORKTREE_MIGRATE', 'fresh'),
],
```

`fresh` runs `migrate:fresh`, which gives you a clean schema every time, even when you reuse a branch name and its old database is still lying around. Use `migrate` for a plain migration, or `none` to handle it yourself.

### Test Databases

If your suite runs against a real database server, the worktree gets a second one for tests and its name is written into `phpunit.xml`, so running tests in a worktree can never touch your development data:

```xml
<env name="DB_DATABASE" value="blog_feature_login_testing"/>
```

The package reads `phpunit.xml` to work out which connection your tests use, and only steps in when that connection is a server. A stock Laravel app pins its suite to an in-memory SQLite database, which is already isolated, so nothing is created and nothing is rewritten. Point your suite at MySQL or Postgres and it starts happening on its own, no configuration needed. That connection can even differ from the app's: a project that develops on SQLite but tests against MySQL gets its test database created on MySQL, and teardown drops it from the same place.

The rewrite is marked `skip-worktree` in the worktree's own git index, so the change never shows up in `git status` and never lands in a commit. Your `phpunit.xml` is a tracked file, and without that the worktree would look permanently dirty.

Set the suffix with `WORKTREE_TEST_SUFFIX` if `-testing` suits your naming better than `_testing`, or turn the whole thing off with `database.test.enabled`.

### Host Rewriting

When `host.remap_source_host` is on, every mention of the old host in the copied `.env` is repointed at the worktree. So `blog.test` becomes `blog-feature-login.test` across `APP_URL`, mail addresses, and any custom domain keys you keep. A cookie domain written with a leading dot comes along too, so `SESSION_DOMAIN=.blog.test` becomes `.blog-feature-login.test` and your worktree's sessions actually work.

Hostnames that only happen to contain the old one are left alone. `myblog.test`, `sub.blog.test`, and `blog.testing` are all different sites, and none of them get touched.

### Extra Env Files

Gitignored env files never arrive through `git worktree add`, so a project that keeps a `.env.testing` would end up with a worktree whose suite can't boot. The `env.copy` option fixes that:

```php
'env' => [
    'copy' => ['.env.testing'],
],
```

Each listed file is copied from the main repository into the worktree when it exists, with the host rewrite applied and nothing else changed. Files that git already placed (tracked ones) are left alone. And a file that exists but isn't gitignored is skipped with a warning, because the copy would sit in the worktree as an untracked file, block a merge teardown, and ride into a `--pr` commit.

### Environment Replacements

The database name and the host are rewritten for you, but some values need to be worktree-specific in ways this package can't know about in advance. A Redis key prefix, a cache prefix, a queue name: leave them shared and two worktrees end up writing over each other. The `env.replace` option rewrites any env key you name, without the package hardcoding a handler for each one:

```php
'env' => [
    'replace' => [
        'REDIS_PREFIX' => '{value}{slug}_',
        'CACHE_PREFIX' => '{slug}_cache_',
    ],
],
```

Each entry is a key and a template. The template is expanded with the same worktree tokens used everywhere else, `{repo}`, `{branch}`, `{name}`, `{slug}`, `{host}`, and `{tld}`, plus one more: `{value}`, which stands for the key's current value. That `{value}` token is what keeps you from repeating yourself. `{value}{slug}_` turns `laravel_database_` into `laravel_database_blog_feature_login_`, appending the worktree's slug without you restating the prefix in config. Drop `{value}` and the value is replaced outright, and a key that isn't in the file yet is added.

The rewrites run on the copied `.env` and on every file in `env.copy`, so a `.env.testing` is isolated the same way. The keys the package already manages, `DB_DATABASE` and `APP_URL` along with the host remap, stay separate and aren't configured here.

### Provisioning Steps

Once the environment and database are ready, the worktree runs the commands in `steps`. The defaults install dependencies, build assets, and link storage:

```php
'steps' => [
    'npm ci',
    'npm run build --if-present',
    'php artisan storage:link',
],
```

The Node step is `npm ci` rather than `npm install` on purpose. Laravel's `package.json` ships without a `name`, so `npm install` writes the worktree's directory name into `package-lock.json`. That file is tracked, so it then looks modified and would follow your work into a commit. `npm ci` installs straight from the lockfile and never rewrites it. It does need a committed lockfile, so if your project doesn't keep one, switch back to `npm install` and add a `name` to your `package.json`, which stops the rename at the source.

## Warp Terminal

If you use [Warp](https://www.warp.dev), you can drive these commands from [Tab Configs](https://docs.warp.dev/terminal/windows/tab-configs/) and turn them into one-click buttons. Each block below is one `.toml` file. Save it in Warp's tab configs directory, or paste it into the tab config editor. The `title` field names the tab, so it reads as `feature/login` rather than the command that ran.

Warp parameters are text fields or repo and branch pickers, with no dropdown to choose from, so a single config can't offer a menu of actions. Three buttons cover the flow instead: create, resume, finish.

Create a worktree and drop into it. Type a branch name, or leave it blank to auto-generate `feature/auto-<timestamp>`:

```toml
name = "Worktree Create"
title = "{{branch}}"
color = "green"

[[panes]]
id = "main"
type = "terminal"
directory = "{{repo}}"
commands = [
  '''P="$(php artisan worktree:setup {{branch}} --base={{base}} --print-path)" && cd "$P"''',
]

[params.repo]
type = "repo"
description = "Repository"

[params.base]
type = "branch"
description = "Base branch"
default = "main"

[params.branch]
type = "text"
description = "Branch name, or blank to auto-generate"
default = ""
```

The branch field carries an empty `default`, and that's what lets you submit it blank. A parameter with no default at all is treated as required. The `--print-path` flag sends everything except the final worktree path to stderr, so `$(...)` captures just the path and drops you in, generated name and all. Leave the `{{...}}` substitutions unquoted: Warp quotes them for you, and wrapping them a second time turns the value into a literal quoted string.

Resume work in an existing worktree:

```toml
name = "Worktree Resume"
title = "{{branch}}"
color = "blue"

[[panes]]
id = "main"
type = "terminal"
directory = "{{repo}}"
commands = [
  '''cd "$(php artisan worktree:path {{branch}})"''',
]

[params.repo]
type = "repo"
description = "Repository"

[params.branch]
type = "branch"
description = "Worktree branch to resume"
```

Finish a worktree with the interactive teardown:

```toml
name = "Worktree Finish"
title = "Worktree Finish"
color = "yellow"

[[panes]]
id = "main"
type = "terminal"
directory = "{{repo}}"
commands = [
  '''php artisan worktree:teardown''',
]

[params.repo]
type = "repo"
description = "Repository"
```

Create and resume read the worktree path from the package, through `--print-path` and `worktree:path`, so both tabs land in the right place even after you change `path` or the host template.

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
