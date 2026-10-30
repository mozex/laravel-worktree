<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\FinishMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\SiteSettings;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;
use Throwable;

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

        $worktree = $this->selectWorktree($source);

        if ($worktree === null) {
            $this->components->info('No worktrees to finish.');

            return self::SUCCESS;
        }

        // What setup recorded for the worktree, when it did: the driver that
        // served it and the databases it made, which the configuration may no
        // longer describe.
        $record = $this->registry($source)?->find($this->canonical($worktree['path']));

        // Resolved before anything is finished, so a misconfigured driver
        // stops the teardown before a merge or a push, not halfway through.
        $site = $record === null ? $this->siteSettings() : $this->recordedSiteSettings($record);
        $mode = $this->resolveMode();

        $this->finish($worktree, $mode, $source);
        $this->cleanup($worktree, $mode, $source, $site, $record);

        $this->components->info("Finished worktree [{$worktree['branch']}].");

        return self::SUCCESS;
    }

    /**
     * The driver and secure flag the worktree was served with, so its site is
     * stopped where it is served even after the configuration moved to another
     * server. A recorded driver that can no longer be built (its class is
     * gone, its commands were removed) stops nothing: the configured driver
     * never served the worktree, and a site of the same name there may be
     * someone else's.
     *
     * @param  Entry  $record
     */
    protected function recordedSiteSettings(array $record): SiteSettings
    {
        $settings = $this->settings();
        Arr::set($settings, 'site.driver', $record['driver']);
        Arr::set($settings, 'site.secure', $record['secure']);

        try {
            return SiteSettings::resolve($settings, fn (string $driver): bool => $this->siteDriver($driver)->available());
        } catch (Throwable $exception) {
            $this->components->warn("The [{$record['driver']}] site driver that served this worktree can no longer be used ({$exception->getMessage()}), so its site [{$record['name']}] is left for you to stop by hand.");

            return new SiteSettings('none', false, 60);
        }
    }

    /**
     * @return array{path: string, branch: string|null}|null
     */
    protected function selectWorktree(string $source): ?array
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
            return $this->matchWorktree($worktrees, $name);
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

        return $this->matchWorktree($worktrees, $choice);
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $worktrees
     * @return array{path: string, branch: string|null}
     */
    protected function matchWorktree(array $worktrees, string $name): array
    {
        foreach ($worktrees as $entry) {
            if ($entry['branch'] === $name || $entry['path'] === $name || basename($entry['path']) === $name) {
                return $entry;
            }
        }

        throw WorktreeException::worktreeNotFound($name);
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

        if (confirm(label: "Discard all changes on [{$worktree['branch']}]?", default: false)) {
            return;
        }

        // Also the --no-interaction path, where the confirm returns its default
        // and nobody actually said no, so the message has to fit both.
        throw WorktreeException::abandonNotConfirmed((string) $worktree['branch']);
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

        // A worktree with no branch has no Worktree to describe it, but its
        // site was linked under its recorded name, or its directory name for
        // a worktree made before anything was recorded.
        $driver = $this->siteDriver($site->driver());
        $this->locked($source, fn () => $driver->unserve($record['name'] ?? basename($worktree['path']), $worktree['path'], $site->secure(), $this->siteRunner($site, $driver->timeoutHint())));

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

    protected function isRegistered(string $path, string $source): bool
    {
        foreach (WorktreeList::parse($this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source)) as $entry) {
            if ($this->samePath($entry['path'], $path)) {
                return true;
            }
        }

        return false;
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

        $this->dropPlanned($drops, $files, $source);
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
        if ($worktree['branch'] === null) {
            return [[], []];
        }

        $names = $this->worktreeFor($source, $worktree['branch'], $worktree['path']);

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
     * The databases and files setup recorded. A database is dropped only on
     * the server it was created on: once its connection points at another
     * one, the same name there may belong to something else entirely.
     *
     * @param  Entry  $record
     * @return array{0: array<string, array{database: string, manager: DatabaseManager}>, 1: array<string, string>}
     */
    protected function recordedDrops(array $record): array
    {
        /** @var array<string, array{database: string, manager: DatabaseManager}> $drops */
        $drops = [];

        foreach ($record['databases'] as $database) {
            $manager = $this->databases($database['connection']);

            if (! $manager->isServer() || $manager->dsn() !== $database['dsn']) {
                $this->components->warn("Not dropping [{$database['database']}]: it was created on [{$database['dsn']}], and the [{$database['connection']}] connection no longer points there. Drop it there by hand once you no longer need it.");

                continue;
            }

            $this->collectDrop($drops, $database['database'], $manager);
        }

        return [$drops, array_column($record['files'], 'source', 'path')];
    }

    /**
     * Drops a worktree's databases and deletes its files, after refusing
     * anything that is the main repository's own.
     *
     * @param  array<string, array{database: string, manager: DatabaseManager}>  $drops
     * @param  array<string, string>  $files  Each worktree file, with the main file it was made beside.
     */
    protected function dropPlanned(array $drops, array $files, string $source): void
    {
        if ($drops === [] && $files === []) {
            return;
        }

        foreach (array_column($drops, 'database') as $database) {
            if (! $this->isSourceDatabase($source, $database)) {
                continue;
            }

            $this->components->warn("Refusing to drop [{$database}]; it matches the main repository database.");

            return;
        }

        foreach ($files as $file => $main) {
            if (! $this->isSameFile($file, $main) && ! $this->isSourceFile($source, $file)) {
                continue;
            }

            $this->components->warn("Refusing to delete [{$file}]; it is the main repository database.");

            return;
        }

        // Test runs spawn "{name}_test_{token}" databases next to these
        // (paratest worker indexes, mozex/laravel-test-lanes lanes). Fold
        // them in so the confirmation names them and they go too. This runs
        // after the source guard because discovery opens a connection, and
        // the guard must refuse before any connection is attempted. The
        // discovered names get the same guard: a derivative can only match
        // the main database through a pathological name template, but a
        // skipped drop beats a dropped main database.
        $derivatives = [];

        foreach ($drops as $meta) {
            foreach ($meta['manager']->parallelDerivatives($meta['database']) as $derivative) {
                if ($this->isSourceDatabase($source, $derivative)) {
                    $this->components->warn("Refusing to drop [{$derivative}]; it matches the main repository database.");

                    continue;
                }

                $this->collectDrop($derivatives, $derivative, $meta['manager']);
            }
        }

        $drops += $derivatives;

        $list = implode('] and [', array_unique([...array_column($drops, 'database'), ...array_keys($files)]));

        if (! $this->option('force') && ! confirm(label: "Drop databases [{$list}]?", default: true)) {
            return;
        }

        foreach ($drops as $meta) {
            $meta['manager']->drop($meta['database']);
        }

        foreach (array_keys($files) as $file) {
            File::delete([$file, $file.'-wal', $file.'-shm', $file.'-journal']);
        }
    }

    /**
     * Whether a file is one of the main repository's SQLite databases as the
     * configuration names them now. A recorded worktree file the main app was
     * pointed at since (to keep the worktree's data) is no longer the
     * worktree's to delete.
     */
    protected function isSourceFile(string $source, string $file): bool
    {
        $main = Worktree::make($source, 'main', $this->settings());

        foreach ($this->databaseConnections() as $entry) {
            $databases = $this->databases($entry['connection']);
            $configured = $databases->database();

            if ($databases->isFile() && $configured !== '' && $configured !== ':memory:' && $this->isSameFile($file, $this->resolveFile($main, $configured))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two connections may provision the same database name on different
     * servers (setup only rejects duplicates on one server), so drops are
     * keyed by server and name together. Keying by name alone would collapse
     * them and quietly leave one server's database behind.
     *
     * @param  array<string, array{database: string, manager: DatabaseManager}>  $drops
     */
    protected function collectDrop(array &$drops, string $database, DatabaseManager $manager): void
    {
        $drops[$manager->dsn().'|'.$database] = ['database' => $database, 'manager' => $manager];
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function deleteBranch(array $worktree, FinishMode $mode, string $source): void
    {
        if ($mode === FinishMode::PullRequest || $worktree['branch'] === null) {
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
