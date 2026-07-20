<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

class SetupCommand extends WorktreeCommand
{
    protected $signature = 'worktree:setup
        {branch? : The branch to work on (auto-generated when omitted)}
        {--base= : Base branch used when creating a new branch}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--print-path : Print only the resolved worktree path (status goes to stderr), for shell integration}';

    protected $description = 'Create an isolated git worktree with its own Herd site and databases';

    public function handle(): int
    {
        $this->routeHumanToError = (bool) $this->option('print-path');

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
        $worktree = Worktree::make($source, $this->resolveBranch(), $config);
        $herd = HerdMode::tryFrom((string) Arr::get($config, 'herd', HerdMode::Secure->value)) ?? HerdMode::Secure;

        if ($this->databaseEnabled()) {
            $this->guardDuplicateDatabases($worktree);
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
            $this->process(['git', 'worktree', 'add', $worktree->path(), $worktree->branch()], $worktree->sourcePath());

            return;
        }

        $base = (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main'));

        $this->process(['git', 'worktree', 'add', $worktree->path(), '-b', $worktree->branch(), $base], $worktree->sourcePath());
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
            if ($this->attempt($command, $worktree->path())) {
                continue;
            }

            $this->display()->warn("Could not run [{$this->label($command)}]. The site may need to be served manually.");

            return;
        }
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

        $current = $env->get($entry['env']);

        // Unset, in memory, or relative: already resolves inside the worktree.
        if ($current === null || $current === '' || $current === ':memory:' || ! $worktree->isAbsolute($current)) {
            return;
        }

        $mapped = $worktree->mapPath($current);

        if ($mapped === null) {
            $this->display()->warn("The database file [{$current}] is outside the repository; the worktree will share it.");

            return;
        }

        $env->set($entry['env'], $mapped);
    }

    protected function prepareDatabase(Worktree $worktree): void
    {
        if (! $this->databaseEnabled()) {
            return;
        }

        foreach ($this->databaseConnections() as $entry) {
            $this->createConnectionDatabase($worktree, $entry);
        }

        $this->prepareTestDatabases($worktree);
        $this->migrate($worktree);
    }

    /**
     * A server connection gets a named database of its own; a file (SQLite)
     * connection gets its file created inside the worktree.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function createConnectionDatabase(Worktree $worktree, array $entry): void
    {
        $databases = $this->databases($entry['connection']);

        if ($databases->isServer()) {
            $databases->create($worktree->database($entry['name']));

            return;
        }

        if ($databases->isFile()) {
            $this->createDatabaseFile($worktree, $entry['connection']);

            return;
        }

        $this->display()->warn('Database driver ['.$databases->driver().'] on connection ['.($entry['connection'] ?? 'default').'] is not supported; skipping database creation.');
    }

    /**
     * Two connections resolving to the same database name on the same server
     * would clobber one another, so this is caught before any worktree is made.
     */
    protected function guardDuplicateDatabases(Worktree $worktree): void
    {
        $seen = [];

        foreach ($this->databaseConnections() as $entry) {
            $databases = $this->databases($entry['connection']);

            if (! $databases->isServer()) {
                continue;
            }

            $name = $worktree->database($entry['name']);
            $signature = $databases->dsn().'|'.$name;

            if (isset($seen[$signature])) {
                throw WorktreeException::duplicateDatabase($name);
            }

            $seen[$signature] = true;
        }
    }

    /**
     * Laravel gitignores the SQLite file (database/.gitignore holds *.sqlite*), so
     * a fresh worktree never receives one from git and migrating would fail without
     * this. Creating it per worktree is exactly the isolation this package is after.
     */
    protected function createDatabaseFile(Worktree $worktree, ?string $connection): void
    {
        $source = $this->databases($connection)->database();

        if ($source === '' || $source === ':memory:') {
            return;
        }

        $path = $worktree->isAbsolute($source)
            ? $worktree->mapPath($source)
            : $worktree->path().'/'.$source;

        if ($path === null || File::exists($path)) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
    }

    /**
     * Creates a test database for every connection that asks for one and whose
     * suite runs against a server, then writes each name into the one PHPUnit
     * file. A SQLite test database is in memory or a file inside the worktree,
     * so it is already isolated and is left alone.
     */
    protected function prepareTestDatabases(Worktree $worktree): void
    {
        $file = $this->phpunitFile($worktree->path());

        if ($file === null) {
            return;
        }

        $path = $worktree->path().'/'.$file;
        $config = PhpunitConfig::fromFile($path);
        $patched = false;

        foreach ($this->databaseConnections() as $entry) {
            if ($entry['test'] === null) {
                continue;
            }

            // The suite may run a connection on a different server than the app
            // (the file's DB_CONNECTION), and that is where the database lands.
            $databases = $this->databases($this->testConnectionFor($entry, $worktree->path()));

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
        $command = $mode->command();

        if ($command === null) {
            return;
        }

        $arguments = ['php', 'artisan', $command, '--force'];

        if ($this->option('seed') || (bool) Arr::get($this->settings(), 'database.seed', false)) {
            $arguments[] = '--seed';
        }

        $this->process($arguments, $worktree->path());
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

        // Only report what was actually provisioned: a file database is whatever
        // each worktree's own .env resolves to, not a name this package chose.
        if ($this->databaseEnabled()) {
            foreach ($this->databaseConnections() as $entry) {
                $label = $entry['connection'] === null ? '' : ' ('.$entry['connection'].')';

                if ($this->databases($entry['connection'])->isServer()) {
                    $rows[] = ['Database'.$label, $worktree->database($entry['name'])];
                }

                if ($entry['test'] !== null && $this->databases($this->testConnectionFor($entry, $worktree->path()))->isServer()) {
                    $rows[] = ['Test database'.$label, $worktree->database($entry['test']['name'])];
                }
            }
        }

        $this->humanOutput()->table(['Item', 'Value'], $rows);
    }
}
