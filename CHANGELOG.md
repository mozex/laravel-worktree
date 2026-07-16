# Changelog

All notable changes to `laravel-worktree` will be documented in this file.

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
