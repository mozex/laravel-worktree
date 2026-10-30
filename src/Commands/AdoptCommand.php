<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

/**
 * Provisions a worktree another tool made (an agent app, an editor, a plain
 * git worktree add) the way setup provisions its own: its site, .env,
 * databases, dependencies, migrations and PHPUnit files. It shares setup's
 * provisioning and skips only creating the worktree.
 *
 * @phpstan-import-type Entry from Registry
 */
class AdoptCommand extends ProvisionCommand
{
    protected $signature = 'worktree:adopt
        {path : The worktree directory another tool made, or git worktree add}
        {--name= : The name its site and databases get, in place of the branch in the host template}
        {--tool= : What made the worktree, as its record should say}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}';

    protected $description = 'Provision a git worktree another tool made with its own databases and local site';

    /**
     * The variables a tool's worktree setup script runs with that name the
     * worktree it made, each with the variable holding that worktree's path:
     * an adopt run by hand from such a tool's terminal must not borrow a name
     * meant for another worktree.
     *
     * @var array<string, array{name: string, path: string}>
     */
    protected array $tools = [
        'conductor' => ['name' => 'CONDUCTOR_WORKSPACE_NAME', 'path' => 'CONDUCTOR_WORKSPACE_PATH'],
    ];

    public function handle(): int
    {
        $source = $this->laravel->basePath();

        if (! $this->isGitRepository($source)) {
            $this->display()->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->display()->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        // Teardown and adopt read "setup" in a record as a worktree setup
        // made, which setup alone resumes.
        if ($this->option('tool') === 'setup') {
            $this->display()->error('--tool=setup is kept for the worktrees worktree:setup makes. Name the tool that made this one.');

            return self::FAILURE;
        }

        $argument = trim((string) $this->argument('path'));
        $resolved = $argument === '' ? false : realpath($argument);

        if ($resolved === false || ! is_dir($resolved)) {
            $this->display()->error("There is no directory at [{$argument}].");

            return self::FAILURE;
        }

        $path = $this->canonical($resolved);
        $entries = WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $source));

        // Adopting the main checkout would copy its .env onto itself and
        // point it at a worktree database.
        if ($this->samePath($path, $source) || $this->samePath($path, $entries[0]['path'] ?? $source)) {
            $this->display()->error("[{$path}] is the main repository. worktree:adopt sets up a linked worktree of it.");

            return self::FAILURE;
        }

        $entry = $this->listedWorktree($entries, $path);

        if ($entry === null) {
            $this->display()->error("[{$path}] is not a worktree of this repository: git worktree list does not show it.");

            return self::FAILURE;
        }

        $record = $this->registry($source)?->find($path);
        $setupBranch = $this->setupBranch($source, $path, $entry['branch'], $record);

        if ($setupBranch !== null) {
            $this->display()->error("[{$path}] was made by worktree:setup, which resumes its own worktrees: run worktree:setup {$setupBranch} instead.");

            return self::FAILURE;
        }

        $tool = $this->toolWorktree($path);

        $this->tool = (string) ($this->option('tool') ?: $tool[0] ?? $record['tool'] ?? 'adopt');

        $worktree = $this->adoptedWorktree($source, $path, $record, $tool[1] ?? null);

        $this->provision($worktree, $entry['branch'], function () use ($worktree): bool {
            $this->display()->info("Adopting the worktree at [{$worktree->path()}] as [{$worktree->name()}]");

            return false;
        });

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $entries
     * @return array{path: string, branch: string|null}|null
     */
    protected function listedWorktree(array $entries, string $path): ?array
    {
        foreach ($entries as $entry) {
            if ($this->samePath($entry['path'], $path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The branch a worktree setup made was named after, which setup needs to
     * resume it under the same names. A worktree an earlier release made has
     * no record, but it sits exactly where setup puts that branch's worktree.
     *
     * @param  Entry|null  $record
     */
    protected function setupBranch(string $source, string $path, ?string $branch, ?array $record): ?string
    {
        if ($record !== null) {
            return $record['tool'] === 'setup' ? $record['branch'] : null;
        }

        return $branch !== null && $this->samePath($path, $this->worktreeFor($source, $branch, $path)->path()) ? $branch : null;
    }

    /**
     * The worktree under its name: --name, then the name the tool that made
     * it gave it, then a short hash of its path. The name stands in for the
     * branch in the host template, so the repository's prefix stays in every
     * host and database name, and a detached HEAD, which many tools start
     * their worktrees on, needs no branch at all. A worktree adopted before
     * keeps the name its site and databases already have.
     *
     * @param  Entry|null  $record
     * @param  string|null  $toolName  The name the tool that made the worktree gave it.
     */
    protected function adoptedWorktree(string $source, string $path, ?array $record, ?string $toolName): Worktree
    {
        $name = trim((string) $this->option('name'));

        // Kept to what a host and a directory take, so a name with a space in
        // it never reaches a server's link command.
        $label = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name !== '' ? $name : $toolName ?? ''), '-.');

        $worktree = Worktree::make($source, $label !== '' ? $label : substr(md5($path), 0, 8), $this->settings())->withPath($path);

        if ($record === null || $record['name'] === $worktree->name()) {
            return $worktree;
        }

        if ($name !== '') {
            $this->display()->warn("[{$path}] was adopted as [{$record['name']}] and keeps that name, which its site and databases already have.");
        }

        return $worktree->withName($record['name']);
    }

    /**
     * The tool whose setup script this runs from, with the name it gave the
     * worktree, when its variables describe this very worktree.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function toolWorktree(string $path): ?array
    {
        foreach ($this->tools as $tool => $variables) {
            $name = getenv($variables['name']);
            $location = getenv($variables['path']);

            if (is_string($name) && $name !== '' && is_string($location) && $location !== '' && $this->samePath($location, $path)) {
                return [$tool, $name];
            }
        }

        return null;
    }
}
