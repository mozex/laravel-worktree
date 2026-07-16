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

        $this->line(Worktree::make($this->laravel->basePath(), $branch, $this->settings())->path());

        return self::SUCCESS;
    }
}
