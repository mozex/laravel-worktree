<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

class SetupCommand extends ProvisionCommand
{
    protected $signature = 'worktree:setup
        {branch? : The branch to work on (auto-generated when omitted)}
        {--base= : Base branch used when creating a new branch}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}
        {--print-path : Print only the resolved worktree path (status goes to stderr), for shell integration}';

    protected $description = 'Create an isolated git worktree with its own databases and local site';

    public function handle(): int
    {
        $this->routeHumanToError = (bool) $this->option('print-path');

        $source = $this->laravel->basePath();

        if (! $this->isGitRepository($source)) {
            $this->display()->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->display()->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $worktree = $this->worktreeFor($source, $this->resolveBranch());

        $this->provision($worktree, $worktree->branch(), function () use ($worktree): bool {
            $this->display()->info("Creating worktree [{$worktree->name()}] on branch [{$worktree->branch()}]");

            return $this->createWorktree($worktree);
        });

        if ($this->option('print-path')) {
            $this->line($worktree->path());
        }

        return self::SUCCESS;
    }

    protected function resolveBranch(): string
    {
        $branch = $this->cleanBranch((string) $this->argument('branch'));

        if ($branch !== '') {
            return $branch;
        }

        return 'feature/auto-'.Carbon::now()->format('ymd-His');
    }

    /**
     * @return bool Whether the worktree was already there, so setup resumes it.
     */
    protected function createWorktree(Worktree $worktree): bool
    {
        // A directory that is already this branch's registered worktree is not a
        // conflict but a half-finished setup (a failed npm step, an interrupted
        // migration), so provisioning resumes instead of demanding a teardown.
        if ($this->isExistingWorktree($worktree)) {
            $this->display()->info("Worktree [{$worktree->name()}] already exists; resuming provisioning.");

            return true;
        }

        // git populates an existing empty directory happily, and teardown can leave
        // one behind when Windows has not released its handle yet, so only a
        // directory with something in it is a real conflict.
        if (is_dir($worktree->path()) && ! Directory::isEmpty($worktree->path())) {
            throw WorktreeException::worktreeExists($worktree->path());
        }

        if ($this->attempt(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$worktree->branch()}"], $worktree->sourcePath())) {
            $this->process([...$this->git(), 'worktree', 'add', $worktree->path(), $worktree->branch()], $worktree->sourcePath());

            return false;
        }

        $base = (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main'));

        $this->process([...$this->git(), 'worktree', 'add', $worktree->path(), '-b', $worktree->branch(), $base], $worktree->sourcePath());

        return false;
    }

    /**
     * Whether the target directory is already registered as this branch's
     * worktree. A worktree on any other branch stays a conflict.
     */
    protected function isExistingWorktree(Worktree $worktree): bool
    {
        $entries = WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $worktree->sourcePath()));

        foreach ($entries as $entry) {
            if ($entry['branch'] === $worktree->branch() && $this->samePath($entry['path'], $worktree->path())) {
                return true;
            }
        }

        return false;
    }
}
