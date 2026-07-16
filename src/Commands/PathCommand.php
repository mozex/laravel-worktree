<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Mozex\Worktree\Worktree;

class PathCommand extends WorktreeCommand
{
    protected $signature = 'worktree:path {branch : The branch the worktree belongs to}';

    protected $description = 'Print the resolved directory path for a worktree branch';

    public function handle(): int
    {
        $branch = $this->cleanBranch((string) $this->argument('branch'));

        if ($branch === '') {
            $this->components->error('Provide a branch name.');

            return self::FAILURE;
        }

        $source = $this->laravel->basePath();

        // Resolving from inside a worktree would derive names from the worktree's
        // own directory and print a path that does not exist.
        if ($this->isGitRepository($source) && ! $this->isMainRepository($source)) {
            $this->components->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $this->line(Worktree::make($source, $branch, $this->settings())->path());

        return self::SUCCESS;
    }
}
