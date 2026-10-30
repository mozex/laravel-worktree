<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

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

            return $this->createWorktree($worktree, (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main')));
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
}
