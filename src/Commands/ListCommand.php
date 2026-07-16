<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

class ListCommand extends WorktreeCommand
{
    protected $signature = 'worktree:list';

    protected $description = 'List the worktrees of this repository with their sites and databases';

    public function handle(): int
    {
        $source = $this->laravel->basePath();

        if (! $this->isGitRepository($source)) {
            $this->components->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->components->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $entries = array_values(array_filter(
            WorktreeList::parse($this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source)),
            fn (array $entry): bool => ! $this->samePath($entry['path'], $source),
        ));

        if ($entries === []) {
            $this->components->info('No worktrees.');

            return self::SUCCESS;
        }

        $herd = HerdMode::tryFrom((string) Arr::get($this->settings(), 'herd', HerdMode::Secure->value)) ?? HerdMode::Secure;

        // The same condition the setup summary reports under: a file database
        // is whatever each worktree's own .env resolves to, not a known name.
        $databases = (bool) Arr::get($this->settings(), 'database.enabled', true) && $this->databases()->isServer();

        $headers = ['Branch', 'Path', 'URL'];

        if ($databases) {
            $headers[] = 'Database';
        }

        $this->output->table($headers, array_map(
            fn (array $entry): array => $this->row($entry, $source, $herd, $databases),
            $entries,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{path: string, branch: string|null}  $entry
     * @return array<int, string>
     */
    protected function row(array $entry, string $source, HerdMode $herd, bool $databases): array
    {
        if ($entry['branch'] === null) {
            return array_pad(['(detached)', $entry['path']], $databases ? 4 : 3, '-');
        }

        $worktree = Worktree::make($source, $entry['branch'], $this->settings());

        $row = [
            $entry['branch'],
            $entry['path'],
            $herd->enabled() ? $herd->scheme().'://'.$worktree->host() : '-',
        ];

        if ($databases) {
            $row[] = $worktree->appDatabase();
        }

        return $row;
    }
}
