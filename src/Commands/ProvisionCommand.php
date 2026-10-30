<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Drivers\Driver;
use Mozex\Worktree\Enums\MigrateMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseCloner;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\SiteList;
use Mozex\Worktree\Support\SiteSettings;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;
use Throwable;

/**
 * What worktree:setup and worktree:adopt share once a worktree is named: the
 * guards, recording it, its site, .env, dependencies, databases, steps and
 * the summary. Only how the worktree comes to exist differs.
 *
 * @phpstan-import-type Database from Registry
 * @phpstan-import-type DatabaseFile from Registry
 */
abstract class ProvisionCommand extends WorktreeCommand
{
    /**
     * What each connection entry's database was cloned from, keyed by the
     * entry's index: a database name on a server, a path for a SQLite file.
     *
     * @var array<int, string>
     */
    protected array $cloned = [];

    /**
     * What the registry records as having made the worktree.
     */
    protected string $tool = 'setup';

    /**
     * Everything after the worktree is named: the guards, making and
     * recording the worktree, its site, .env, dependencies, databases and
     * steps, and the summary.
     *
     * @param  string|null  $branch  The branch checked out there, recorded and shown; null for a detached HEAD.
     * @param  Closure(): bool  $create  Makes the worktree when it has to, and says whether it was already there.
     */
    protected function provision(Worktree $worktree, ?string $branch, Closure $create): void
    {
        // The console keeps one instance of a command, so a run must not see
        // what an earlier run in the same process cloned.
        $this->cloned = [];

        $source = $worktree->sourcePath();
        $site = $this->siteSettings();
        $driver = $this->siteDriver($site->driver());
        $url = $driver->url($worktree, $site->secure());

        if ($this->databaseEnabled()) {
            $this->guardDuplicateDatabases($worktree);
            $this->guardSourceDatabases($worktree);
        }

        foreach ($site->warnings() as $warning) {
            $this->display()->warn($warning);
        }

        $this->warnAboutLerd($worktree, $site);

        // Under one lock, so a second command cannot slip in between the
        // checks for an existing worktree or a taken name and the worktree
        // being made and recorded.
        $this->locked($source, function () use ($worktree, $branch, $site, $url, $create): void {
            $this->guardNameTaken($worktree);
            $this->recordWorktree($worktree, $branch, $site, $url, $create());
        });

        $this->removeSharedLinks($worktree);

        // Served before the .env is written, which is copied fresh from the
        // main repository on every run, so anything the server writes into
        // the worktree's .env while linking never survives.
        $served = $this->locked($source, fn (): bool => $driver->serve($worktree, $site->secure(), $this->siteRunner($site, $driver->timeoutHint())));

        $this->prepareEnvironment($worktree, $url);
        $this->copyExtraEnvironmentFiles($worktree);

        $this->provisionDependencies($worktree);

        $this->prepareDatabase($worktree);
        $this->runSteps($worktree);

        // APP_URL keeps the address a site that failed to serve would have, so
        // serving it by hand later needs no .env change, but the summary only
        // shows an address that answers.
        $this->summary($worktree, $branch, $served ? $url : null);
    }

    /**
     * Two worktrees under one name would share its site and every database
     * named after it, and the second one's migrate:fresh or clone would wipe
     * the first one's data. Setup names each directory after its worktree, so
     * its own worktrees cannot collide, but an adopted worktree sits wherever
     * its tool put it, under a name that may already be taken.
     */
    protected function guardNameTaken(Worktree $worktree): void
    {
        $source = $worktree->sourcePath();

        // The main site goes by the repository's name, so a worktree named
        // like it would be linked over it.
        if ($this->sharesDatabases($worktree, $worktree->repository())) {
            throw WorktreeException::nameTaken($worktree->name(), $worktree->repository(), $source);
        }

        $registry = $this->registry($source);

        foreach (WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $source)) as $entry) {
            if ($this->samePath($entry['path'], $source) || $this->samePath($entry['path'], $worktree->path())) {
                continue;
            }

            // An unrecorded worktree goes by its directory, which is what
            // setup names it after.
            $name = $registry?->find($this->canonical($entry['path']))['name'] ?? basename($entry['path']);

            if ($this->sharesDatabases($worktree, $name)) {
                throw WorktreeException::nameTaken($worktree->name(), $name, $entry['path']);
            }
        }
    }

    /**
     * Database names come from the slug, which lowercases the name and
     * collapses everything but letters and digits, so "blog-feature-login"
     * and "blog-feature_login" name the same databases.
     */
    protected function sharesDatabases(Worktree $worktree, string $name): bool
    {
        return $worktree->withName($name)->slug() === $worktree->slug();
    }

    /**
     * A tool may link files in its worktree back to the main checkout's
     * (Conductor's docs suggest linking .env), and every write through such
     * a link lands in the main app: its .env rewritten by this command and by
     * a server linking the site, its SQLite database migrated fresh, its
     * PHPUnit file patched, its vendor or node_modules installed over. Each
     * such link is removed, never what it points at, so the worktree gets its
     * own. Only what this run will write is looked at.
     */
    protected function removeSharedLinks(Worktree $worktree): void
    {
        // Each path, with whether the worktree keeps the linked contents: a
        // PHPUnit file is patched in place, not copied in fresh.
        $paths = [$worktree->path().'/.env' => false];

        if (! $this->option('no-install')) {
            foreach ($this->dependencies() as $entry) {
                if (File::exists($worktree->path().'/'.$entry['manifest'])) {
                    $paths[$worktree->path().'/'.$entry['path']] = false;
                }
            }
        }

        if ($this->databaseEnabled()) {
            foreach ($this->databaseConnections() as $entry) {
                $databases = $this->databases($entry['connection']);
                $configured = $databases->database();

                if (! $databases->isFile() || $configured === '' || $configured === ':memory:') {
                    continue;
                }

                // A file outside the repository is not in the worktree.
                $mapped = $worktree->mapPath($this->resolveFile($worktree, $configured));

                if ($mapped !== null) {
                    $paths[$mapped] = false;
                }
            }

            foreach ($this->phpunitFiles($worktree->path()) as $file) {
                $paths[$worktree->path().'/'.$file] = true;
            }
        }

        foreach ($paths as $path => $keep) {
            if (! $this->isShared($worktree, $path)) {
                continue;
            }

            $label = $this->fileLabel($worktree, $path);
            $contents = $keep ? (string) @file_get_contents($path) : null;

            if (! Directory::removeLink($path)) {
                throw WorktreeException::linkedFile($label);
            }

            if ($contents !== null) {
                File::put($path, $contents);
            }

            $this->display()->warn("[{$label}] in the worktree was a link, and writing to it would have changed what it points at, so the worktree gets its own instead.");
        }
    }

    /**
     * Whether writing to a path in the worktree would change a file outside
     * it: a link to anything outside, or a hard link, which is the same file
     * under a second name. A link within the worktree (a committed relative
     * symlink) is the worktree's own.
     */
    protected function isShared(Worktree $worktree, string $path): bool
    {
        if (Directory::isLink($path)) {
            $target = realpath($path);

            return $target === false
                || ! str_starts_with(mb_strtolower($this->canonical($target)).'/', mb_strtolower($this->canonical($worktree->path())).'/');
        }

        $stat = is_file($path) ? @stat($path) : false;

        return $stat !== false && $stat['nlink'] > 1;
    }

    /**
     * Lerd sets up every new git worktree of a site it serves on its own
     * (its .env, dependencies, and vhost), so with this repository among its
     * sites the two would provision the same worktree at once.
     */
    protected function warnAboutLerd(Worktree $worktree, SiteSettings $site): void
    {
        $binary = Arr::get($this->settings(), 'site.lerd.binary', 'lerd');

        if (! is_string($binary) || $binary === '' || ! Driver::executable($binary)) {
            return;
        }

        if (! $this->lerdServes($binary, $worktree, $site)) {
            return;
        }

        $this->display()->warn('Lerd seems to serve this repository, and it sets up every new git worktree of a site it serves by itself, so Lerd and this package would both provision this worktree at once. Let one of them set worktrees up; set worktree.site.lerd.binary to null to stop this check.');
    }

    /**
     * A .lerd.yaml says so without asking Lerd; otherwise Lerd's site list is
     * read for the repository's name or host. Lerd cuts a long name or domain
     * short with "...", and prints its paths shortened with "~", so the path
     * is not compared.
     */
    protected function lerdServes(string $binary, Worktree $worktree, SiteSettings $site): bool
    {
        if (File::exists($worktree->sourcePath().'/.lerd.yaml')) {
            return true;
        }

        $sites = $this->siteRunner($site)->run([$binary, 'sites']);

        if ($sites === null || $sites->failed()) {
            return false;
        }

        return SiteList::hasShortenedCell($sites->output(), $worktree->repository())
            || SiteList::hasShortenedCell($sites->output(), $worktree->sourceHost());
    }

    /**
     * Records the worktree as soon as git has made it, so one whose setup
     * fails later still has an entry teardown can clean up after. The
     * databases are the ones setup is about to provision: creating one can
     * still fail, and dropping one that was never created is harmless.
     *
     * A worktree an earlier release made has no entry yet, and its databases
     * were made by that release, so resuming it records them even when this
     * run skips databases: otherwise teardown would trust an entry naming
     * none of them and leave them all behind.
     */
    protected function recordWorktree(Worktree $worktree, ?string $branch, SiteSettings $site, ?string $url, bool $resumed): void
    {
        $path = $this->canonical($worktree->path());

        $this->updateRegistry($worktree->sourcePath(), function (Registry $registry) use ($worktree, $branch, $site, $url, $resumed, $path): void {
            $databases = $this->databaseEnabled()
                || ($resumed && $registry->find($path) === null && (bool) Arr::get($this->settings(), 'database.enabled', true));

            $registry->put([
                'path' => $path,
                'name' => $worktree->name(),
                'branch' => $branch,
                'tool' => $this->tool,
                'driver' => $site->driver(),
                'secure' => $site->secure(),
                'host' => $worktree->host(),
                'url' => $url,
                'databases' => $databases ? $this->plannedDatabases($worktree) : [],
                'files' => $databases ? $this->plannedFiles($worktree) : [],
                'created' => Carbon::now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Every server database setup provisions for the worktree, application
     * and test, with the server it lands on. A test database is made only for
     * the PHPUnit files the worktree has, on each file's own connection.
     *
     * @return list<Database>
     */
    protected function plannedDatabases(Worktree $worktree): array
    {
        $planned = [];

        foreach ($this->databaseConnections() as $entry) {
            $connection = $this->connectionName($entry['connection']);
            $databases = $this->databases($connection);

            if ($databases->isServer()) {
                $planned[] = ['connection' => $connection, 'database' => $worktree->database($entry['name']), 'dsn' => $databases->dsn(), 'test' => false];
            }

            if ($entry['test'] === null) {
                continue;
            }

            foreach ($this->phpunitFiles($worktree->path()) as $file) {
                $connection = $this->connectionName($this->testConnectionFor($entry, $worktree->path(), $file));
                $databases = $this->databases($connection);

                if ($databases->isServer()) {
                    $planned[] = ['connection' => $connection, 'database' => $worktree->database($entry['test']['name']), 'dsn' => $databases->dsn(), 'test' => true];
                }
            }
        }

        return $planned;
    }

    /**
     * The SQLite files setup places next to a main file outside the
     * repository, each with that main file. A file inside the worktree goes
     * with it, so it is not recorded.
     *
     * @return list<DatabaseFile>
     */
    protected function plannedFiles(Worktree $worktree): array
    {
        $planned = [];

        foreach ($this->databaseConnections() as $entry) {
            $databases = $this->databases($entry['connection']);

            if ($databases->isFile() && $this->isOutsideFile($worktree, $databases->database())) {
                $planned[] = [
                    'path' => $this->siblingDatabaseFile($worktree, $databases->database(), $entry['name']),
                    'source' => $this->resolveFile($worktree, $databases->database()),
                ];
            }
        }

        return $planned;
    }

    /**
     * @param  string|null  $url  Where the driver serves the worktree; null when nothing serves it.
     */
    protected function prepareEnvironment(Worktree $worktree, ?string $url): void
    {
        $source = $worktree->sourcePath().'/'.(string) Arr::get($this->settings(), 'env.source', '.env');
        $target = $worktree->path().'/.env';

        if (! File::exists($source)) {
            $this->display()->warn("No env file at [{$source}]; skipping environment setup.");

            return;
        }

        File::copy($source, $target);

        $env = EnvFile::fromFile($target);

        if ($this->databaseEnabled()) {
            $this->applyDatabaseEnv($env, $worktree);
        }

        // With nothing serving the worktree, APP_URL still names its host over
        // http, for a server set up some other way. An empty key leaves it be.
        $key = (string) Arr::get($this->settings(), 'env.app_url_key', 'APP_URL');

        if ($key !== '') {
            $env->set($key, $url ?? 'http://'.$worktree->host());
        }

        if ((bool) Arr::get($this->settings(), 'host.remap_source_host', true)) {
            $env->remapHost($worktree->sourceHost(), $worktree->host());
        }

        $this->applyEnvReplacements($env, $worktree);

        $env->save($target);
    }

    /**
     * Per-worktree value rewrites from config: each listed key's value is set to
     * its template with the worktree tokens expanded, and {value} standing for
     * the key's current value so a prefix can be appended without restating it. A
     * listed key the env file does not define is added. This is how a project
     * isolates values the package knows nothing about (a Redis prefix, a cache
     * prefix, a queue name) without a hardcoded handler for each one. It reads
     * the value the source file holds, not one it just wrote, so re-running is
     * idempotent: prepareEnvironment() re-copies the .env every time, and the
     * extra files are only ever copied once.
     */
    protected function applyEnvReplacements(EnvFile $env, Worktree $worktree): void
    {
        /** @var array<string, string> $replacements */
        $replacements = Arr::get($this->settings(), 'env.replace', []);

        foreach ($replacements as $key => $template) {
            $env->set($key, $worktree->expand($template, ['value' => (string) $env->get($key)]));
        }
    }

    /**
     * Gitignored env files beyond the main one (.env.testing is the usual case)
     * never arrive through git, so the suite would boot without one. They are
     * copied as they are, apart from the host remap and the configured value
     * rewrites; database isolation for the suite is phpunit.xml's job, whose
     * values outrank an env file anyway.
     */
    protected function copyExtraEnvironmentFiles(Worktree $worktree): void
    {
        /** @var array<int, string> $files */
        $files = Arr::get($this->settings(), 'env.copy', []);

        foreach ($files as $file) {
            $source = $worktree->sourcePath().'/'.$file;
            $target = $worktree->path().'/'.$file;

            // A file git already put there is tracked and not this command's to touch.
            if (! File::exists($source) || File::exists($target)) {
                continue;
            }

            // A copy that is not gitignored would sit in the worktree as an
            // untracked file: merge teardowns would refuse to run and --pr
            // would commit local env values into the branch.
            if (! $this->attempt(['git', 'check-ignore', '-q', $file], $worktree->sourcePath())) {
                $this->display()->warn("Not copying [{$file}]; it is not gitignored, so the copy would dirty the worktree.");

                continue;
            }

            File::copy($source, $target);

            $env = EnvFile::fromFile($target);

            if ((bool) Arr::get($this->settings(), 'host.remap_source_host', true)) {
                $env->remapHost($worktree->sourceHost(), $worktree->host());
            }

            $this->applyEnvReplacements($env, $worktree);
            $env->save($target);
        }
    }

    protected function applyDatabaseEnv(EnvFile $env, Worktree $worktree): void
    {
        foreach ($this->databaseConnections() as $entry) {
            $this->applyConnectionEnv($env, $worktree, $entry);
        }
    }

    /**
     * A server database is shared between worktrees, so the worktree points its
     * env key at one of its own. A file database already lives inside the
     * worktree, so the value only needs redirecting when it is an absolute path
     * back into the source.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function applyConnectionEnv(EnvFile $env, Worktree $worktree, array $entry): void
    {
        $databases = $this->databases($entry['connection']);

        if ($databases->isServer()) {
            $env->set($entry['env'], $worktree->database($entry['name']));

            return;
        }

        if (! $databases->isFile()) {
            return;
        }

        // The .env value wins; with none, the app's config decides where the file is.
        $current = (string) $env->get($entry['env']);
        $configured = $current !== '' ? $current : $databases->database();

        if ($configured === '' || $configured === ':memory:') {
            return;
        }

        // A file outside the repository always gets the worktree's own file.
        // Inside it, a relative path or an unset key (stock Laravel's
        // database_path()) already resolves to the worktree's copy when run
        // from there; only an absolute path back into the source needs moving.
        if (! $this->isOutsideFile($worktree, $configured) && ($current === '' || ! $worktree->isAbsolute($configured))) {
            return;
        }

        $env->set($entry['env'], (string) $this->databaseFile($worktree, $configured, $entry['name']));
    }

    protected function prepareDatabase(Worktree $worktree): void
    {
        if (! $this->databaseEnabled()) {
            return;
        }

        foreach ($this->databaseConnections() as $index => $entry) {
            $this->createConnectionDatabase($worktree, $entry, $index);
        }

        $this->prepareTestDatabases($worktree);
        $this->migrate($worktree);
    }

    /**
     * A server connection gets a named database of its own; a file (SQLite)
     * connection gets its file created inside the worktree. With cloning on,
     * either one starts as a copy of the main repository's database instead,
     * unless there is nothing to copy, in which case it starts empty.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function createConnectionDatabase(Worktree $worktree, array $entry, int $index): void
    {
        $databases = $this->databases($entry['connection']);

        if ($databases->isServer()) {
            if ($this->cloning() && $this->cloneServerDatabase($worktree, $entry, $databases, $index)) {
                return;
            }

            $databases->create($worktree->database($entry['name']));

            return;
        }

        if ($databases->isFile()) {
            if ($this->cloning() && $this->cloneDatabaseFile($worktree, $entry, $databases, $index)) {
                return;
            }

            $this->createDatabaseFile($worktree, $entry);

            return;
        }

        $this->display()->warn('Database driver ['.$databases->driver().'] on connection ['.($entry['connection'] ?? 'default').'] is not supported; skipping database creation.');
    }

    /**
     * Copies the main repository's database into the worktree's. Returns false
     * when the source does not exist, so the caller creates an empty one.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function cloneServerDatabase(Worktree $worktree, array $entry, DatabaseManager $databases, int $index): bool
    {
        $source = $databases->database();
        $target = $worktree->database($entry['name']);

        if ($source === '' || ! $databases->exists($source)) {
            $this->display()->warn("Nothing to clone: the database [{$source}] does not exist. The worktree starts from an empty database.");

            return false;
        }

        // Checked again right before the drop, the one destructive step setup
        // takes, even though the same guard already ran before the worktree
        // was created.
        $this->guardSourceDatabase($worktree, $target);

        $this->display()->info("Cloning [{$source}] into [{$target}].");

        // A resumed setup finds the copy it made last time. It starts over, so
        // the data matches the main database again, as migrate:fresh would.
        $databases->drop($target);

        $cloner = $this->cloner($databases);

        if (! $cloner->cloneServer($source, $target)) {
            $this->cloneWithDump($databases, $cloner, $source, $target);
        }

        $this->cloned[$index] = $source;

        return true;
    }

    /**
     * Postgres copies a database as a template only while nobody else is
     * connected to it, and a running queue worker or an open database GUI is
     * enough to stop that. pg_dump reads straight through those sessions, so
     * the copy goes through a dump file instead. No session on the main
     * database is ever terminated to make the template copy work.
     */
    protected function cloneWithDump(DatabaseManager $databases, DatabaseCloner $cloner, string $source, string $target): void
    {
        if (! $this->hasTool('pg_dump') || ! $this->hasTool('pg_restore')) {
            throw WorktreeException::cloneSourceBusy($source);
        }

        $this->display()->info("Other sessions are connected to [{$source}], so it is copied with pg_dump instead.");

        $file = (string) tempnam(sys_get_temp_dir(), 'worktree-');

        try {
            $this->process($databases->dumpCommand($source, $file), null, $databases->toolEnvironment());
            $databases->create($target);
            $this->process($databases->restoreCommand($target, $file), null, $databases->toolEnvironment());
        } finally {
            File::delete($file);
        }

        $cloner->emptyStructureOnlyTables($target);
    }

    /**
     * Whether a command-line tool answers at all. A missing binary fails the
     * process on Windows but can fail to launch elsewhere, so both count.
     */
    protected function hasTool(string $tool): bool
    {
        try {
            return $this->attempt([$tool, '--version']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Copies the main repository's SQLite file into the worktree's. Returns
     * false when there is no file to copy (an in-memory database, or one not
     * created yet), so the caller falls back to an empty file.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function cloneDatabaseFile(Worktree $worktree, array $entry, DatabaseManager $databases, int $index): bool
    {
        $configured = $databases->database();
        $target = $this->databaseFile($worktree, $configured, $entry['name']);

        if ($target === null) {
            return false;
        }

        $source = $this->resolveFile($worktree, $configured);

        if (! File::exists($source)) {
            return false;
        }

        // Checked again right before the delete below, the same way a server
        // database is guarded before its drop.
        $this->guardSourceFile($source, $target);

        $label = $this->fileLabel($worktree, $target);

        $this->display()->info("Cloning [{$label}] from the main repository.");

        // A resumed setup finds last run's copy (and maybe its journal), and
        // VACUUM INTO only writes to a missing or empty file.
        File::delete([$target, $target.'-wal', $target.'-shm', $target.'-journal']);
        File::ensureDirectoryExists(dirname($target));

        $this->cloner($databases)->cloneFile($source, $target);

        $this->cloned[$index] = $label;

        return true;
    }

    protected function cloner(DatabaseManager $databases): DatabaseCloner
    {
        /** @var array<int, string> $tables */
        $tables = Arr::get($this->settings(), 'database.clone.structure_only', []);

        return new DatabaseCloner($databases, array_values(array_map(strval(...), $tables)));
    }

    /**
     * --no-clone wins over everything, then --clone, then the config.
     */
    protected function cloning(): bool
    {
        if ($this->option('no-clone')) {
            return false;
        }

        return (bool) $this->option('clone') || (bool) Arr::get($this->settings(), 'database.clone.enabled', false);
    }

    /**
     * A worktree database named exactly like the main repository's own (a
     * name template with no worktree token in it) would be migrated fresh or
     * cloned over with the main data in it, so it is refused before anything
     * is created. Test databases are checked too: a suite pointed at the main
     * database would wipe it on its first RefreshDatabase.
     */
    protected function guardSourceDatabases(Worktree $worktree): void
    {
        foreach ($this->databaseConnections() as $entry) {
            $databases = $this->databases($entry['connection']);

            if ($databases->isServer()) {
                $this->guardSourceDatabase($worktree, $worktree->database($entry['name']));
            }

            if ($databases->isFile() && $this->isOutsideFile($worktree, $databases->database())) {
                $this->guardSourceFile($this->resolveFile($worktree, $databases->database()), $this->siblingDatabaseFile($worktree, $databases->database(), $entry['name']));
            }

            if ($entry['test'] !== null) {
                $this->guardSourceDatabase($worktree, $worktree->database($entry['test']['name']));
            }
        }
    }

    protected function guardSourceDatabase(Worktree $worktree, string $name): void
    {
        if ($this->isSourceDatabase($worktree->sourcePath(), $name)) {
            throw WorktreeException::sourceDatabase($name);
        }
    }

    /**
     * A worktree's SQLite file next to a main database outside the repository
     * is named by the connection's template, and a template without a worktree
     * token would name the main file itself.
     */
    protected function guardSourceFile(string $source, string $target): void
    {
        if ($this->isSameFile($source, $target)) {
            throw WorktreeException::sourceDatabase($target);
        }
    }

    /**
     * Two connections resolving to the same database name on the same server
     * would clobber one another, so this is caught before any worktree is made.
     */
    protected function guardDuplicateDatabases(Worktree $worktree): void
    {
        $seen = [];

        foreach ($this->databaseConnections() as $entry) {
            $named = $this->namedDatabase($worktree, $entry);

            if ($named === null) {
                continue;
            }

            [$name, $signature] = $named;

            if (isset($seen[$signature])) {
                throw WorktreeException::duplicateDatabase($name);
            }

            $seen[$signature] = true;
        }
    }

    /**
     * The database a connection entry gets a name of its own for, and where
     * that name lives: a server database, or the worktree's file next to a
     * SQLite database outside the repository. Two SQLite files in one
     * directory get their worktree files from the same templates, so they can
     * land on one file just as two server databases can land on one name.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     * @return array{0: string, 1: string}|null The name and a signature unique to where it lives.
     */
    protected function namedDatabase(Worktree $worktree, array $entry): ?array
    {
        $databases = $this->databases($entry['connection']);

        if ($databases->isServer()) {
            $name = $worktree->database($entry['name']);

            return [$name, $databases->dsn().'|'.$name];
        }

        if ($databases->isFile() && $this->isOutsideFile($worktree, $databases->database())) {
            $name = $this->siblingDatabaseFile($worktree, $databases->database(), $entry['name']);

            return [$name, 'file|'.mb_strtolower($name)];
        }

        return null;
    }

    /**
     * Laravel gitignores the SQLite file (database/.gitignore holds *.sqlite*), so
     * a fresh worktree never receives one from git and migrating would fail without
     * this. Creating it per worktree is exactly the isolation this package is after.
     */
    /**
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function createDatabaseFile(Worktree $worktree, array $entry): void
    {
        $path = $this->databaseFile($worktree, $this->databases($entry['connection'])->database(), $entry['name']);

        if ($path === null || File::exists($path)) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
    }

    /**
     * A worktree's SQLite file named relative to the worktree, or in full when
     * it sits next to a main database outside the repository.
     */
    protected function fileLabel(Worktree $worktree, string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $worktree->path().'/') ? mb_substr($path, mb_strlen($worktree->path()) + 1) : $path;
    }

    /**
     * Creates a test database for every connection that asks for one and whose
     * suite runs against a server, then writes each name into every configured
     * PHPUnit file. A SQLite test database is in memory or a file inside the
     * worktree, so it is already isolated and is left alone.
     */
    protected function prepareTestDatabases(Worktree $worktree): void
    {
        foreach ($this->phpunitFiles($worktree->path()) as $file) {
            $this->prepareTestDatabasesFor($worktree, $file);
        }
    }

    /**
     * The same names written into one PHPUnit file. Two files sharing a test
     * database name provision it once: creation is a no-op when it already
     * exists, and the suites are separate runs.
     */
    protected function prepareTestDatabasesFor(Worktree $worktree, string $file): void
    {
        $path = $worktree->path().'/'.$file;
        $config = PhpunitConfig::fromFile($path);
        $patched = false;

        foreach ($this->databaseConnections() as $entry) {
            if ($entry['test'] === null) {
                continue;
            }

            // The suite may run a connection on a different server than the app
            // (the file's DB_CONNECTION), and that is where the database lands.
            // Read off the open document rather than through testConnectionFor(),
            // which would re-parse this same file once per entry.
            $databases = $this->databases($entry['connection'] ?? $config->env('DB_CONNECTION'));

            if (! $databases->isServer()) {
                continue;
            }

            $name = $worktree->database($entry['test']['name']);
            $databases->create($name);
            $config->setEnv($entry['test']['env'], $name);
            $patched = true;
        }

        if (! $patched) {
            return;
        }

        $config->save($path);

        // A stock Laravel phpunit.xml is tracked, so this edit would leave the
        // worktree permanently dirty: teardown --into would refuse to run, and
        // --pr would commit the local test database name into the branch.
        $this->attempt(['git', 'update-index', '--skip-worktree', $file], $worktree->path());
    }

    protected function migrate(Worktree $worktree): void
    {
        if ($this->option('no-migrate')) {
            return;
        }

        // Artisan cannot boot without the worktree's own vendor directory.
        if ($this->option('no-install')) {
            $this->display()->warn('Skipping migrations because --no-install was passed.');

            return;
        }

        $mode = MigrateMode::tryFrom((string) Arr::get($this->settings(), 'database.migrate', MigrateMode::Fresh->value)) ?? MigrateMode::Fresh;

        // A fresh migration would wipe the data just cloned. A clone migrates
        // forward instead, running only the branch's own new migrations on top
        // of the main repository's data.
        $cloned = $this->clonedDefaultConnection();

        if ($mode === MigrateMode::Fresh && $cloned) {
            $mode = MigrateMode::Migrate;
        }

        $command = $mode->command();

        if ($command === null) {
            return;
        }

        $arguments = ['php', 'artisan', $command, '--force'];

        // The configured default seeds an empty database. A clone already holds
        // data that seeding again would duplicate, so only --seed applies to one.
        if ($this->option('seed') || (! $cloned && (bool) Arr::get($this->settings(), 'database.seed', false))) {
            $arguments[] = '--seed';
        }

        $this->process($arguments, $worktree->path());
    }

    /**
     * Whether the default connection's database was cloned, which is the one
     * migrate and --seed act on. Another connection being cloned says nothing
     * about it: the default may have had no main database to copy.
     */
    protected function clonedDefaultConnection(): bool
    {
        $default = (string) Config::get('database.default');

        foreach ($this->databaseConnections() as $index => $entry) {
            if (isset($this->cloned[$index]) && ($entry['connection'] === null || $entry['connection'] === $default)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provisions each configured dependency directory before the steps run. With
     * "copy" on and the worktree's lock file matching the main repository's, the
     * directory is copied and its install is skipped; otherwise it installs.
     */
    protected function provisionDependencies(Worktree $worktree): void
    {
        if ($this->option('no-install')) {
            return;
        }

        foreach ($this->dependencies() as $name => $entry) {
            $this->provisionDependency($worktree, $name, $entry);
        }
    }

    /**
     * @param  array{copy: bool, path: string, manifest: string, lock: string, install: string}  $entry
     */
    protected function provisionDependency(Worktree $worktree, string $name, array $entry): void
    {
        // No manifest, no dependency: an app without a package.json never runs npm.
        if (! File::exists($worktree->path().'/'.$entry['manifest'])) {
            return;
        }

        if ($entry['copy'] && $this->copyDependency($worktree, $name, $entry)) {
            return;
        }

        if ($entry['install'] !== '') {
            $this->process($entry['install'], $worktree->path());
        }
    }

    /**
     * Copies the dependency directory from the main repository when it is safe:
     * the source exists and the worktree's lock file is byte-for-byte the main
     * repository's, so the copied tree is exactly what a fresh install would
     * produce. Returns false to fall back to installing.
     *
     * @param  array{copy: bool, path: string, manifest: string, lock: string, install: string}  $entry
     */
    protected function copyDependency(Worktree $worktree, string $name, array $entry): bool
    {
        $source = $worktree->sourcePath().'/'.$entry['path'];

        if (! is_dir($source)) {
            return false;
        }

        if (! $this->locksMatch($worktree, $entry['lock'])) {
            $this->display()->info("Lock file [{$entry['lock']}] differs from the main repository; installing [{$name}] fresh.");

            return false;
        }

        $target = $worktree->path().'/'.$entry['path'];

        // A resumed setup may already hold the copy.
        if (File::exists($target)) {
            return true;
        }

        $this->display()->info("Copying [{$name}] from the main repository.");

        if ($this->copyDirectory($source, $target)) {
            return true;
        }

        $this->display()->warn("Copying [{$name}] failed; installing instead.");

        return false;
    }

    /**
     * Whether the worktree's lock file is identical to the main repository's, so
     * the main repository's installed directory is exactly right for the branch.
     */
    protected function locksMatch(Worktree $worktree, string $lock): bool
    {
        $source = $worktree->sourcePath().'/'.$lock;
        $target = $worktree->path().'/'.$lock;

        if (! File::exists($source) || ! File::exists($target)) {
            return false;
        }

        return hash_file('sha256', $source) === hash_file('sha256', $target);
    }

    /**
     * The configured dependency directories, normalized.
     *
     * @return array<string, array{copy: bool, path: string, manifest: string, lock: string, install: string}>
     */
    protected function dependencies(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = Arr::get($this->settings(), 'dependencies', []);

        $dependencies = [];

        foreach ($raw as $name => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $dependencies[(string) $name] = [
                'copy' => (bool) ($entry['copy'] ?? false),
                'path' => (string) ($entry['path'] ?? $name),
                'manifest' => (string) ($entry['manifest'] ?? 'composer.json'),
                'lock' => (string) ($entry['lock'] ?? 'composer.lock'),
                'install' => (string) ($entry['install'] ?? ''),
            ];
        }

        return $dependencies;
    }

    protected function runSteps(Worktree $worktree): void
    {
        /** @var array<int, string> $steps */
        $steps = Arr::get($this->settings(), 'steps', []);

        if ($steps === []) {
            return;
        }

        if ($this->option('no-install')) {
            $this->display()->warn('Skipping the configured steps because --no-install was passed.');

            return;
        }

        foreach ($steps as $step) {
            $this->process($step, $worktree->path());
        }
    }

    protected function databaseEnabled(): bool
    {
        return ! $this->option('no-database') && (bool) Arr::get($this->settings(), 'database.enabled', true);
    }

    protected function summary(Worktree $worktree, ?string $branch, ?string $url): void
    {
        $this->humanOutput()->newLine();
        $this->display()->info('Worktree ready.');

        $rows = [
            ['Path', $worktree->path()],
            ['Branch', $branch ?? '(detached)'],
        ];

        if ($url !== null) {
            $rows[] = ['URL', $url];
        }

        // Only report what was actually provisioned: a file database inside the
        // worktree is whatever its own .env resolves to, not a name this package
        // chose, unless it was cloned or sits next to a main file outside the
        // repository, where this package did name it.
        if ($this->databaseEnabled()) {
            foreach ($this->databaseConnections() as $index => $entry) {
                $label = $entry['connection'] === null ? '' : ' ('.$entry['connection'].')';
                $databases = $this->databases($entry['connection']);

                if ($databases->isServer()) {
                    $name = $worktree->database($entry['name']);
                    $rows[] = ['Database'.$label, isset($this->cloned[$index]) ? "{$name} (cloned from {$this->cloned[$index]})" : $name];
                }

                if ($databases->isFile() && (isset($this->cloned[$index]) || $this->isOutsideFile($worktree, $databases->database()))) {
                    $file = $this->fileLabel($worktree, (string) $this->databaseFile($worktree, $databases->database(), $entry['name']));
                    $rows[] = ['Database'.$label, isset($this->cloned[$index]) ? "{$file} (cloned)" : $file];
                }

                if ($entry['test'] !== null && $this->hasServerTestDatabase($entry, $worktree->path())) {
                    $rows[] = ['Test database'.$label, $worktree->database($entry['test']['name'])];
                }
            }
        }

        $this->humanOutput()->table(['Item', 'Value'], $rows);
    }

    /**
     * Whether an entry's test database landed on a server, and so is a name
     * worth reporting. Two PHPUnit files may test on different connections, so
     * one server among them means a database was created.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function hasServerTestDatabase(array $entry, string $path): bool
    {
        foreach ($this->testConnectionsFor($entry, $path) as $connection) {
            if ($this->databases($connection)->isServer()) {
                return true;
            }
        }

        return false;
    }
}
