<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

class PathCommand extends WorktreeCommand
{
    protected $signature = 'worktree:path {branch : The branch the worktree belongs to, or the name it was adopted under}';

    protected $description = 'Print the resolved directory path for a worktree branch';

    public function handle(): int
    {
        // A shell captures the path with "$(...)", so a warning about the
        // registry must not land in it.
        $this->routeHumanToError = true;

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

        // An adopted worktree sits wherever its tool put it, so the branch's
        // checkout comes before the path setup would give it.
        $this->line($this->recordedPath($source, $branch)
            ?? $this->registeredPath($source, $branch)
            ?? $this->worktreeFor($source, $branch)->path());

        return self::SUCCESS;
    }

    /**
     * Where the worktree recorded under this name lives: an adopted one is
     * named after --name or its tool, not after a branch.
     */
    protected function recordedPath(string $source, string $name): ?string
    {
        foreach ($this->registry($source)?->entries() ?? [] as $entry) {
            if ($entry['name'] === $name) {
                return $entry['path'];
            }
        }

        return null;
    }
}
