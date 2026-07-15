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
        $this->line(Worktree::make(base_path(), (string) $this->argument('branch'), $this->settings())->path());

        return self::SUCCESS;
    }
}
