<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Mozex\Worktree\Contracts\SiteDriver;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

/**
 * @phpstan-import-type Entry from Registry
 */
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

        $site = $this->siteSettings();
        $driver = $this->siteDriver($site->driver());
        $registry = $this->registry($source);

        $records = array_map(
            fn (array $entry): ?array => $registry?->find($this->canonical($entry['path'])),
            $entries,
        );

        // The same condition the setup summary reports under: a file database
        // inside the worktree is whatever its own .env resolves to, not a known
        // name, so only server databases and the SQLite files placed next to a
        // main file outside the repository are worth a column.
        $databases = (bool) Arr::get($this->settings(), 'database.enabled', true)
            && ($this->databaseNames(Worktree::make($source, 'main', $this->settings())) !== []
                || array_filter($records, fn (?array $record): bool => $record !== null && $this->recordedNames($record) !== []) !== []);

        $headers = ['Branch', 'Path', 'Tool', 'URL'];

        if ($databases) {
            $headers[] = 'Database';
        }

        $this->output->table($headers, array_map(
            fn (array $entry, ?array $record): array => $this->row($entry, $record, $source, $driver, $site->secure(), $databases),
            $entries,
            $records,
        ));

        return self::SUCCESS;
    }

    /**
     * A worktree setup recorded shows what setup made: the address its driver
     * gave and the databases it created, even after the configuration moved
     * on. One made before anything was recorded is described from the
     * configuration.
     *
     * @param  array{path: string, branch: string|null}  $entry
     * @param  Entry|null  $record
     * @return array<int, string>
     */
    protected function row(array $entry, ?array $record, string $source, SiteDriver $driver, bool $secure, bool $databases): array
    {
        if ($record !== null) {
            return $this->columns($entry, $record['tool'], $record['url'], $this->recordedNames($record), $databases);
        }

        // Another tool's worktree was never served or given databases.
        if ($entry['branch'] === null || $this->setupName($entry, $source) === null) {
            return $this->columns($entry, null, null, [], $databases);
        }

        $worktree = $this->unrecordedWorktree($source, $entry['branch'], $entry['path']);

        return $this->columns($entry, null, $driver->url($worktree, $secure), $this->databaseNames($worktree), $databases);
    }

    /**
     * @param  array{path: string, branch: string|null}  $entry
     * @param  string|null  $tool  What made the worktree, as recorded.
     * @param  list<string>  $names
     * @return array<int, string>
     */
    protected function columns(array $entry, ?string $tool, ?string $url, array $names, bool $databases): array
    {
        $row = [$entry['branch'] ?? '(detached)', $entry['path'], $tool ?? '-', $url ?? '-'];

        if ($databases) {
            $row[] = $names === [] ? '-' : implode(', ', $names);
        }

        return $row;
    }

    /**
     * The application databases setup recorded, test databases left out as
     * they are for a worktree described from the configuration.
     *
     * @param  Entry  $record
     * @return list<string>
     */
    protected function recordedNames(array $record): array
    {
        $names = [];

        foreach ($record['databases'] as $database) {
            if (! $database['test']) {
                $names[] = $database['database'];
            }
        }

        foreach ($record['files'] as $file) {
            $names[] = basename($file['path']);
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    protected function databaseNames(Worktree $worktree): array
    {
        $names = [];

        foreach ($this->databaseConnections() as $entry) {
            $databases = $this->databases($entry['connection']);

            if ($databases->isServer()) {
                $names[] = $worktree->database($entry['name']);
            }

            if ($databases->isFile() && $this->isOutsideFile($worktree, $databases->database())) {
                $names[] = basename($this->siblingDatabaseFile($worktree, $databases->database(), $entry['name']));
            }
        }

        return $names;
    }
}
