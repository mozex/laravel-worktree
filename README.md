[![Laravel Worktree](https://raw.githubusercontent.com/mozex/laravel-worktree/main/art/banner.png)](https://mozex.dev/docs/laravel-worktree)

# Laravel Worktree

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![GitHub Checks Workflow Status](https://img.shields.io/github/actions/workflow/status/mozex/laravel-worktree/checks.yml?branch=main&label=checks&style=flat-square)](https://github.com/mozex/laravel-worktree/actions/workflows/checks.yml)
[![Docs](https://img.shields.io/badge/docs-mozex.dev-10B981?style=flat-square)](https://mozex.dev/docs/laravel-worktree/v1)
[![License](https://img.shields.io/github/license/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)
[![Total Downloads](https://img.shields.io/packagist/dt/mozex/laravel-worktree.svg?style=flat-square)](https://packagist.org/packages/mozex/laravel-worktree)

Work on a feature branch without touching your main checkout. One command turns a branch into a git worktree with its own `.env`, its own dependencies, a database of its own on every connection your app uses (for the app and for its tests, empty or cloned from your local data), and a local site served by Herd, Valet, a command you configure, or no server at all. A second command finishes the branch by opening a pull request, merging it, or throwing it away, then drops the databases, stops serving the site, and removes the worktree. A migration in one branch never touches another, and teardown removes what setup created.

> **[Read the full documentation at mozex.dev](https://mozex.dev/docs/laravel-worktree/v1)**: searchable docs, version requirements, detailed changelog, and more.

## Table of Contents

- [Installation](#installation)
- [How It Works](#how-it-works)
- [Creating a Worktree](#creating-a-worktree)
- [Adopting a Worktree](#adopting-a-worktree)
  - [From a Tool's Setup Script](#from-a-tools-setup-script)
- [Claude Code](#claude-code)
  - [Subagent Worktrees](#subagent-worktrees)
  - [Running the Hook by Hand](#running-the-hook-by-hand)
- [Finishing a Worktree](#finishing-a-worktree)
- [Pruning Gone Worktrees](#pruning-gone-worktrees)
  - [Databases Nothing Claims](#databases-nothing-claims)
- [Listing Worktrees](#listing-worktrees)
- [Finding a Worktree](#finding-a-worktree)
- [Configuration](#configuration)
  - [Site Drivers](#site-drivers)
    - [Herd](#herd)
    - [Valet](#valet)
    - [The `command` Driver](#the-command-driver)
    - [Writing Your Own Driver](#writing-your-own-driver)
    - [The Older `herd` Option](#the-older-herd-option)
    - [Lerd](#lerd)
    - [Long Names](#long-names)
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

Then run the install command. It publishes `config/worktree.php`, which is where you'll choose the site driver, where worktrees live, how databases are named, and the steps that run after setup:

```bash
php artisan worktree:install
```

If `config/worktree.php` already exists, the command leaves it as it is. Once that's done, every command below is ready to use.

## How It Works

Git worktrees let you check out several branches at once, each in its own directory, all backed by one `.git` folder. That solves the code side of running two branches side by side, but it leaves the environment behind. The new directory has no `vendor`, no `node_modules`, no `.env`, and it points at the same database as your main checkout. Run migrations in one and you've changed the other.

This package fills that gap. `worktree:setup` runs from your main repository and does the rest of the work for you:

1. Creates the worktree next to your project (or wherever you configure).
2. Serves it as a local site through your server's [site driver](#site-drivers), so `blog` on branch `feature/login` becomes `blog-feature-login.test`.
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

The URL row only appears when the site driver served the worktree.

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

Each worktree setup makes is recorded in `.git/laravel-worktree/registry.json`: its name, the site driver that served it, and every database it created, with the server that database lives on. The file sits inside git's own directory, so it's never committed, and every worktree of the clone shares it. Teardown and `worktree:list` read it back, as described below. If the file can't be read, the commands say so, leave it untouched, and work from your config instead.

Two setup or adopt runs on the same repository at once, say from agents working side by side, take turns at the steps that would collide: checking the name is free, creating the worktree and recording it, and running the site driver's commands. The one that has to wait says so, and gives up after ten minutes.

## Adopting a Worktree

Agent apps and editors such as Cursor, Codex and Conductor make worktrees of their own. A worktree like that gets your code and nothing else: its `.env` is missing, or it's a copy of yours, or a link to yours, all of which point it at your main database. `worktree:adopt` provisions it the way setup provisions its own worktrees, without creating it:

```bash
php artisan worktree:adopt ../agent-worktrees/blog-3f2a
```

The path has to be one that `git worktree list` shows for this repository, so your main checkout and unrelated directories are refused. Everything after that is setup's: the site, the `.env`, databases, dependencies, migrations, PHPUnit configs and your steps, with the same options (`--clone`, `--seed`, `--no-install` and the rest). Some tools link files back to your main checkout instead of copying them. When the worktree's `.env`, `vendor`, `node_modules`, SQLite database or PHPUnit config is a link (a hard link included) to something outside the worktree, adopt removes the link, never what it points to, and gives the worktree its own, since writing through it would change your main app. It only checks what the run is about to write, so `--no-install` leaves dependency links alone and `--no-database` leaves database and PHPUnit links alone.

A tool's worktree isn't named after a branch, and many tools start it on a detached HEAD, so adopt names it with `--name`, which takes the branch's place in the host template. In a repository called `blog`, `--name=agent` gives `blog-agent.test` and a `blog_agent` database. Without `--name`, adopt uses the workspace name Conductor sets when it runs from Conductor's setup script for that worktree, and otherwise a short hash of the path, such as `blog-3f2a91c0`.

A name that would give two worktrees the same databases is refused, by adopt and by setup alike: `blog-feature_login` and `blog-feature-login` both make `blog_feature_login`, and so would a name matching your main site's. Adopting a worktree a second time picks up where the first run stopped and keeps the name it got then. A worktree that `worktree:setup` made is sent back to setup, which resumes its own.

Adopt records the worktree with what made it (`--tool=cursor`, say, or `adopt` when you don't pass one). After that, `worktree:list` shows it, and `worktree:teardown blog-agent` and `worktree:path blog-agent` find it by its name. Teardown treats it like any other worktree: it drops the databases adopt created, removes the worktree directory the tool made, and deletes its branch unless you finish with `--pr`. A name that matches more than one worktree is refused; give the path instead. A tool's worktree that was never adopted has no record, so teardown leaves its site and databases alone and says so.

### From a Tool's Setup Script

Tools run their setup script inside the new worktree, which has no `vendor` directory yet, so artisan can't run there. Publish a small script into your app and commit it:

```bash
php artisan vendor:publish --tag=worktree-adopt
```

That writes `worktree-adopt.php` to your project root. It finds your main checkout through git and runs `php artisan worktree:adopt` there for the worktree it was started in, passing on any options you add. Make it the tool's setup command:

```bash
php worktree-adopt.php --clone
```

## Claude Code

Claude Code makes worktrees of its own: when you start it with `claude --worktree`, when it moves into one during a session, for a background session, and for every subagent that runs with `isolation: worktree`. A worktree Claude makes by itself gets your code and, when `.worktreeinclude` lists it, a copy of your `.env`, which still points at your main database. Install this package's hooks and Claude asks `worktree:hook` for each worktree instead:

```bash
php artisan worktree:install-agent claude
```

That writes a WorktreeCreate and a WorktreeRemove hook into `.claude/settings.local.json`, your own settings for the project. A WorktreeCreate hook replaces how Claude makes every worktree, so it belongs to each developer rather than to the shared `.claude/settings.json`, and the installer warns when the local file isn't gitignored. It refuses to run when either file already has a hook for those events: Claude runs every hook it finds, and two of them would both make the worktree. Hooks in your user settings or a plugin run as well, and `/hooks` in Claude Code lists everything that will.

Each worktree Claude asks for then gets what `worktree:setup` gives a branch: its own databases, `.env`, dependencies and site. The branch is named after the name Claude picked (`worktree-bold-oak-a3f2`), and the directory follows your `path` setting. If a step fails, the hook removes the worktree and databases that run made before it exits, since Claude never learns where a failed worktree is. When Claude comes back to a worktree it asks for it again, and the hook hands it back as it is instead of migrating or cloning over its data.

The installer takes these options and writes them into the hook:

| Option | What it does |
|---|---|
| `--base=develop` | Branch off `develop`. Without it, the configured base branch, or the branch checked out in your main repository when the configured one doesn't exist. The branch is written into the hook, so run the installer again after you change `base_branch` |
| `--secure` | Leave HTTPS to your `site.secure` setting, as `worktree:setup` does (Herd secures by default). Without it the worktrees are served over http, because securing a site makes Herd reload its server and Valet ask for a password, for every worktree an agent makes |
| `--clone`, `--no-clone`, `--seed`, `--no-migrate`, `--no-database`, `--no-install` | Passed on to every setup the hook runs, as on `worktree:setup` |
| `--timeout=1800` | Seconds Claude gives each hook before stopping it. A setup stopped partway is set up again the next time Claude asks for that worktree |

Run the installer again to change the options; it replaces the hooks it wrote. Claude starts the hooks without a shell, so they name PHP and your app's `artisan` by their full paths: run the installer again after you remove the PHP version it ran on, or move the project. To stop using the hooks, delete the two entries under `hooks` in `.claude/settings.local.json`.

With a WorktreeCreate hook in place, Claude Code no longer reads `.worktreeinclude`, so the files it lists stop reaching new worktrees, and the installer says so when you have one. Gitignored env files go under [`env.copy`](#extra-env-files) instead.

When you exit a worktree session and choose to remove the worktree, or Claude removes one you didn't name because nothing changed in it, the WorktreeRemove hook finishes it the way `worktree:teardown --abandon` does: it drops the databases, stops serving the site and deletes the directory. It keeps the branch, so whatever you committed there stays, and Claude never deletes a hook's branch either. A session you leave without committing anything therefore leaves its `worktree-...` branch behind for `git branch -d`. The hook refuses to remove a worktree with uncommitted or untracked changes, or with commits no branch holds, and only removes worktrees it made.

### Subagent Worktrees

When a subagent or workflow agent finishes, Claude Code keeps the worktree the hook made for it and never runs the WorktreeRemove hook, so each one stays, databases included. `worktree:list` shows them with `claude` in the Tool column, and teardown finishes each by its branch like any other worktree:

```bash
php artisan worktree:teardown worktree-bold-oak-a3f2 --into=main
```

### Running the Hook by Hand

`worktree:hook` reads Claude's JSON from stdin, so to try it yourself, pipe some in:

```bash
echo '{"name": "try-it"}' | php artisan worktree:hook claude create
```

Started from a terminal with nothing piped in, it stops with an error instead of waiting. A terminal or editor panel that connects stdin to a pipe it never closes makes it wait for that input to end.

## Finishing a Worktree

When the work is done, run:

```bash
php artisan worktree:teardown
```

It lists your worktrees, asks how you want to finish, and then cleans up. Name the worktree by its branch, its directory, or the name it was adopted under to skip the first question, and use flags for the rest:

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

Whichever path you pick, the cleanup is the same: drop the application and test databases, stop serving the site, remove the worktree, and delete the branch (except after a pull request, where the branch stays for the open PR). Pass `--keep-database` if you want the databases left alone, and `--keep-branch` to keep the branch whichever way you finish.

Teardown goes by what setup recorded. It stops serving the site through the driver that served it and drops the databases setup created, even if you've changed a name template or switched to another server since. A recorded database is only dropped on the server it was created on: if its connection now points somewhere else, teardown leaves it there and tells you. A worktree made by a release that kept no record is worked out from its name instead, never from the copied `.env`, as long as it still has the name setup gives its branch's worktree. One that doesn't (its branch was switched or detached, or the host template changed) keeps its site and databases, and teardown tells you, since a worktree another tool made looks the same. Either way, teardown refuses outright to drop a database matching any of your main repository's databases, ignoring case, since MySQL on Windows and macOS does too.

Sometimes git can't delete every file in a worktree, because another program holds one open or a path runs past Windows' 260-character limit. Git has already unregistered the worktree by then, so teardown deletes the leftovers itself and carries on, rather than stopping with the databases and branch still around. Teardown also passes git `core.longpaths`, so a deep `vendor` tree on Windows doesn't cause that failure in the first place. A worktree git refuses to remove on purpose, because it's locked or has changes you didn't `--force`, still stops the teardown.

A worktree left in detached HEAD state has no branch to push or merge, so `--pr` and `--into` refuse it with a clear message. Finish it with `--abandon`, which still drops the databases setup recorded for it.

## Pruning Gone Worktrees

Agent apps and editors clean up their own worktrees by deleting the directory, and people do the same by hand. Nothing runs teardown then, so the site stays linked and the databases stay on your server. `worktree:prune` finds the worktrees the package recorded whose directory is gone:

```bash
php artisan worktree:prune
```

On its own it only lists them, with the site driver that served each one and the databases it was given. A directory can also be missing because you moved it or its drive isn't mounted, so read the list before you clean up:

```bash
php artisan worktree:prune --force
```

For each one, `--force` stops the site through the driver that served it and drops the databases its record names, with the same checks teardown makes: a database is dropped only on the server it was created on, and never one matching your main databases. It also deletes the worktree's SQLite files next to a main database outside the repository, forgets the record, and runs `git worktree prune`. It drops nothing the record doesn't name, so the `blog_feature_login_test_1` databases a parallel test run left stay until you clear them with `--orphans` below. When the server that served a site isn't installed any more, prune says which site to stop by hand. A worktree keeps its record, and its databases, when one of them is in use by your main app or another worktree now (your `.env.testing` points at it, say), when a guard refuses a drop, or when its database server can't be reached, so the next run can look again.

`git worktree prune` clears git's own entry for every worktree whose directory is gone, whichever tool made it. That touches no site or database, but it does cut off a worktree folder you moved or renamed by hand: git can no longer find it. Run `git worktree repair <new path>` for such a folder before pruning, and git and prune then see it where it is.

Prune leaves a gone worktree alone when another worktree seems to have taken its place: one with its branch checked out, one under its name, or one that records or names one of its databases. That's what a worktree moved with `git worktree move` looks like. When exactly one such worktree has no record of its own and its `.env` or PHPUnit config names the gone one's databases, `--force` moves the record there, so teardown finds it later. Run prune before adopting a worktree you moved: adopt refuses one that uses a gone worktree's databases, since adopting it afresh would give it new, empty ones. A worktree git has locked (`git worktree lock`, the usual way to keep one on a removable drive) is skipped as well. Setup and adopt tell you when recorded worktrees are gone, and leave the cleanup to prune.

### Databases Nothing Claims

Some databases outlive their record. `teardown --keep-database` forgets the worktree and keeps its databases, a worktree made by a release that kept no record leaves nothing to find them by, and prune leaves a gone worktree's parallel-test databases. `--orphans` looks on each configured server for databases named the way this repository's worktrees are (`blog_` and then anything, in a repository called `blog`). It leaves out every name a record or a worktree git still lists accounts for, and every database your main app uses: those its `.env`, its PHPUnit config and every `env.copy` file (`.env.testing` by default) name, and every connection's database in `config/database.php`.

```bash
php artisan worktree:prune --orphans
php artisan worktree:prune --orphans --force
```

The first command lists them. With `--force`, prune also cleans up after gone worktrees as above, then asks about each of these databases by name and drops only the ones you say yes to. The answer defaults to no, and a run that can't ask (`--no-interaction`, or no terminal) drops none of them. Another project on the same server may use matching names, `blog_admin` say, so read each name before you answer. Databases from a name template that doesn't include the repository's name before the worktree's, such as a host template of `{branch}` alone, could belong to anything on the server, so prune doesn't look for them. Neither does it when the repository's name is long enough that worktree names get cut short.

## Listing Worktrees

`worktree:list` shows every worktree of the repository, along with the URL and database each one was provisioned with:

```bash
php artisan worktree:list
```

```
 ---------------- -------------------------------------- ------- ---------------------------------- ---------------------
  Branch           Path                                   Tool    URL                                Database
 ---------------- -------------------------------------- ------- ---------------------------------- ---------------------
  feature/login    /Users/you/Sites/blog-feature-login    setup   https://blog-feature-login.test    blog_feature_login
  feature/search   /Users/you/Sites/blog-feature-search   setup   https://blog-feature-search.test   blog_feature_search
 ---------------- -------------------------------------- ------- ---------------------------------- ---------------------
```

The Tool column says what made each worktree: `setup`, `adopt` (or the tool that made it, such as `--tool=cursor`), or `claude` for one Claude Code's hook made. A worktree with no record shows `-`.

For a worktree setup recorded, these are the URL and databases it was set up with, even after your config changes; an older worktree's are worked out from the config, and a worktree that has no record and isn't named the way setup names its branch's worktree shows neither. The URL column shows `-` when the site driver serves nothing. The Database column only appears when a connection has a database the package named: a server database (MySQL, MariaDB, or PostgreSQL), or the SQLite file it places next to a main database kept outside the repository. A SQLite file inside the worktree belongs to the worktree's own `.env`, so there's no single name worth printing.

## Finding a Worktree

`worktree:path` prints where a branch's worktree lives, or an adopted worktree's, given the name it was adopted under. It creates nothing and touches nothing:

```bash
php artisan worktree:path feature/login
# /Users/you/Sites/blog-feature-login
```

A branch that's checked out in a worktree gives that worktree's directory, wherever it is. Otherwise the path is resolved from your config rather than guessed, so it stays correct even after you change `path` or the host template. That's what makes the `cd` in the Warp tab configs below land in the right place.

## Configuration

Every part of the workflow is driven by `config/worktree.php`, so the package adapts to your stack instead of forcing one setup. Here are the parts you're most likely to touch.

Worktrees go next to your project by default. Set `path` (`WORKTREE_PATH`) to a nested directory such as `.worktrees` to keep them inside it instead, and add that directory to your `.gitignore`, or the worktrees show up as untracked files in the main repository's `git status`.

### Site Drivers

A site driver serves each worktree as a local site during setup and stops serving it during teardown. Pick one with `site.driver`:

```php
'site' => [
    'driver' => env('WORKTREE_DRIVER'),
    'secure' => env('WORKTREE_SECURE'),
    'timeout' => 60,
],
```

| Driver | Serves the worktree with |
|---|---|
| `auto` | Herd when the `herd` command is installed, otherwise Valet when `valet` is, otherwise nothing |
| `herd` | [Laravel Herd](https://herd.laravel.com) on macOS or Windows: `herd link`, then `herd secure` for HTTPS |
| `valet` | [Laravel Valet](https://laravel.com/docs/valet) on macOS, valet-linux on Linux, or valet-windows on Windows: `valet link`, then `valet secure` |
| `command` | [Commands you configure](#the-command-driver), for any server with a command line |
| `none` | Nothing. Use it when you serve the site some other way, or not at all |

A [class of your own](#writing-your-own-driver) works too. Leave `site.driver` empty and `auto` decides, unless your config still sets the [older `herd` option](#the-older-herd-option).

`site.secure` (`WORKTREE_SECURE`) serves the site over HTTPS. Left empty, Herd secures it and every other driver serves plain HTTP, since Valet asks for a password to secure a site.

Setup writes the site's address to `APP_URL`, such as `https://blog-feature-login.test`. With `none` it still writes `http://blog-feature-login.test`, so a site you serve by hand later needs no `.env` change. Set `env.app_url_key` to `null` to skip that; the [host rewrite](#host-rewriting) still swaps your main site's host for the worktree's. Setup's summary shows the URL row only when the driver served the site, and `worktree:list` shows `-` in its URL column under a driver that serves nothing.

The host is the worktree name plus `host.tld` (`test` by default). The package doesn't ask your server for its TLD, so if you changed it there, set `WORKTREE_TLD` to match.

The `herd` and `valet` drivers run the command of that name from your `PATH`. If yours lives elsewhere, put its full path in `site.drivers.herd.binary` or `site.drivers.valet.binary`. A driver you chose by name whose command can't be found serves nothing: setup warns, writes an `http://` address, and carries on. `auto` moves on to the next server without a warning.

A server's command line can hang while it waits on something you can't see, like a dialog in the Herd app or a password prompt. Each driver command gets `site.timeout` seconds (`0` turns the limit off). One that runs out is stopped, setup or teardown warns you, and the driver's remaining commands are skipped and listed by name, so you can run them yourself once the server responds. Everything else carries on.

#### Herd

The worktree is always linked before it's secured. Herd only serves parked and linked directories, and a worktree in a nested path such as `.worktrees` is neither, so `herd secure` alone would create a certificate for a site that never answers.

Herd's command line can report success when the Herd app never did the work, and it links in the background. So after `herd link`, setup reads `herd links` for up to five seconds and secures the site once Herd lists it, or when the list can't be read. A site Herd never lists isn't secured, and setup warns with the commands to run by hand. Running setup again on a worktree Herd already serves doesn't link it a second time, because `herd link` also runs `php artisan boost:update` in a worktree that has Laravel Boost installed.

#### Valet

One driver covers Laravel Valet, valet-linux, and valet-windows, since all three take the same commands. They ask for a password or for elevation more often than Herd does:

- **macOS:** Valet runs most of its commands through `sudo`. Run `valet trust` once, or setup waits on a password prompt until the timeout stops it.
- **Linux:** valet-linux asks for `sudo` to secure a site and to remove a secured one.
- **Windows:** run setup and teardown from an elevated terminal, or valet-windows asks for elevation.

#### The `command` Driver

Any server with a command line can serve worktrees through `command`. Configure up to five commands under `site.drivers.command`:

| Command | When it runs | Where it runs |
|---|---|---|
| `serve` | During setup; required | In the worktree |
| `secure` | After `serve`, when `site.secure` is on | In the worktree |
| `check` | After `serve` and `secure`, to confirm the server lists the site | In the main repository |
| `unsecure` | During teardown, for a secured site | In the main repository |
| `unserve` | During teardown | In the main repository |

Each command is a list of arguments, or one string split on its spaces. A string doesn't go through a shell, so quotes don't group anything: use a list when an argument holds a space. `serve` and `secure` take every worktree token (`{repo}`, `{branch}`, `{name}`, `{slug}`, `{host}`, `{tld}`) plus `{path}`, the worktree directory. `check`, `unsecure`, and `unserve` know a site only by its name and directory, since teardown can meet a worktree with no branch, so they take `{name}`, `{path}`, `{host}`, and `{tld}`.

The `check` passes when its output names the site, alone or followed by `.{tld}`: `blog-feature-login` and `https://blog-feature-login.test` both count, `blog-feature-login-v2` doesn't. Leave `unserve` empty for a server that can't remove a site, and teardown reminds you to remove it by hand. Choosing `command` without a `serve` command is an error.

[Yerd](https://yerd.app) on macOS or Linux, for example:

```php
'site' => [
    'driver' => 'command',

    'drivers' => [
        'command' => [
            'serve' => ['yerd', 'link', '{name}', '{path}'],
            'secure' => ['yerd', 'secure', '{name}'],
            'check' => ['yerd', 'sites'],
            'unsecure' => ['yerd', 'unsecure', '{name}'],
            'unserve' => ['yerd', 'unlink', '{name}'],
        ],
    ],
],
```

[EnvKit](https://envkit.net) on Windows or macOS registers a folder with one command. It has no command to remove a site, so teardown reminds you to remove it in the EnvKit app:

```php
'command' => [
    'serve' => 'envkit sites-link {path}',
],
```

[Laragon](https://laragon.org) on Windows serves every project folder in its `www` directory as `{folder}.test` once it reloads. Keep your project in `www` with the default `path` of `..`, and each worktree lands there too, in a folder named after it. Laragon's only command reloads it, and the Laragon app has to be running for it:

```php
'command' => [
    'serve' => ['C:\laragon\laragon.exe', 'reload'],
],
```

Laragon can't remove a site from the command line either. After teardown, delete the worktree's `auto.{host}.conf` file from Laragon's `etc\nginx\sites-enabled` (or `etc\apache2\sites-enabled`) and reload.

#### Writing Your Own Driver

When none of these fits, write a class for your server and put its name in `site.driver`:

```php
'driver' => App\Worktree\YerdDriver::class,
```

The class implements `Mozex\Worktree\Contracts\SiteDriver`. Laravel's container builds it, so its constructor can ask for whatever it needs. It never starts a process itself: it hands each command, as a list of arguments, to the runner it's given. The runner keeps your main app's `.env` values out of the command's environment and applies `site.timeout`. Its `run()` returns `null` for a command that didn't run to its end, after warning about it. Here's the Yerd recipe above as a class:

```php
namespace App\Worktree;

use Mozex\Worktree\Contracts\SiteDriver;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Worktree;
use Symfony\Component\Process\ExecutableFinder;

class YerdDriver implements SiteDriver
{
    public function available(): bool
    {
        return (new ExecutableFinder)->find('yerd') !== null;
    }

    public function url(Worktree $worktree, bool $secure): ?string
    {
        return ($secure ? 'https' : 'http').'://'.$worktree->host();
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        $name = $worktree->name();

        if ($runner->run(['yerd', 'link', $name, $worktree->path()])?->successful() !== true) {
            $runner->warn("Yerd could not link [{$name}].");

            return false;
        }

        if ($secure && $runner->run(['yerd', 'secure', $name])?->successful() !== true) {
            $runner->warn("Yerd could not secure [{$name}].");

            return false;
        }

        return true;
    }

    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        return null;
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        if ($secure) {
            $runner->run(['yerd', 'unsecure', $name]);
        }

        $runner->run(['yerd', 'unlink', $name]);
    }

    public function timeoutHint(): string
    {
        return 'Check that the Yerd daemon is running.';
    }
}
```

What each method is for:

- `available()` says whether the server is installed. A driver that isn't available serves nothing.
- `url()` is the address the site answers at, or `null` when the driver serves nothing.
- `serve()` links the worktree, and secures it when asked. It returns `false` when that didn't work, and setup then leaves the URL out of its summary.
- `isServed()` says whether the server lists the site, or `null` when the driver can't tell.
- `unserve()` gets a name, a path, and whether the site was secured, not a `Worktree`, because the worktree may have no branch and its directory may already be gone.
- `timeoutHint()` is added to the warning when a command runs out of time.

Every method is best effort: report a problem with `$runner->warn()` rather than throwing, so setup and teardown can finish. The interface won't gain methods in later 1.x releases; anything new arrives as a separate interface a driver can choose to implement.

A driver for a server many people use belongs in the package, and pull requests for one are welcome. The repository's `CLAUDE.md` lists the places a driver is registered and how its commands are tested against a stand-in for the real server.

#### The Older `herd` Option

Configs published by earlier versions choose the server with `herd` (`WORKTREE_HERD`), and it still works while `site.driver` is empty. `secure` serves through Herd over HTTPS, `link` through Herd over HTTP, and `none` serves nothing. Any other value is read as `secure`, as it always was, and setup warns about it.

Those configs set `herd` to `secure` unless you changed it, so they keep using Herd. To let the package find your server instead, set `WORKTREE_DRIVER=auto`.

#### Lerd

When [Lerd](https://github.com/lerd-env/lerd) serves a site, it sets up every new git worktree of that site by itself. If it serves your repository, Lerd and `worktree:setup` would both provision the same worktree at once. So when Lerd is installed, setup warns if your repository holds a `.lerd.yaml` file or `lerd sites` lists it. Let one of the two set worktrees up. To turn the check off, set `site.lerd.binary` to `null`.

#### Long Names

The site name doubles as the host, and a host has to fit in a TLS certificate, which holds 64 characters at most. A long repository name plus a long branch would break securing the site, so the worktree name is capped to keep the whole host (`.test` included) within 63 characters. A longer name is cut and given a short hash, so two long branches never share a name. The directory and database names derive from the same capped name. A worktree an older version created under its full name keeps that name: every command recognizes it by its directory, so it still resumes, lists, and tears down with the databases it really has.

### Databases

Each worktree gets its own database on every connection you list under `database.connections`, so two branches never share data. What that means depends on the driver, because the isolation problem is different for each.

**MySQL, MariaDB, and PostgreSQL** put every worktree on one shared server, so each worktree gets a database of its own, named after it (`blog_feature_login`). The name is lowercased, with anything that isn't a letter or number turned into an underscore, so it stays valid everywhere. A name that would blow past the server's identifier limit (64 characters on MySQL, 63 on Postgres) gets cut and given a short hash, so a long repo plus a long branch can't produce a name the server rejects. Postgres works too: databases are created against the `postgres` maintenance connection and dropped `WITH (FORCE)`, which needs PostgreSQL 13 or newer. Teardown drops every database it made, plus the `{name}_test_{token}` databases parallel test runs derived from them (paratest worker databases, [mozex/laravel-test-lanes](https://github.com/mozex/laravel-test-lanes) lanes), so they never outlive the worktree. A name like that which is another worktree's own database stays: branch `feature/login-test-2` gets `blog_feature_login_test_2`, which looks like a lane of `feature/login`. If teardown can't read what the other worktrees use, it drops none of these and says so.

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

A copy skips the downloading, unpacking, and install scripts an install runs, so it finishes in a fraction of the time. A copied `vendor` boots and a copied `node_modules` builds, because both are portable within one machine.

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

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
