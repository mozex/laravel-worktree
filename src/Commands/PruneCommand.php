<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;
use PDOException;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * Cleans up after the worktrees this package recorded whose directory is
 * gone without a teardown: an agent app or an editor deleted its worktree,
 * or someone did by hand, and the site and databases stayed. It only lists
 * them unless told to act, since a directory can also be missing because it
 * was moved or its drive is not mounted, and a dropped database cannot come
 * back. It drops only what the registry recorded. It can also look for
 * databases named like this repository's worktrees that nothing records,
 * and drops one only when asked about it by name.
 *
 * @phpstan-import-type Entry from Registry
 */
class PruneCommand extends WorktreeCommand
{
    protected $signature = 'worktree:prune
        {--force : Stop the sites and drop the databases the gone worktrees left behind, instead of only listing them}
        {--orphans : Also list databases named like this repository\'s worktrees that no worktree claims}';

    protected $description = 'Clean up after worktrees whose directory is gone, and find databases no worktree claims';

    /**
     * A name no branch carries, put where the branch goes to find out what
     * this repository's worktree databases are named like.
     */
    protected string $marker = 'wtprunemarker';

    /**
     * One runner per site driver for the whole run, so a server stuck on a
     * dialog costs one time limit, not one per worktree.
     *
     * @var array<string, SiteRunner>
     */
    protected array $runners = [];

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

        // The console keeps one instance of a command, and a runner that
        // gave up on a server in an earlier run must not skip this one's.
        $this->runners = [];

        $registry = $this->registry($source);

        if ($registry === null) {
            $this->components->error('There is no readable worktree registry to go by, so nothing is pruned.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $this->pruneGone($source, $registry, $force);

        if ($this->option('orphans')) {
            $this->sweepOrphans($source, $force);
        }

        return self::SUCCESS;
    }

    protected function pruneGone(string $source, Registry $registry, bool $force): void
    {
        $porcelain = $this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source);

        foreach (WorktreeList::locked($porcelain) as $path) {
            if (! is_dir($path) && $registry->find($path) !== null) {
                $this->components->info("[{$path}] is gone, but git has it locked, so it is left alone. Run git worktree unlock on it once it is gone for good.");
            }
        }

        $gone = $this->goneEntries($registry, $porcelain);

        if ($gone === []) {
            $this->components->info('No recorded worktree is gone.');

            return;
        }

        if (! $force) {
            $this->listGone($gone, $source, $registry, $porcelain);

            return;
        }

        $pruned = false;

        // Each under a lock of its own, with the registry and git's list
        // read again: a setup waiting on the lock never waits out every
        // entry, and one that reused a path or a name meanwhile keeps it.
        foreach ($gone as $entry) {
            if ($this->locked($source, fn (): bool => $this->pruneOne($entry['path'], $source))) {
                $pruned = true;
            }
        }

        // git's own record of every worktree whose directory is gone, this
        // package's and other tools' alike, which holds nothing but git's
        // bookkeeping for them.
        if ($pruned) {
            $this->attempt(['git', 'worktree', 'prune'], $source);
        }
    }

    /**
     * The dry run: what --force would prune, in a table, and why it would
     * leave the rest.
     *
     * @param  list<Entry>  $gone
     */
    protected function listGone(array $gone, string $source, Registry $registry, string $porcelain): void
    {
        $prunable = [];

        foreach ($gone as $entry) {
            $claimants = $this->claimants($entry, $source, $registry, $porcelain);

            if ($claimants === []) {
                $prunable[] = $entry;

                continue;
            }

            $this->keepClaimed($entry, $claimants, $source, $registry, false);
        }

        if ($prunable === []) {
            return;
        }

        $this->output->table(['Name', 'Path', 'Site', 'Databases'], array_map(fn (array $entry): array => [
            $entry['name'],
            $entry['path'],
            $entry['driver'] === 'none' ? '-' : $entry['driver'],
            implode(', ', [...array_column($entry['databases'], 'database'), ...array_map(basename(...), array_column($entry['files'], 'path'))]) ?: '-',
        ], $prunable));

        $this->components->info('Nothing was changed. Run worktree:prune --force to stop these sites, drop these databases, and forget these worktrees.');
    }

    /**
     * Prunes one gone entry, unless it is not gone any more or another
     * worktree claims it.
     *
     * @return bool Whether it was pruned.
     */
    protected function pruneOne(string $path, string $source): bool
    {
        $registry = $this->registry($source);

        if ($registry === null) {
            return false;
        }

        $porcelain = $this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source);

        foreach ($this->goneEntries($registry, $porcelain) as $entry) {
            if (! Registry::samePath($entry['path'], $path)) {
                continue;
            }

            $claimants = $this->claimants($entry, $source, $registry, $porcelain);

            if ($claimants !== []) {
                $this->keepClaimed($entry, $claimants, $source, $registry, true);

                return false;
            }

            return $this->pruneEntry($entry, $source, $registry);
        }

        return false;
    }

    /**
     * Stops the site through the driver that served it, drops the databases
     * and files it was given (through the same server check and main
     * database guard as teardown, and nothing else: a lane its tests left is
     * not recorded, so --orphans lists it), and forgets it. One whose
     * database the main app or another worktree uses now, one a guard
     * refuses, and one whose server cannot be reached keep the record, so a
     * later run can look again.
     *
     * @param  Entry  $entry
     * @return bool Whether it was pruned.
     */
    protected function pruneEntry(array $entry, string $source, Registry $registry): bool
    {
        $databases = (bool) Arr::get($this->settings(), 'database.enabled', true);
        [$drops, $files] = $databases ? $this->recordedDrops($entry) : [[], []];

        if (! $this->unclaimed($entry, $drops, $source, $registry)) {
            return false;
        }

        $site = $this->recordedSiteSettings($entry);
        $driver = $this->siteDriver($site->driver());
        $runner = $this->runners[$site->driver()] ??= $this->siteRunner($site, $driver->timeoutHint());

        $driver->unserve($entry['name'], $entry['path'], $site->secure(), $runner);

        try {
            $dropped = $this->dropPlanned($drops, $files, $source, true);
        } catch (PDOException $exception) {
            $this->components->warn("Could not drop the databases of [{$entry['name']}] ({$exception->getMessage()}), so its record is kept for worktree:prune to try again.");

            return false;
        }

        if (! $dropped) {
            $this->components->warn("[{$entry['name']}] keeps its record, so worktree:prune can look at it again.");

            return false;
        }

        $this->updateRegistry($source, fn (Registry $registry) => $registry->forget($entry['path']));

        $this->components->info("Pruned [{$entry['name']}], which was at [{$entry['path']}].");

        return true;
    }

    /**
     * The worktrees git lists that may have taken a gone one's place, which
     * makes its site and databases theirs: one with its branch checked out
     * (git worktree move leaves the record at the old path), one under its
     * name, or one that records or names one of its databases. Any of them
     * keeps the whole entry: a database left behind is clutter, a dropped one
     * is lost.
     *
     * @param  Entry  $entry
     * @return list<array{path: string, branch: string|null}>
     */
    protected function claimants(array $entry, string $source, Registry $registry, string $porcelain): array
    {
        $slug = $this->slugOf($source, $entry['name']);
        $databases = array_map(mb_strtolower(...), array_column($entry['databases'], 'database'));

        return array_values(array_filter($this->liveWorktrees($source, $porcelain), function (array $worktree) use ($entry, $source, $registry, $slug, $databases): bool {
            if ($entry['branch'] !== null && $worktree['branch'] === $entry['branch']) {
                return true;
            }

            $record = $registry->find($this->canonical($worktree['path']));

            foreach (array_filter([$record['name'] ?? null, basename($worktree['path'])]) as $name) {
                if ($this->slugOf($source, $name) === $slug) {
                    return true;
                }
            }

            $theirs = array_map(mb_strtolower(...), array_column($record['databases'] ?? [], 'database'));

            return array_intersect($theirs, $databases) !== [] || $this->namesDatabaseOf($entry, $worktree['path']);
        }));
    }

    /**
     * Whether none of the databases about to be dropped is one the main app
     * or another worktree uses now: its .env, .env.testing or PHPUnit config
     * pointed at it since, or another record holds it. When that cannot be
     * told, nothing is dropped.
     *
     * @param  Entry  $entry
     * @param  array<string, array{database: string, manager: DatabaseManager}>  $drops
     */
    protected function unclaimed(array $entry, array $drops, string $source, Registry $registry): bool
    {
        if ($drops === []) {
            return true;
        }

        try {
            $claimed = $this->claimed($source, $registry, $entry['path'])['names'];
        } catch (Throwable $exception) {
            $this->components->warn("Could not tell which databases the main app and the other worktrees use ({$exception->getMessage()}), so [{$entry['name']}] keeps its record and its databases.");

            return false;
        }

        foreach (array_column($drops, 'database') as $database) {
            if (isset($claimed[mb_strtolower($database)])) {
                $this->components->warn("[{$database}] of [{$entry['name']}] is in use by the main app or another worktree now, so [{$entry['name']}] keeps its record and its databases.");

                return false;
            }
        }

        return true;
    }

    /**
     * A gone worktree that another one took the place of keeps its record.
     * When that is a single worktree with no record of its own whose .env or
     * PHPUnit files name the gone one's databases, it is the gone one moved
     * (git worktree move, or a folder renamed and repaired), so the record
     * follows it there and teardown finds it again. One that only has its
     * branch or its name may be a new checkout of the same branch, running
     * on other databases.
     *
     * @param  Entry  $entry
     * @param  list<array{path: string, branch: string|null}>  $claimants
     */
    protected function keepClaimed(array $entry, array $claimants, string $source, Registry $registry, bool $force): void
    {
        $moved = $this->canonical($claimants[0]['path']);

        if (count($claimants) > 1 || $registry->find($moved) !== null || ! $this->namesDatabaseOf($entry, $moved)) {
            $this->components->warn("[{$entry['path']}] is gone, but the worktree at [".implode('] and [', array_column($claimants, 'path')).'] has its branch, its name, or one of its databases, so its site and databases are left alone while that worktree is there.');

            return;
        }

        if (! $force) {
            $this->components->warn("[{$entry['name']}] seems to have moved from [{$entry['path']}] to [{$moved}]. worktree:prune --force records it there and leaves its site and databases alone.");

            return;
        }

        $this->updateRegistry($source, function (Registry $registry) use ($entry, $moved, $claimants): void {
            $registry->forget($entry['path']);
            $registry->put([...$entry, 'path' => $moved, 'branch' => $claimants[0]['branch']]);
        });

        $this->components->info("[{$entry['name']}] moved from [{$entry['path']}] to [{$moved}], so its record now follows it there.");
    }

    /**
     * Every worktree git lists with its directory in place, the main
     * repository left out.
     *
     * @return list<array{path: string, branch: string|null}>
     */
    protected function liveWorktrees(string $source, string $porcelain): array
    {
        return array_values(array_filter(
            WorktreeList::parse($porcelain),
            fn (array $worktree): bool => ! $this->samePath($worktree['path'], $source) && is_dir($worktree['path']),
        ));
    }

    /**
     * Lists the databases no worktree claims and, with --force, asks about
     * each by name, defaulting to keeping it: they are found by their names
     * alone, and another project on the same server may use one.
     */
    protected function sweepOrphans(string $source, bool $force): void
    {
        $orphans = $this->orphans($source);

        if ($orphans === null) {
            $this->components->warn('The worktree registry cannot be read now, so what the worktrees claim cannot be told and no database is looked for.');

            return;
        }

        if ($orphans === []) {
            $this->components->info('No database is named like a worktree of this repository without a worktree to claim it.');

            return;
        }

        $this->output->table(['Database', 'Server'], array_map(
            fn (array $orphan): array => [$orphan['database'], $orphan['manager']->dsn()],
            array_values($orphans),
        ));

        if (! $force) {
            $this->components->info('Nothing was dropped. Run worktree:prune --orphans --force to be asked about each one.');

            return;
        }

        // --force never answers for anyone, so a run nobody can answer drops
        // none of them.
        if (! $this->input->isInteractive()) {
            $this->components->warn('Nothing was dropped: each one is dropped only once you confirm it, and this run cannot ask.');

            return;
        }

        $confirmed = [];

        foreach ($orphans as $key => $orphan) {
            if (confirm(label: "Drop [{$orphan['database']}] on [{$orphan['manager']->dsn()}]?", default: false)) {
                $confirmed[$key] = $orphan;
            }
        }

        if ($confirmed === []) {
            return;
        }

        // Looked for again under the lock, since a setup run while the
        // questions were open may have made one of them its own.
        $this->locked($source, function () use ($source, $confirmed): void {
            $orphans = $this->orphans($source, true);

            if ($orphans === null) {
                $this->components->warn('Nothing was dropped: the worktree registry cannot be read now, so what the worktrees claim cannot be told.');

                return;
            }

            foreach ($confirmed as $key => $orphan) {
                if (! isset($orphans[$key])) {
                    $this->components->warn("Not dropping [{$orphan['database']}]: a worktree claims it now.");

                    continue;
                }

                try {
                    $orphan['manager']->drop($orphan['database']);
                } catch (PDOException $exception) {
                    $this->components->warn("Could not drop [{$orphan['database']}]: {$exception->getMessage()}");

                    continue;
                }

                $this->components->info("Dropped [{$orphan['database']}].");
            }
        });
    }

    /**
     * The databases on the configured servers named like a worktree of this
     * repository that nothing claims, keyed by server and name. Null when
     * the registry cannot be read, since then nothing can be told unclaimed.
     *
     * @param  bool  $quiet  Whether to leave out the warnings a first look already gave.
     * @return array<string, array{database: string, manager: DatabaseManager}>|null
     */
    protected function orphans(string $source, bool $quiet = false): ?array
    {
        $registry = $this->registry($source, true);

        if ($registry === null) {
            return null;
        }

        $claimed = $this->claimed($source, $registry);
        $orphans = [];

        foreach ($this->namePatterns($source, $quiet) as $pattern) {
            try {
                $databases = $pattern['manager']->named($pattern['prefix']);
            } catch (PDOException $exception) {
                if (! $quiet) {
                    $this->components->warn("Could not list the databases on [{$pattern['manager']->dsn()}]: {$exception->getMessage()}");
                }

                continue;
            }

            foreach ($databases as $database) {
                if ($this->matches($database, $pattern['prefix'], $pattern['suffix']) && ! $this->isClaimed($database, $claimed)) {
                    $this->collectDrop($orphans, $database, $pattern['manager']);
                }
            }
        }

        return $orphans;
    }

    /**
     * What this repository's worktree databases are named like on each
     * server they are made on: every name template, application and test,
     * expanded for a marker name and split around it, so "{slug}_testing"
     * gives "blog_" and "_testing" (a name over the server's limit loses its
     * end, which matches() allows for). A template whose fixed start does not
     * include the repository's name would also match other projects'
     * databases, so it is not looked for, and neither is anything when a
     * worktree name, or the fixed start itself, is too long to keep whole.
     *
     * @param  bool  $quiet  Whether to leave out the warnings a first look already gave.
     * @return list<array{manager: DatabaseManager, prefix: string, suffix: string}>
     */
    protected function namePatterns(string $source, bool $quiet = false): array
    {
        $worktree = Worktree::make($source, $this->marker, $this->settings());
        $warn = function (string $message) use ($quiet): void {
            if (! $quiet) {
                $this->components->warn($message);
            }
        };

        if ($worktree->name() !== $worktree->uncappedName()) {
            $warn('Worktree names in this repository are cut short to fit a host, so its databases cannot be told apart by name and are not looked for.');

            return [];
        }

        $repository = '/(?:^|_)'.preg_quote($this->slugOf($source, $worktree->repository()), '/').'(?:_|$)/';
        $patterns = [];

        foreach ($this->databaseConnections() as $entry) {
            $templates = [[$entry['connection'], $entry['name']]];

            foreach ($entry['test'] === null ? [] : $this->testConnectionsFor($entry, $source) as $connection) {
                $templates[] = [$connection, $entry['test']['name']];
            }

            foreach ($templates as [$connection, $template]) {
                $manager = $this->databases($connection);
                $name = $worktree->expand($template);
                $at = mb_strpos($name, $this->marker);

                // A fixed name, which no worktree has to itself.
                if (! $manager->isServer() || $at === false) {
                    continue;
                }

                $prefix = mb_substr($name, 0, $at);

                // Longer than what survives the cut to the server's limit,
                // so no name starts with all of it.
                if (mb_strlen($prefix) > 56) {
                    $warn("Databases named by the template [{$template}] are cut short to fit the server's limit, so they cannot be told apart by name and are not looked for.");

                    continue;
                }

                if (preg_match($repository, $this->slugOf($source, $prefix)) !== 1) {
                    $warn("Databases named by the template [{$template}] do not include the repository's name before the worktree's, so they cannot be told apart from other projects' and are not looked for.");

                    continue;
                }

                $suffix = mb_substr($name, $at + mb_strlen($this->marker));
                $patterns[$manager->dsn().'|'.$prefix.'|'.$suffix] = ['manager' => $manager, 'prefix' => $prefix, 'suffix' => $suffix];
            }
        }

        return array_values($patterns);
    }

    /**
     * A name past the prefix that ends in the suffix. A name over the
     * server's limit was cut and given a hash, which also cut the suffix, so
     * one of exactly that length ending in a hash counts too.
     */
    protected function matches(string $database, string $prefix, string $suffix): bool
    {
        $database = mb_strtolower($database);
        $prefix = mb_strtolower($prefix);
        $suffix = mb_strtolower($suffix);

        if (! str_starts_with($database, $prefix)) {
            return false;
        }

        if (mb_strlen($database) > mb_strlen($prefix.$suffix) && str_ends_with($database, $suffix)) {
            return true;
        }

        return mb_strlen($database) === 63 && preg_match('/_[0-9a-f]{6}$/', $database) === 1;
    }

    /**
     * A database something claims by name, a parallel-test database derived
     * from one of those, or one under a worktree's slug, whichever template
     * named it.
     *
     * @param  array{names: array<string, true>, slugs: list<string>}  $claimed
     */
    protected function isClaimed(string $database, array $claimed): bool
    {
        $database = mb_strtolower($database);

        if (isset($claimed['names'][$database])) {
            return true;
        }

        // A name of digits alone is an integer key by now.
        foreach (array_keys($claimed['names']) as $name) {
            if (DatabaseManager::isDerivative($database, (string) $name)) {
                return true;
            }
        }

        foreach ($claimed['slugs'] as $slug) {
            if ($database === $slug || str_starts_with($database, $slug.'_')) {
                return true;
            }
        }

        return false;
    }
}
