# Changelog

All notable changes to `laravel-worktree` will be documented in this file.

## 1.9.0 - 2026-11-01

### What's Changed

* Worktrees are now served through site drivers, chosen with `site.driver` (`WORKTREE_DRIVER`): `herd`, `valet`, `command`, `none`, or a class of your own implementing `Mozex\Worktree\Contracts\SiteDriver`. The default, `auto`, uses Herd when the `herd` command is installed, otherwise Valet, otherwise serves nothing. `site.secure` (`WORKTREE_SECURE`) turns HTTPS on; left empty, Herd secures the site and the other drivers serve plain HTTP.
* The `valet` driver serves worktrees through Laravel Valet on macOS, valet-linux and valet-windows: `valet link`, `valet secure`, and on teardown `valet unlink`, each with the site's name.
* The `command` driver runs commands you configure under `site.drivers.command` (`serve`, `secure`, `check`, `unsecure`, `unserve`), with `{site}`, `{path}` and the other worktree tokens filled in, so any server with a command line can serve worktrees. The README has recipes for Yerd, EnvKit and Laragon.
* Herd's `secure`, `unsecure` and `unlink` now always name the site, and teardown runs them from the main repository. Teardown warns when an unlink fails.
* After `herd link`, setup waits up to five seconds for Herd to list the site, matches its name exactly (a site whose name only started with the worktree's used to count), and doesn't secure a site Herd never lists. Running setup again on a worktree Herd already serves no longer links it a second time, which ran `boost:update` in the worktree.
* Setup's summary shows the URL row only when the site was served, and `worktree:list` shows `-` for a driver that serves nothing.
* Each driver command may take `site.timeout` seconds (60 by default, `0` for no limit), which replaces the fixed minute Herd commands had. After a timeout, the driver's remaining commands are skipped and each one is named so you can run it by hand.
* Setup warns when Lerd serves the repository, since Lerd sets up every new worktree of its sites by itself. Set `site.lerd.binary` to `null` to turn the check off.
* Setting `env.app_url_key` to `null` now skips the `APP_URL` rewrite. It used to write a malformed `=http://...` line.
* Setup warns about a `WORKTREE_HERD` value other than `secure`, `link` or `none`, and about a `WORKTREE_SECURE` value that isn't true or false.
* A worktree's site now has a name of its own, set by `host.site` (`WORKTREE_SITE_TEMPLATE`, default `{name}`). Setting it to `{branch}.{repo}` serves worktrees under the main site's host, as `feature-login.blog.test`, without moving any directory or database name.
* Hosts hold only lowercase letters, digits and hyphens between their dots: branch `Feature_Login` keeps the directory `blog-Feature_Login` and is served at `blog-feature-login.test`. A worktree served before this release keeps its site through later setup runs and at teardown.
* A worktree served under the main site's host gets its own `SESSION_COOKIE`, so a main `SESSION_DOMAIN` covering subdomains no longer logs the two apps out of each other.
* Setup now records each worktree it makes in `.git/laravel-worktree/registry.json` (its name, its site, the driver that served it, and every database with its server), and teardown and `worktree:list` go by that record. Changing a name template or switching servers after setup no longer leaves the old databases or site behind.
* A recorded database is dropped only on the server it was created on. When its connection now points elsewhere, teardown keeps it and says so.
* Teardown of a worktree with a detached HEAD now drops the databases setup made for it.
* Setups running on one repository at the same time take turns at creating the worktree, recording it and running the driver's commands, and the one waiting says so.
* Setup and adopt refuse a worktree that would share its databases or its site with another, such as branch `feature_login` next to `feature-login`.
* Teardown leaves the site and databases of a worktree it has no record of alone, and says so, unless setup would have named that worktree the same way. It used to unlink a site by the directory's name.
* Teardown refuses a name that matches two worktrees, and accepts a worktree's path however it's spelled.
* Teardown no longer drops another worktree's database whose name looks like one of its test lanes, such as `feature/login-test-2` next to `feature/login`.
* `worktree:teardown --keep-branch` keeps the branch whichever way the worktree is finished.
* New `worktree:adopt {path}` gives a worktree another tool made (an agent app, an editor, a plain `git worktree add`) its own site, `.env`, databases, dependencies and migrations. It's named by `--name`, Conductor's workspace name, or a short hash of its path. `--no-secure` serves it over HTTP, and `--unless-set-up` leaves a worktree that's already set up alone.
* `php artisan vendor:publish --tag=worktree-adopt` writes `worktree-adopt.php`, which a tool's setup script runs from inside the new worktree.
* `worktree:teardown` and `worktree:path` find an adopted worktree by its name, and `worktree:path` prints where a branch is checked out when it is.
* Links a tool leaves back into the main checkout (`.env`, `vendor`, `node_modules`, SQLite and PHPUnit files) are replaced before anything is written through them.
* New `worktree:prune` lists recorded worktrees whose folder is gone, with each one's site and driver. `--force` drops the databases their records name and forgets the records. It leaves alone a worktree git has locked, and one whose branch, name, site or databases a live worktree still uses, such as one moved with `git worktree move`. `--orphans` lists leftover databases named like this repository's worktrees and drops one only when you confirm it by name.
* Setup and adopt say how many recorded worktrees are gone.
* Teardown and prune say when the server that served a worktree's site is no longer installed.
* New `worktree:hook claude create|remove`, for Claude Code's WorktreeCreate and WorktreeRemove hooks. Create makes a worktree on branch `worktree-<name>` with its own databases, `.env`, site and dependencies and prints its path; remove finishes it and keeps the branch, refusing a worktree with uncommitted or untracked changes.
* New `worktree:install-agent claude` writes those hooks into `.claude/settings.local.json`, so `claude --worktree`, background sessions and subagents get worktrees with their own databases, `.env` and site. It passes setup's options on, serves the worktrees over HTTP unless given `--secure`, and refuses to run beside another worktree hook.
* `worktree:install-agent` also sets up Conductor, Paradev (formerly Superset), Cursor, Codex, Zed and Devin Desktop (formerly Windsurf). It writes Conductor's and Paradev's local setup to run `worktree-adopt.php`, prints the setup to add for the others, and publishes the script when it's missing.
* `worktree:install-agent git` writes a git `post-checkout` hook that adopts every worktree `git worktree add` makes. `LARAVEL_WORKTREE_SKIP_ADOPT=1` leaves one alone.
* `worktree:list` shows which tool made each worktree.

**If you set `WORKTREE_HERD`:** it keeps working while `WORKTREE_DRIVER` is empty. `secure` serves through Herd over HTTPS, `link` through Herd over HTTP, and `none` serves nothing. A config published by an earlier release sets `herd` to `secure` unless you changed it, so it keeps using Herd. Set `WORKTREE_DRIVER=auto` to let the package pick your server, or name one.

**If the configured server isn't installed:** a config that names Herd on a machine without the `herd` command used to fail a `herd link` on every setup and write an `https://` `APP_URL` that nothing served. It now serves nothing, writes an `http://` `APP_URL`, and setup says why. The same goes for a `valet` or `command` driver whose command can't be found.

**The new `auto` default:** an app with no published config, or one that leaves both `WORKTREE_DRIVER` and `WORKTREE_HERD` empty, now uses Herd when it's installed and Valet when only Valet is. If you have Valet installed but don't want it to serve your worktrees, set `WORKTREE_DRIVER=none`.

**Subdomain sites under a secured main site:** if you set `host.site` to `{branch}.{repo}` and your main site is served over HTTPS, serve the worktrees over HTTPS too (`site.secure`, and `--secure` for `worktree:install-agent`). Herd's and Valet's HTTPS setup for `blog.test` also answers `*.blog.test` and sends plain-http requests on to https.

**Worktrees made by an earlier release** are still torn down from the config, and get recorded the next time setup runs on them.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.8.0...1.9.0

## 1.8.0 - 2026-10-10

### What's Changed

* Added `--clone` and `--no-clone` to `worktree:setup`, plus a `database.clone` config block (`WORKTREE_CLONE`). A clone starts the worktree with a copy of your main database instead of an empty one, then runs `migrate` instead of `migrate:fresh`, so only the branch's own new migrations run on top of your data. MySQL and MariaDB copy on the server with `INSERT ... SELECT`, with no `mysqldump` needed, and bring foreign keys, generated columns, views, triggers, stored routines and auto-increment counters along, reading the main database without locking its rows. PostgreSQL copies the database as a template and falls back to `pg_dump` and `pg_restore` while a queue worker or a database GUI holds the main database open. SQLite is copied with `VACUUM INTO`, which includes rows still in a WAL file. Test databases are never cloned, and `database.seed` is skipped on a clone.
* Tables listed in `database.clone.structure_only` are cloned with their schema but no rows. The default list covers the queue tables, `cache`, `cache_locks`, `sessions`, `telescope_*` and `pulse_*`, so a worker in the worktree never runs the main app's pending jobs a second time.
* Setup now refuses a worktree database name, application or test, that matches one of the main repository's databases on any listed connection, ignoring case, before it creates anything. Teardown checks the same names before it drops anything.
* A SQLite database kept outside the repository, at an absolute path or a relative `../` one, is no longer shared with the worktree, where `migrate:fresh` used to wipe it. The worktree gets its own file next to the main one, named by the connection's `name` template, and teardown deletes it.
* Worktree names are capped so the site's host stays within 63 characters, which `herd secure` needs for its certificate. A longer name is cut and given a short hash. Worktrees created under their full name by an earlier release are recognized by their directory and keep resuming, listing and tearing down as before.
* Herd commands now time out after 60 seconds, so a Herd app stuck on a dialog or an elevation prompt no longer hangs setup or teardown. After linking, setup also checks that Herd lists the new site and warns when it doesn't.
* `git worktree add` and `git worktree remove` now run with `core.longpaths`, so a deep `vendor` tree on Windows no longer breaks them. When git unregisters a worktree it couldn't fully delete, such as one with a file another program holds open, teardown deletes the rest and finishes instead of stopping halfway.
* Deleting an entry from `dependencies` in a published config, such as `node_modules`, now removes it. The config merge used to put it back.
* The `env.replace` example now writes whole prefixes (`'REDIS_PREFIX' => '{slug}-database-'`). A stock Laravel `.env` leaves `REDIS_PREFIX` and `CACHE_PREFIX` unset, so `{value}` is empty for them.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.7.0...1.8.0

## 1.7.0 - 2026-10-08

### What's Changed

* Drop Laravel 11 support
* Bump minimum `illuminate/*` requirements from `^11.0|^12.0|^13.0` to `^12.69|^13.30`
* Lower the `spatie/laravel-package-tools` requirement from `^1.93` back to `^1.16`
* Add the package banner and icon

Laravel 11 stopped receiving security fixes on March 12, 2026. Three advisories published since then ([GHSA-5vg9-5847-vvmq](https://github.com/advisories/GHSA-5vg9-5847-vvmq), [GHSA-crmm-hgp2-wgrp](https://github.com/advisories/GHSA-crmm-hgp2-wgrp) and [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8)) affect every Laravel 11 release and were fixed only in Laravel 12 and 13. The new minimums, 12.69.0 and 13.30.0, are the first releases that include all three fixes.

If your app is still on Laravel 11, Composer keeps you on 1.6.1.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.6.1...1.7.0

## 1.6.1 - 2026-10-04

### What's Changed

* Improve package setup

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.6.0...1.6.1

## 1.6.0 - 2026-08-20

### What's Changed

* `database.phpunit_files` now patches every listed file that exists instead of only the first, and reads each file for the connection its own suite runs on. A project that runs a second suite from a second config, browser tests beside the main suite for example, lists both and each file gets the worktree's own test database name plus the `skip-worktree` bit. A file left off the list keeps the name the main checkout put there, so from inside a worktree that suite runs against the main checkout's test database, and nothing shows it: the suite is green, and the parallel-test databases it spawns sit outside what teardown drops. Teardown now drops across every connection those files pin, collapsing to a single drop when they agree, which is the usual case.
* Added a `suggest` entry for `mozex/laravel-test-lanes`, whose lane databases `worktree:teardown` already reaps.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.5.0...1.6.0

## 1.5.0 - 2026-08-01

### What's Changed

* `worktree:teardown` now also drops the `{name}_test_{token}` databases parallel test runs derived from the worktree's databases, covering both paratest worker indexes and mozex/laravel-test-lanes lanes, so they no longer outlive the worktree. Only token-shaped suffixes (digits, or `lane` plus digits) qualify, which keeps a sibling worktree whose slug happens to extend the prefix untouched, and every discovered name passes the same main-database guard as the worktree's own databases.
* Database drops are now keyed by server and name together, so two connections that provision the same database name on different servers both get their database dropped instead of one being quietly left behind.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.4.0...1.5.0

## 1.4.0 - 2026-07-20

### What's Changed

* Added an `env.replace` option that rewrites env values per worktree, such as a Redis or cache prefix. Each entry maps a key to a template built from the worktree's tokens and the key's current value, so the value carries the worktree's slug without a hardcoded handler for it. The rewrites apply to the copied `.env` and to every file in `env.copy`.
* Added isolation for more than one database connection. List each connection under `database.connections`, and every worktree gets its own database on all of them, application and test, created on setup and dropped on teardown. Teardown guards each connection against its own database in the main `.env`. This replaces the single `database.name` and `database.test` settings.
* Added an opt-in `dependencies` option that copies `vendor` and `node_modules` from the main repository instead of installing them, as long as the worktree's lock file matches. A warm copy of `vendor` ran about five times faster than `composer install` in testing. A branch that changed its lock installs normally, and `npm ci` now lives in this block rather than in `steps`.
* A published config file is now merged with the package defaults one level deeper, so a config written before one of these options existed still receives it instead of losing the whole block.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.3.1...1.4.0

## 1.3.1 - 2026-07-16

### What's Changed

* The config defaults for `herd` and `database.migrate` are now plain strings instead of enum values. A published config kept the enum imports, and on a production deploy without dev dependencies those classes do not exist, so loading the configuration failed.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.3.0...1.3.1

## 1.3.0 - 2026-07-16

### What's Changed

* Added `worktree:list`, which shows every worktree with its branch, path, URL, and database.
* Added an `env.copy` option that copies extra gitignored env files (`.env.testing` by default) into the worktree, with the host rewritten.
* `worktree:setup` now resumes a worktree that already exists for the branch, so a failed provisioning step no longer forces a full teardown.
* Worktree sites are linked in Herd before being secured. A worktree in a nested path such as `.worktrees` used to get a certificate for a site that never answered.
* Every command now refuses to run from inside a linked worktree and points back at the main repository.
* Fixed the test database being created and dropped on the app's default connection instead of the one `phpunit.xml` pins. A project that develops on SQLite and tests against MySQL used to fail setup outright.
* Teardown refuses `--pr` and `--into` on a detached worktree, database names are capped at the server's identifier limit, failed commands report their stdout when stderr is empty, and `export KEY=value` env lines are recognized when isolating child processes.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.2.1...1.3.0

## 1.2.1 - 2026-07-16

### What's Changed

* Depend on the split `illuminate/*` components (console, contracts, filesystem, process, support) instead of `laravel/framework`. This keeps the package clear of the framework's security advisories while still supporting Laravel 11, 12, and 13.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.2.0...1.2.1

## 1.2.0 - 2026-07-16

### What's Changed

* The default Node step is now `npm ci` instead of `npm install`. Laravel's `package.json` ships without a `name`, so `npm install` rewrote the tracked `package-lock.json` with the worktree's own directory name; `npm ci` installs from the lockfile and leaves it untouched.
* Reworked the Warp tab configs with tab titles and clearer names (Create, Resume, Finish).

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.1.0...1.2.0

## 1.1.0 - 2026-07-16

### What's Changed

* Fixed a blank or quoted branch name creating a `repo-''` worktree. A blank branch now auto-generates a name, as the docs always said it would.
* Added `--print-path` to `worktree:setup`, which prints only the resolved worktree path so a shell button can `cd` straight into the new worktree.
* Fixed the Warp terminal config so leaving the branch blank works and drops you into the worktree, generated name and all.

**Full Changelog**: https://github.com/mozex/laravel-worktree/compare/1.0.0...1.1.0

## 1.0.0 - 2026-07-16

### What's Changed

* Initial Release

**Full Changelog**: https://github.com/mozex/laravel-worktree/commits/1.0.0
