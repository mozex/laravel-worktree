<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Mozex\Worktree\Enums\FinishMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\SiteSettings;
use Mozex\Worktree\Support\WorktreeList;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * @phpstan-import-type Entry from Registry
 */
class TeardownCommand extends WorktreeCommand
{
    protected $signature = 'worktree:teardown
        {name? : The worktree name or branch to finish}
        {--pr : Push the branch and open a pull request}
        {--into= : Merge the branch into this target branch}
        {--abandon : Discard the branch without merging}
        {--message= : Commit message used when there are pending changes}
        {--keep-database : Do not drop the worktree databases}
        {--keep-branch : Keep the branch, whichever way the worktree is finished}
        {--force : Skip confirmations and discard uncommitted changes}';

    protected $description = 'Finish a worktree (PR, merge, or abandon) and clean up its databases and files';

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

        $registry = $this->registry($source);
        $worktree = $this->selectWorktree($source, $registry);

        if ($worktree === null) {
            $this->components->info('No worktrees to finish.');

            return self::SUCCESS;
        }

        // What setup recorded for the worktree, when it did: the driver that
        // served it and the databases it made, which the configuration may no
        // longer describe.
        $record = $registry?->find($this->canonical($worktree['path']));

        // Resolved before anything is finished, so a misconfigured driver
        // stops the teardown before a merge or a push, not halfway through.
        $site = $record === null ? $this->siteSettings() : $this->recordedSiteSettings($record);
        $mode = $this->resolveMode();

        $this->finish($worktree, $mode, $source);
        $this->cleanup($worktree, $mode, $source, $site, $record);

        $this->components->info("Finished worktree [{$this->describe($worktree)}].");

        return self::SUCCESS;
    }

    /**
     * A worktree by its branch, or by its directory when its HEAD is
     * detached, as many tools start the worktrees they make.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function describe(array $worktree): string
    {
        return $worktree['branch'] ?? basename($worktree['path']);
    }

    /**
     * @return array{path: string, branch: string|null}|null
     */
    protected function selectWorktree(string $source, ?Registry $registry): ?array
    {
        $worktrees = array_values(array_filter(
            WorktreeList::parse($this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source)),
            fn (array $entry): bool => ! $this->samePath($entry['path'], $source),
        ));

        if ($worktrees === []) {
            return null;
        }

        $name = (string) $this->argument('name');

        if ($name !== '') {
            return $this->matchWorktree($worktrees, $name, $registry);
        }

        if (count($worktrees) === 1) {
            return $worktrees[0];
        }

        $options = [];

        foreach ($worktrees as $entry) {
            $options[$entry['path']] = (string) $entry['branch'].' ('.basename($entry['path']).')';
        }

        $choice = (string) select(
            label: 'Which worktree do you want to finish?',
            options: $options,
        );

        return $this->matchWorktree($worktrees, $choice, $registry);
    }

    /**
     * A worktree by its branch, its path or directory, or the name it was
     * recorded under, which for an adopted worktree is neither of the others.
     *
     * @param  array<int, array{path: string, branch: string|null}>  $worktrees
     * @return array{path: string, branch: string|null}
     */
    protected function matchWorktree(array $worktrees, string $name, ?Registry $registry): array
    {
        $matches = array_values(array_filter($worktrees, fn (array $entry): bool => $entry['branch'] === $name
            || $this->samePath($entry['path'], $name)
            || basename($entry['path']) === $name
            || ($registry?->find($this->canonical($entry['path']))['name'] ?? null) === $name));

        // An adopted name may equal another worktree's branch or directory,
        // and finishing the wrong one would delete its branch.
        if (count($matches) > 1) {
            throw WorktreeException::ambiguousWorktree($name);
        }

        return $matches[0] ?? throw WorktreeException::worktreeNotFound($name);
    }

    protected function resolveMode(): FinishMode
    {
        if ($this->option('abandon')) {
            return FinishMode::Abandon;
        }

        if ($this->option('into')) {
            return FinishMode::Merge;
        }

        if ($this->option('pr')) {
            return FinishMode::PullRequest;
        }

        return FinishMode::from(select(
            label: 'How do you want to finish this work?',
            options: [
                FinishMode::PullRequest->value => 'Push and open a pull request',
                FinishMode::Merge->value => 'Merge into another branch',
                FinishMode::Abandon->value => 'Abandon the changes',
            ],
            default: FinishMode::PullRequest->value,
        ));
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finish(array $worktree, FinishMode $mode, string $source): void
    {
        match ($mode) {
            FinishMode::PullRequest => $this->finishWithPullRequest($worktree),
            FinishMode::Merge => $this->finishWithMerge($worktree, $source),
            FinishMode::Abandon => $this->confirmAbandon($worktree),
        };
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finishWithPullRequest(array $worktree): void
    {
        $branch = $worktree['branch'];

        if ($branch === null) {
            throw WorktreeException::detachedWorktree($worktree['path']);
        }

        if ($this->isDirty($worktree['path'])) {
            $message = (string) ($this->option('message') ?: "Work on {$branch}");
            $this->process(['git', 'add', '-A'], $worktree['path']);
            $this->process(['git', 'commit', '-m', $message], $worktree['path']);
        }

        $this->process(['git', 'push', '-u', 'origin', $branch], $worktree['path']);
        $this->process(['gh', 'pr', 'create', '--base', $this->defaultBranch($worktree['path']), '--fill'], $worktree['path']);
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finishWithMerge(array $worktree, string $source): void
    {
        if ($worktree['branch'] === null) {
            throw WorktreeException::detachedWorktree($worktree['path']);
        }

        if ($this->isDirty($worktree['path']) && ! $this->option('force')) {
            throw WorktreeException::dirtyWorktree($worktree['path']);
        }

        $target = $this->resolveMergeTarget($source);

        $this->process(['git', 'checkout', $target], $source);
        $this->process(['git', 'merge', '--no-ff', '--no-edit', $worktree['branch']], $source);
    }

    /**
     * The merge mode is reachable from the interactive prompt as well as from
     * --into, so the target has to be asked for when the option is absent.
     */
    protected function resolveMergeTarget(string $source): string
    {
        $target = (string) $this->option('into');

        if ($target !== '') {
            return $target;
        }

        return text(
            label: 'Which branch should this merge into?',
            default: $this->defaultBranch($source),
            required: true,
        );
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function confirmAbandon(array $worktree): void
    {
        if ($this->option('force')) {
            return;
        }

        if (confirm(label: "Discard all changes on [{$this->describe($worktree)}]?", default: false)) {
            return;
        }

        // Also the --no-interaction path, where the confirm returns its default
        // and nobody actually said no, so the message has to fit both.
        throw WorktreeException::abandonNotConfirmed($this->describe($worktree));
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     * @param  Entry|null  $record
     */
    protected function cleanup(array $worktree, FinishMode $mode, string $source, SiteSettings $site, ?array $record): void
    {
        // Each test suite's connection lives in a PHPUnit file inside the
        // worktree, about to be deleted with it, so they are resolved up front.
        $testConnections = $this->testConnections($worktree['path']);

        $this->unserve($worktree, $source, $site, $record);

        $force = $this->option('force') || $mode === FinishMode::Abandon;
        $remove = [...$this->git(), 'worktree', 'remove', $worktree['path']];

        if ($force) {
            $remove[] = '--force';
        }

        // Remove the worktree before dropping anything: if the removal fails,
        // the databases are still around to retry against.
        $this->removeWorktree($remove, $worktree['path'], $source);

        // git leaves behind whatever it will not follow, such as the public/storage
        // link a storage:link step creates. The directory would then survive and
        // block worktree:setup for this branch, so clear it out.
        if (is_dir($worktree['path']) && ! Directory::delete($worktree['path'])) {
            $this->components->warn("Could not fully remove [{$worktree['path']}]; delete it by hand.");
        }

        $this->dropDatabases($worktree, $source, $testConnections, $record);
        $this->deleteBranch($worktree, $mode, $source);
        $this->attempt(['git', 'worktree', 'prune'], $source);

        // Last, so a teardown that stops partway still leaves the record to
        // finish from.
        if ($record !== null) {
            $this->updateRegistry($source, fn (Registry $registry) => $registry->forget($record['path']));
        }
    }

    /**
     * A worktree with no branch has no Worktree to describe it, but its site
     * was linked under its recorded site name, or its directory name for a
     * worktree made before anything was recorded.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     * @param  Entry|null  $record
     */
    protected function unserve(array $worktree, string $source, SiteSettings $site, ?array $record): void
    {
        $name = $record['site'] ?? $this->setupName($worktree, $source);

        if ($name === null) {
            $this->components->warn("[{$worktree['path']}] has no record and is not named the way setup names a branch's worktree, so it is taken for another tool's: any site or database it has is left alone.");

            return;
        }

        $driver = $this->siteDriver($site->driver());
        $this->locked($source, fn () => $driver->unserve($name, $worktree['path'], $site->secure(), $this->siteRunner($site, $driver->timeoutHint())));
    }

    /**
     * git unregisters a worktree before it deletes the files, so a delete that
     * fails partway (a file another program holds, a path too long for
     * Windows) leaves git considering the worktree gone while its directory
     * survives. The cleanup then carries on and deletes the rest itself. A
     * worktree git still lists was refused on purpose (it is locked, or has
     * changes and --force was not given), so that failure stops the teardown.
     *
     * @param  array<int, string>  $remove
     */
    protected function removeWorktree(array $remove, string $path, string $source): void
    {
        try {
            $this->process($remove, $source);
        } catch (WorktreeException $exception) {
            if ($this->isRegistered($path, $source)) {
                throw $exception;
            }

            $this->components->warn("git removed the worktree but not all of its files ({$exception->getMessage()}); deleting the rest.");
        }
    }

    /**
     * The connections each configured entry's test database ran on, mirroring
     * what setup provisioned. Keyed by the entry's index so a drop can find its
     * server after the worktree, and the PHPUnit files naming its connections,
     * are gone.
     *
     * @return array<int, list<string|null>>
     */
    protected function testConnections(string $path): array
    {
        $connections = [];

        foreach ($this->databaseConnections() as $index => $entry) {
            $connections[$index] = $this->testConnectionsFor($entry, $path);
        }

        return $connections;
    }

    /**
     * Every worktree database, application and test, on the connection setup
     * created it on: the ones it recorded, or for a worktree made before
     * anything was recorded, the ones the configuration names. A SQLite file
     * inside the worktree went with it, so of the file databases only the ones
     * setup placed next to a main file outside the repository are left to
     * delete.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     * @param  array<int, list<string|null>>  $testConnections
     * @param  Entry|null  $record
     */
    protected function dropDatabases(array $worktree, string $source, array $testConnections, ?array $record): void
    {
        if ($this->option('keep-database') || ! (bool) Arr::get($this->settings(), 'database.enabled', true)) {
            return;
        }

        [$drops, $files] = $record === null ? $this->derivedDrops($worktree, $source, $testConnections) : $this->recordedDrops($record);

        $this->dropPlanned($drops, $files, $source, (bool) $this->option('force'), $worktree['path']);
    }

    /**
     * The databases and files the configuration names for the worktree. A
     * worktree with no branch has no names to derive.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     * @param  array<int, list<string|null>>  $testConnections
     * @return array{0: array<string, array{database: string, manager: DatabaseManager}>, 1: array<string, string>}
     */
    protected function derivedDrops(array $worktree, string $source, array $testConnections): array
    {
        if ($worktree['branch'] === null || $this->setupName($worktree, $source) === null) {
            return [[], []];
        }

        $names = $this->unrecordedWorktree($source, $worktree['branch'], $worktree['path']);

        /** @var array<string, array{database: string, manager: DatabaseManager}> $drops */
        $drops = [];
        $files = [];

        foreach ($this->databaseConnections() as $index => $entry) {
            $app = $this->databases($entry['connection']);

            if ($app->isServer()) {
                $this->collectDrop($drops, $names->database($entry['name']), $app);
            }

            if ($app->isFile() && $this->isOutsideFile($names, $app->database())) {
                $files[$this->siblingDatabaseFile($names, $app->database(), $entry['name'])] = $this->resolveFile($names, $app->database());
            }

            if ($entry['test'] === null) {
                continue;
            }

            // One name, but possibly more than one server: collectDrop keys by
            // both, so the usual case where every PHPUnit file tests on the same
            // connection collapses back to a single drop.
            foreach ($testConnections[$index] ?? [null] as $connection) {
                $test = $this->databases($connection);

                if ($test->isServer()) {
                    $this->collectDrop($drops, $names->database($entry['test']['name']), $test);
                }
            }
        }

        return [$drops, $files];
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function deleteBranch(array $worktree, FinishMode $mode, string $source): void
    {
        if ($this->option('keep-branch') || $mode === FinishMode::PullRequest || $worktree['branch'] === null) {
            return;
        }

        $flag = $mode === FinishMode::Merge ? '-d' : '-D';

        $this->attempt(['git', 'branch', $flag, $worktree['branch']], $source);
    }

    protected function isDirty(string $path): bool
    {
        return trim($this->captureOrFail(['git', 'status', '--porcelain'], $path)) !== '';
    }

    protected function defaultBranch(string $path): string
    {
        $reference = trim($this->capture(['git', 'symbolic-ref', 'refs/remotes/origin/HEAD'], $path));

        if ($reference !== '') {
            return (string) preg_replace('#^refs/remotes/origin/#', '', $reference);
        }

        return (string) Arr::get($this->settings(), 'base_branch', 'main');
    }
}
