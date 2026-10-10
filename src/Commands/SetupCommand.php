<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseCloner;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;
use Throwable;

class SetupCommand extends WorktreeCommand
{
    protected $signature = 'worktree:setup
        {branch? : The branch to work on (auto-generated when omitted)}
        {--base= : Base branch used when creating a new branch}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}
        {--print-path : Print only the resolved worktree path (status goes to stderr), for shell integration}';

    protected $description = 'Create an isolated git worktree with its own Herd site and databases';

    /**
     * What each connection entry's database was cloned from, keyed by the
     * entry's index: a database name on a server, a path for a SQLite file.
     *
     * @var array<int, string>
     */
    protected array $cloned = [];

    public function handle(): int
    {
        $this->routeHumanToError = (bool) $this->option('print-path');

        // The console keeps one instance of a command, so a run must not see
        // what an earlier run in the same process cloned.
        $this->cloned = [];

        $source = $this->laravel->basePath();

        if (! $this->isGitRepository($source)) {
            $this->display()->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->display()->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $config = $this->settings();
        $worktree = $this->worktreeFor($source, $this->resolveBranch());
        $herd = HerdMode::tryFrom((string) Arr::get($config, 'herd', HerdMode::Secure->value)) ?? HerdMode::Secure;

        if ($this->databaseEnabled()) {
            $this->guardDuplicateDatabases($worktree);
            $this->guardSourceDatabases($worktree);
        }

        $this->display()->info("Creating worktree [{$worktree->name()}] on branch [{$worktree->branch()}]");

        $this->createWorktree($worktree);
        $this->serveWithHerd($worktree, $herd);
        $this->prepareEnvironment($worktree, $herd);
        $this->copyExtraEnvironmentFiles($worktree);

        $this->provisionDependencies($worktree);

        $this->prepareDatabase($worktree);
        $this->runSteps($worktree);
        $this->summary($worktree, $herd);

        if ($this->option('print-path')) {
            $this->line($worktree->path());
        }

        return self::SUCCESS;
    }

    protected function resolveBranch(): string
    {
        $branch = $this->cleanBranch((string) $this->argument('branch'));

        if ($branch !== '') {
            return $branch;
        }

        return 'feature/auto-'.Carbon::now()->format('ymd-His');
    }

    protected function createWorktree(Worktree $worktree): void
    {
        // A directory that is already this branch's registered worktree is not a
        // conflict but a half-finished setup (a failed npm step, an interrupted
        // migration), so provisioning resumes instead of demanding a teardown.
        if ($this->isExistingWorktree($worktree)) {
            $this->display()->info("Worktree [{$worktree->name()}] already exists; resuming provisioning.");

            return;
        }

        // git populates an existing empty directory happily, and teardown can leave
        // one behind when Windows has not released its handle yet, so only a
        // directory with something in it is a real conflict.
        if (is_dir($worktree->path()) && ! Directory::isEmpty($worktree->path())) {
            throw WorktreeException::worktreeExists($worktree->path());
        }

        if ($this->attempt(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$worktree->branch()}"], $worktree->sourcePath())) {
            $this->process([...$this->git(), 'worktree', 'add', $worktree->path(), $worktree->branch()], $worktree->sourcePath());

            return;
        }

        $base = (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main'));

        $this->process([...$this->git(), 'worktree', 'add', $worktree->path(), '-b', $worktree->branch(), $base], $worktree->sourcePath());
    }

    /**
     * Whether the target directory is already registered as this branch's
     * worktree. A worktree on any other branch stays a conflict.
     */
    protected function isExistingWorktree(Worktree $worktree): bool
    {
        $entries = WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $worktree->sourcePath()));

        foreach ($entries as $entry) {
            if ($entry['branch'] === $worktree->branch() && $this->samePath($entry['path'], $worktree->path())) {
                return true;
            }
        }

        return false;
    }

    protected function serveWithHerd(Worktree $worktree, HerdMode $herd): void
    {
        if (! $herd->enabled()) {
            return;
        }

        // The site is always linked first. Herd only serves parked and linked
        // directories, and a worktree in a nested path (such as ".worktrees")
        // is neither: "herd secure" alone would mint a certificate for a site
        // that never answers. Linking a parked worktree is harmless.
        $commands = [['herd', 'link', $worktree->name()]];

        if ($herd === HerdMode::Secure) {
            $commands[] = ['herd', 'secure'];
        }

        foreach ($commands as $command) {
            if ($this->herd($command, $worktree->path())) {
                continue;
            }

            $this->display()->warn("Could not run [{$this->label($command)}]. The site may need to be served manually.");

            return;
        }

        $this->confirmHerdLink($worktree);
    }

    /**
     * Herd's CLI hands "link" to the Herd app and ignores a failed request, so
     * the command can exit cleanly having linked nothing, and the site then
     * never answers. Herd's own site list says whether it took. A list that
     * cannot be read proves nothing either way, so it stays quiet then.
     */
    protected function confirmHerdLink(Worktree $worktree): void
    {
        $links = $this->herdResult(['herd', 'links'], $worktree->path());

        if ($links === null || $links->failed() || str_contains($links->output(), $worktree->name())) {
            return;
        }

        $this->display()->warn("Herd reported linking [{$worktree->name()}] but does not list it, so the site may not answer. Check the Herd app, then run [herd link {$worktree->name()}] in the worktree.");
    }

    protected function prepareEnvironment(Worktree $worktree, HerdMode $herd): void
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

        $env->set((string) Arr::get($this->settings(), 'env.app_url_key', 'APP_URL'), $herd->scheme().'://'.$worktree->host());

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

    protected function summary(Worktree $worktree, HerdMode $herd): void
    {
        $this->humanOutput()->newLine();
        $this->display()->info('Worktree ready.');

        $rows = [
            ['Path', $worktree->path()],
            ['Branch', $worktree->branch()],
        ];

        if ($herd->enabled()) {
            $rows[] = ['URL', $herd->scheme().'://'.$worktree->host()];
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
