[![Laravel Worktree](https://raw.githubusercontent.com/mozex/laravel-worktree/main/art/banner.png)](https://mozex.dev/docs/laravel-worktree)

# Laravel Worktree

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Tests](https://img.shields.io/github/actions/workflow/status/mozex/laravel-worktree/checks.yml?branch=main&label=tests&style=flat-square)](https://github.com/mozex/laravel-worktree/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-worktree/v1)
[![License](https://img.shields.io/packagist/l/mozex/laravel-worktree?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)

Work on a feature branch without touching your main checkout. One command turns a branch into a git worktree that has its own Laravel Herd site, its own application and test databases (empty, or cloned from your local data), and a rewritten `.env`. A second command finishes the branch, whether that means opening a pull request, merging it, or throwing it away, and then drops the databases and removes the worktree. No leftover databases, no stale `.test` sites, no shared state between branches.

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
  - [Multiple Connections](#multiple-connections)
  - [Cloning Your Data](#cloning-your-data)
  - [Host Rewriting](#host-rewriting)
  - [Extra Env Files](#extra-env-files)
  - [Environment Replacements](#environment-replacements)
  - [Copying Dependencies](#copying-dependencies)
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

Then run the install command. It publishes `config/worktree.php`, which is where you'll set the Herd mode, where worktrees live, how databases are named, and the steps that run after setup:

```bash
php artisan worktree:install
```

If `config/worktree.php` already exists, the command leaves it as it is. Once that's done, the setup, teardown, list, and path commands are ready to use.

## How It Works

Git worktrees let you check out several branches at once, each in its own directory, all backed by one `.git` folder. That solves the code side of running two branches side by side, but it leaves the environment behind. The new directory has no `vendor`, no `node_modules`, no `.env`, and it points at the same database as your main checkout. Run migrations in one and you've changed the other.

This package fills that gap. `worktree:setup` runs from your main repository and does the rest of the work for you:

1. Creates the worktree next to your project (or wherever you configure).
2. Serves it through Herd, so `blog` on branch `feature/login` becomes `blog-feature-login.test`.
3. Copies your `.env` (plus any extra env files you configure), then rewrites the database name and every reference to the old host.
4. Creates an application database, empty or cloned from your main one, plus a separate test database, and writes the test database name into every PHPUnit config you run a suite with.
5. Installs dependencies (or copies them from your main checkout when the lock matches), migrates the new database, then runs your own extra steps (build, storage link, whatever you list).

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
| `--clone` | Start from a copy of your main database instead of an empty one (see [Cloning Your Data](#cloning-your-data)) |
| `--no-clone` | Start from an empty database even when cloning is turned on in the config |
| `--no-migrate` | Create the databases but skip migrations |
| `--no-database` | Skip databases and PHPUnit entirely |
| `--no-install` | Skip installing or copying dependencies, plus the migrations and steps that need them |
| `--print-path` | Send all output to stderr except the final worktree path, for shell integration |

If the branch already exists, its worktree is checked out as-is instead of branching from scratch.

Setup is also safe to run twice. If the worktree for that branch is already there, say because a dependency install died halfway through the first run, the command picks it back up and finishes provisioning instead of demanding a teardown. A directory that belongs to some other branch is still refused.

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

Whichever path you pick, the cleanup is the same: drop the application and test databases, remove the Herd site, remove the worktree, and delete the branch (except after a pull request, where the branch stays for the open PR). The databases to drop are worked out from the worktree's own name rather than the copied `.env`, and teardown refuses outright to drop one matching any of your main repository's databases, ignoring case, since MySQL on Windows and macOS does too. Pass `--keep-database` if you want them left alone.

Sometimes git can't delete every file in a worktree, because another program holds one open or a path runs past Windows' 260-character limit. Git has already unregistered the worktree by then, so teardown deletes the leftovers itself and carries on, rather than stopping with the databases and branch still around. Teardown also passes git `core.longpaths`, so a deep `vendor` tree on Windows doesn't cause that failure in the first place. A worktree git refuses to remove on purpose, because it's locked or has changes you didn't `--force`, still stops the teardown.

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

The Database column only appears when a connection has a database the package named: a server database (MySQL, MariaDB, or PostgreSQL), or the SQLite file it places next to a main database kept outside the repository. A SQLite file inside the worktree belongs to the worktree's own `.env`, so there's no single name worth printing.

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

Herd's CLI hands most of its work to the Herd app and waits for an answer with no time limit, so while the app is stuck on a dialog (or, on Windows, an elevation prompt) a command like `herd link` can wait forever. Each Herd command gets a minute. One that runs out of time is stopped, setup warns you to check the Herd app, and the rest of the setup carries on. Run `herd link` in the worktree yourself once Herd responds. The same CLI can also report success when the app never did the work, so after linking, setup checks `herd links` and warns if the new site isn't listed.

The site name doubles as the host, and a host has to fit in a TLS certificate, which holds 64 characters at most. A long repository name plus a long branch would break `herd secure`, so the worktree name is capped to keep the whole host (`.test` included) within 63 characters. A longer name is cut and given a short hash, so two long branches never share a name. The directory and database names derive from the same capped name. A worktree an older version created under its full name keeps that name: every command recognizes it by its directory, so it still resumes, lists, and tears down with the databases it really has.

If you do keep worktrees in a nested path, add that directory to your `.gitignore`. The worktrees would otherwise show up as untracked files in the main repository's `git status`.

### Databases

Each worktree gets its own database on every connection you list under `database.connections`, so two branches never share data. What that means depends on the driver, because the isolation problem is different for each.

**MySQL, MariaDB, and PostgreSQL** put every worktree on one shared server, so each worktree gets a database of its own, named after it (`blog_feature_login`). The name is lowercased, with anything that isn't a letter or number turned into an underscore, so it stays valid everywhere. A name that would blow past the server's identifier limit (64 characters on MySQL, 63 on Postgres) gets cut and given a short hash, so a long repo plus a long branch can't produce a name the server rejects. Postgres works too: databases are created against the `postgres` maintenance connection and dropped `WITH (FORCE)`, which needs PostgreSQL 13 or newer. Teardown drops every database it made, plus the `{name}_test_{token}` databases parallel test runs derived from them (paratest worker databases, [mozex/laravel-test-lanes](https://github.com/mozex/laravel-test-lanes) lanes), so they never outlive the worktree.

The server is reached through the connection's `host`, `port`, `username`, and `password` values. A connection configured through a single `DB_URL` or a `unix_socket` isn't parsed, so give the connection explicit host values if you use one of those.

**SQLite** usually needs none of that. The database is a file inside your project, so the worktree already has its own copy and nothing has to be named, created on a server, or dropped afterwards. The package makes sure the file exists so migrations can run, and leaves it alone otherwise. When `DB_DATABASE` holds an absolute path back into the main checkout, it gets repointed at the worktree so the two don't share a file.

A SQLite file kept outside the repository (`DB_DATABASE=/Users/you/databases/blog.sqlite`, or a relative path that climbs out, like `../databases/blog.sqlite`) would be shared by every worktree, and the first `migrate:fresh` in one of them would wipe your main data. So it's treated like a server: the worktree gets a file of its own next to the main one, named by the connection's `name` template (`/Users/you/databases/blog_feature_login.sqlite`), and teardown deletes it. Setup refuses a template that would name the main file itself.

A stock app never touches this config. The shipped entry covers the default connection, and `null` means "whatever `DB_CONNECTION` resolves to," so it works whether you develop on SQLite, MySQL, or Postgres:

```php
'database' => [
    'connections' => [
        [
            'connection' => null,        // null is the app's default connection
            'env' => 'DB_DATABASE',      // the .env key holding this database's name
            'name' => '{slug}',          // the worktree database name
            'test' => [
                'env' => 'DB_DATABASE',  // the phpunit.xml <env> key to rewrite
                'name' => '{slug}_testing',
            ],
        ],
    ],
],
```

The `{slug}` token is the worktree name, lowercased with each run of non-alphanumerics collapsed to one underscore. The `{repo}`, `{branch}`, `{name}`, `{host}`, and `{tld}` tokens work here too.

The `database.migrate` option controls what happens after creation. `fresh` runs `migrate:fresh`, which gives you a clean schema every time, even when you reuse a branch name and its old database is still lying around. Use `migrate` for a plain migration, or `none` to handle it yourself. With [cloning](#cloning-your-data) on, `fresh` runs a plain `migrate` instead, so the copied data survives. Only the default connection is migrated; a second connection is migrated by your own migrations pinning their connection, or by a provisioning step.

### Test Databases

If your suite runs against a real database server, each connection with a `test` block gets a second database for tests, and its name is written into `phpunit.xml`, so running tests in a worktree can never touch your development data:

```xml
<env name="DB_DATABASE" value="blog_feature_login_testing"/>
```

For the default connection, the package reads `phpunit.xml` to work out which connection your tests run on and creates the test database there. So a project that develops on SQLite but tests against MySQL gets its test database on MySQL, and teardown drops it from the same place. A stock Laravel app pins its suite to an in-memory SQLite database, which is already isolated, so nothing is created and nothing is rewritten. A named connection keeps its own name in tests. Leave the `test` block off a connection to skip a test database there.

The rewrite is marked `skip-worktree` in the worktree's own git index, so the change never shows up in `git status` and never lands in a commit. Your `phpunit.xml` is a tracked file, and without that the worktree would look permanently dirty.

Running a second suite from a second config? List it. `database.phpunit_files` defaults to `['phpunit.xml', 'phpunit.xml.dist']`, and every listed file that exists gets patched, each one read for the connection its own suite runs on:

```php
'phpunit_files' => ['phpunit.xml', 'phpunit.browser.xml'],
```

A file you leave out keeps whatever test database name your main checkout put there, and that's the database its suite runs against from inside the worktree. Nothing errors, nothing looks wrong, and one of your suites is quietly sharing a database with the main repo. Leave out the configs that only run on CI, though: those name a database on the CI runner, not on your machine.

### Multiple Connections

Some apps talk to more than one database: a main connection plus an analytics or reporting one, say. List each connection you want isolated, and every worktree gets its own database on all of them, kept apart in development and in tests.

You have to name the env key yourself, and there's a reason the package can't guess it. Laravel resolves `env()` at boot, so the connection config it hands back holds the database *value*, not the variable it came from, and there's no way back. So each entry spells it out:

```php
'database' => [
    'connections' => [
        [
            'connection' => null,
            'env' => 'DB_DATABASE',
            'name' => '{slug}',
            'test' => ['env' => 'DB_DATABASE', 'name' => '{slug}_testing'],
        ],
        [
            'connection' => 'analytics',           // the name from config/database.php
            'env' => 'ANALYTICS_DB_DATABASE',      // this connection's .env key
            'name' => '{slug}_analytics',
            'test' => ['env' => 'ANALYTICS_DB_DATABASE', 'name' => '{slug}_analytics_testing'],
        ],
    ],
],
```

Setup creates each database, rewrites each env key in the worktree's `.env`, and writes each test database into every configured PHPUnit file. Both commands guard your real data the same way. If any worktree name, application or test, matches the main database of any listed connection (ignoring case, as MySQL on Windows and macOS does), setup stops before it creates anything and teardown refuses to drop it, so a bad template can't take out your main databases. Give each connection a distinct `name`. If two would land on the same server with the same name, setup stops before touching anything.

### Cloning Your Data

A new worktree database starts empty: migrated, maybe seeded, but without the users, orders, and settings you've built up locally. When you'd rather start the branch from that data, clone it:

```bash
php artisan worktree:setup feature/login --clone
```

Or turn it on for every worktree:

```php
'database' => [
    'clone' => [
        'enabled' => true, // or WORKTREE_CLONE=true in .env
    ],
],
```

Setup copies each connection's database from your main checkout into the worktree's, then runs `migrate` instead of `migrate:fresh`. The copy survives, and only the branch's own new migrations run on top of it. When the config has cloning on, `--no-clone` turns it off for a single run.

How the copy is made depends on the driver:

- **MySQL and MariaDB** copy on the server, table by table, with `INSERT ... SELECT` between the two databases. No rows pass through PHP and you don't need `mysqldump`. Foreign keys, generated columns, views, triggers, and stored routines come along, and auto-increment counters carry on where your main database left off.
- **PostgreSQL** creates the worktree database with your main one as its template, which copies the files directly. Postgres refuses a template copy while anything else is connected to the source, and a running queue worker or an open database GUI is enough for that. In that case setup falls back to `pg_dump` and `pg_restore`, which need to be on your PATH. It never disconnects anyone from your main database to make the template copy work.
- **SQLite** writes a snapshot of the file with `VACUUM INTO`, so rows still sitting in a WAL file make it into the copy.

Some tables shouldn't bring their rows along. The queue's pending jobs matter most: a worker running in the worktree would process them a second time, and a job can send real email. Tables listed under `structure_only` are created with their schema and no rows:

```php
'clone' => [
    'structure_only' => [
        'jobs', 'job_batches', 'failed_jobs',
        'cache', 'cache_locks', 'sessions',
        'telescope_*', 'pulse_*',
    ],
],
```

That's the default list. Names accept `*` wildcards and match with or without the connection's table prefix. If you renamed the queue tables, add the new names. Setting your own list replaces the default rather than adding to it, so keep the entries you still want. It's also the place for a huge table whose rows the branch doesn't need, like an event log.

A few things to know:

- Test databases are never cloned. They start empty, the way your suite expects them.
- Running setup again on the same branch clones again, replacing the worktree's data with a fresh copy, the same way `migrate:fresh` would reset it.
- The `database.seed` setting doesn't apply to a cloned database, because seeding on top of real data duplicates rows. Pass `--seed` if you want it anyway.
- Every connection in `database.connections` is cloned. They go together because Laravel records every migration, including those pinned to a second connection, in the default connection's `migrations` table.
- If your main database doesn't exist yet, setup warns and starts the worktree from an empty one.
- On MySQL, rows are read without locking them, so your main app keeps writing while a clone runs. The copy also stays out of the binary log when your account may turn it off. A server that logs in `STATEMENT` format and won't let you do that refuses lock-free reads, so there the copy reads the usual locking way instead.
- A table that keeps its rows may have a foreign key into a `structure_only` table. MySQL and SQLite copy it anyway, leaving those keys pointing at rows that weren't copied. Postgres won't empty such a table with `TRUNCATE`, so its rows are deleted with foreign keys unenforced, which needs a Postgres superuser. Without one, setup says so.

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

Each listed file is copied from the main repository into the worktree when it exists, with the host rewrite and your [environment replacements](#environment-replacements) applied and nothing else changed. Files that git already placed (tracked ones) are left alone. And a file that exists but isn't gitignored is skipped with a warning, because the copy would sit in the worktree as an untracked file, block a merge teardown, and ride into a `--pr` commit.

A stock Laravel `.gitignore` covers `.env` but not `.env.testing`, so add `.env.testing` to your `.gitignore` if you keep one. Until you do, setup skips it with that warning.

### Environment Replacements

The database name and the host are rewritten for you, but some values need to be worktree-specific in ways this package can't know about in advance. A Redis key prefix, a cache prefix, a queue name: leave them shared and two worktrees end up writing over each other. The `env.replace` option rewrites any env key you name, without the package hardcoding a handler for each one:

```php
'env' => [
    'replace' => [
        'REDIS_PREFIX' => '{slug}-database-',
        'CACHE_PREFIX' => '{slug}-cache-',
    ],
],
```

Each entry is a key and a template. The template is expanded with the same worktree tokens used everywhere else, `{repo}`, `{branch}`, `{name}`, `{slug}`, `{host}`, and `{tld}`, plus one more: `{value}`, which stands for the key's current value in the copied file. A key that isn't in the file yet is added.

Write the whole value unless your `.env` sets the key. A stock Laravel app leaves `REDIS_PREFIX` and `CACHE_PREFIX` out of `.env` and builds them in config from `APP_NAME` (`laravel-database-`, `laravel-cache-`), so for those keys `{value}` is empty, and that's why the example above spells the prefixes out. Use `{value}` for a key your `.env` does set: with `REDIS_PREFIX=shop_` in `.env`, `{value}{slug}_` gives `shop_blog_feature_login_`.

The rewrites run on the copied `.env` and on every file in `env.copy`, so a `.env.testing` is isolated the same way. The keys the package already manages, `DB_DATABASE` and `APP_URL` along with the host remap, stay separate and aren't configured here.

### Copying Dependencies

Every worktree installs the same `vendor` and `node_modules` your main checkout already has. When a branch doesn't touch its dependencies, that install is wasted time. Turn on copying and the package copies the directory from the main repository instead, but only when it's safe:

```php
'dependencies' => [
    'vendor'       => ['copy' => true, /* ... */],
    'node_modules' => ['copy' => true, /* ... */],
],
```

Safe means the worktree's lock file is byte-for-byte the main repository's. If the branch changed `composer.lock` or `package-lock.json`, the copy would be stale, so the package installs from scratch instead. You get the speed on the common path and the correct install on the branch that bumped a package, with nothing to remember.

The win is real. In a benchmark on a mid-sized app (`vendor` 135 MB, `node_modules` 108 MB), a warm `robocopy` beat `composer install` 5.7s to 24.8s and `npm ci` 2.4s to 7.3s, so dependencies that took half a minute to install copied in under ten seconds. A copied `vendor` boots and a copied `node_modules` builds without a hitch, because both are portable within one machine.

Copying is off by default. Flip it with `WORKTREE_COPY_VENDOR` and `WORKTREE_COPY_NODE_MODULES`, or per entry. An entry whose manifest is missing from the worktree is skipped, so an app with no `package.json` never runs npm.

The entries are yours to change. Delete `node_modules` from your published config and npm never runs; add an entry of your own (a `bower_components`, say) and it's provisioned the same way. An entry you keep still picks up any option a later version of the package adds to it.

### Provisioning Steps

Before the steps run, the worktree provisions the dependencies above. Then it runs the commands in `steps`, which by default build assets and link storage:

```php
'steps' => [
    'npm run build --if-present',
    'php artisan storage:link',
],
```

The `npm ci` that installs `node_modules` lives in the `dependencies` block, not here, so copying can skip it. It's `npm ci` rather than `npm install` on purpose. Laravel's `package.json` ships without a `name`, so `npm install` writes the worktree's directory name into the tracked `package-lock.json` and it looks modified. `npm ci` installs straight from the lockfile and never rewrites it. If your project has no committed lockfile, switch it to `npm install` and add a `name` to your `package.json`.

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
