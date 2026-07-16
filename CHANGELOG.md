# Changelog

All notable changes to `laravel-worktree` will be documented in this file.

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
