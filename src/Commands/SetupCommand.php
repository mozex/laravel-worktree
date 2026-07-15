<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Worktree;

class SetupCommand extends WorktreeCommand
{
    protected $signature = 'worktree:setup
        {branch? : The branch to work on (auto-generated when omitted)}
        {--base= : Base branch used when creating a new branch}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip composer install, plus the migrations and steps that need it}
        {--seed : Seed the application database after migrating}';

    protected $description = 'Create an isolated git worktree with its own Herd site and databases';

    public function handle(): int
    {
        $source = base_path();

        if (! $this->isGitRepository($source)) {
            $this->components->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        $config = $this->settings();
        $worktree = Worktree::make($source, $this->resolveBranch(), $config);
        $herd = HerdMode::tryFrom((string) Arr::get($config, 'herd', HerdMode::Secure->value)) ?? HerdMode::Secure;

        $this->components->info("Creating worktree [{$worktree->name()}] on branch [{$worktree->branch()}]");

        $this->createWorktree($worktree);
        $this->serveWithHerd($worktree, $herd);
        $this->prepareEnvironment($worktree, $herd);

        if (! $this->option('no-install')) {
            $this->process('composer install', $worktree->path());
        }

        $this->prepareDatabase($worktree);
        $this->runSteps($worktree);
        $this->summary($worktree, $herd);

        return self::SUCCESS;
    }

    protected function resolveBranch(): string
    {
        $branch = (string) $this->argument('branch');

        if ($branch !== '') {
            return $branch;
        }

        return 'feature/auto-'.now()->format('ymd-His');
    }

    protected function createWorktree(Worktree $worktree): void
    {
        if (is_dir($worktree->path())) {
            throw WorktreeException::worktreeExists($worktree->path());
        }

        if ($this->attempt(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$worktree->branch()}"], $worktree->sourcePath())) {
            $this->process(['git', 'worktree', 'add', $worktree->path(), $worktree->branch()], $worktree->sourcePath());

            return;
        }

        $base = (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main'));

        $this->process(['git', 'worktree', 'add', $worktree->path(), '-b', $worktree->branch(), $base], $worktree->sourcePath());
    }

    protected function serveWithHerd(Worktree $worktree, HerdMode $herd): void
    {
        if (! $herd->enabled()) {
            return;
        }

        $command = $herd === HerdMode::Secure
            ? ['herd', 'secure']
            : ['herd', 'link', $worktree->name()];

        if ($this->attempt($command, $worktree->path())) {
            return;
        }

        $this->components->warn("Could not run [{$this->label($command)}]. The site may need to be served manually.");
    }

    protected function prepareEnvironment(Worktree $worktree, HerdMode $herd): void
    {
        $source = $worktree->sourcePath().'/'.(string) Arr::get($this->settings(), 'env.source', '.env');
        $target = $worktree->path().'/.env';

        if (! File::exists($source)) {
            $this->components->warn("No env file at [{$source}]; skipping environment setup.");

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

        $env->save($target);
    }

    /**
     * A server database is shared between worktrees, so the worktree points at one
     * of its own. A file database already lives inside the worktree, so the value
     * only needs redirecting when it is an absolute path back into the source.
     */
    protected function applyDatabaseEnv(EnvFile $env, Worktree $worktree): void
    {
        $databases = $this->databases();

        if ($databases->isServer()) {
            $env->set('DB_DATABASE', $worktree->appDatabase());

            return;
        }

        if (! $databases->isFile()) {
            return;
        }

        $current = $env->get('DB_DATABASE');

        // Unset, in memory, or relative: already resolves inside the worktree.
        if ($current === null || $current === '' || $current === ':memory:' || ! $worktree->isAbsolute($current)) {
            return;
        }

        $mapped = $worktree->mapPath($current);

        if ($mapped === null) {
            $this->components->warn("The database file [{$current}] is outside the repository; the worktree will share it.");

            return;
        }

        $env->set('DB_DATABASE', $mapped);
    }

    protected function prepareDatabase(Worktree $worktree): void
    {
        if (! $this->databaseEnabled()) {
            return;
        }

        $databases = $this->databases();

        if ($databases->isServer()) {
            $databases->create($worktree->appDatabase());
        } elseif ($databases->isFile()) {
            $this->createDatabaseFile($worktree);
        } else {
            $this->components->warn("Database driver [{$databases->driver()}] is not supported; skipping database creation.");

            return;
        }

        $this->prepareTestDatabase($worktree);
        $this->migrate($worktree);
    }

    /**
     * Laravel gitignores the SQLite file (database/.gitignore holds *.sqlite*), so
     * a fresh worktree never receives one from git and migrating would fail without
     * this. Creating it per worktree is exactly the isolation this package is after.
     */
    protected function createDatabaseFile(Worktree $worktree): void
    {
        $source = $this->databases()->database();

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
     * Only a server test database needs a name of its own. A SQLite one is either
     * in memory or a file inside the worktree, so it is already isolated and
     * renaming it would just break the suite.
     */
    protected function prepareTestDatabase(Worktree $worktree): void
    {
        if (! $this->providesTestDatabase($worktree)) {
            return;
        }

        $file = (string) $this->phpunitFile($worktree);
        $path = $worktree->path().'/'.$file;

        $this->databases()->create($worktree->testDatabase());

        $key = (string) Arr::get($this->settings(), 'database.test.phpunit_key', 'DB_DATABASE');
        PhpunitConfig::fromFile($path)->setEnv($key, $worktree->testDatabase())->save($path);

        // A stock Laravel phpunit.xml is tracked, so this edit would leave the
        // worktree permanently dirty: teardown --into would refuse to run, and
        // --pr would commit the local test database name into the branch.
        $this->attempt(['git', 'update-index', '--skip-worktree', $file], $worktree->path());
    }

    /**
     * Whether the suite runs against a server database that needs one of its own.
     * A stock Laravel phpunit.xml pins the suite to an in-memory SQLite database,
     * which is already isolated, so there is nothing to create or rewrite.
     */
    protected function providesTestDatabase(Worktree $worktree): bool
    {
        if (! $this->databaseEnabled() || ! (bool) Arr::get($this->settings(), 'database.test.enabled', true)) {
            return false;
        }

        $file = $this->phpunitFile($worktree);

        if ($file === null) {
            return false;
        }

        $connection = PhpunitConfig::fromFile($worktree->path().'/'.$file)->env('DB_CONNECTION');

        return $this->databases($connection)->isServer();
    }

    /**
     * The first configured PHPUnit file present in the worktree, relative to it.
     */
    protected function phpunitFile(Worktree $worktree): ?string
    {
        /** @var array<int, string> $files */
        $files = Arr::get($this->settings(), 'database.test.phpunit_files', ['phpunit.xml']);

        foreach ($files as $file) {
            if (File::exists($worktree->path().'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    protected function migrate(Worktree $worktree): void
    {
        if ($this->option('no-migrate')) {
            return;
        }

        // Artisan cannot boot without the worktree's own vendor directory.
        if ($this->option('no-install')) {
            $this->components->warn('Skipping migrations because --no-install was passed.');

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

    protected function runSteps(Worktree $worktree): void
    {
        /** @var array<int, string> $steps */
        $steps = Arr::get($this->settings(), 'steps', []);

        if ($steps === []) {
            return;
        }

        if ($this->option('no-install')) {
            $this->components->warn('Skipping the configured steps because --no-install was passed.');

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
        $this->newLine();
        $this->components->info('Worktree ready.');

        $rows = [
            ['Path', $worktree->path()],
            ['Branch', $worktree->branch()],
        ];

        if ($herd->enabled()) {
            $rows[] = ['URL', $herd->scheme().'://'.$worktree->host()];
        }

        // Only report what was actually provisioned: a file database is whatever
        // the worktree's own .env resolves to, not a name this package chose.
        if ($this->databaseEnabled() && $this->databases()->isServer()) {
            $rows[] = ['Database', $worktree->appDatabase()];
        }

        if ($this->providesTestDatabase($worktree)) {
            $rows[] = ['Test database', $worktree->testDatabase()];
        }

        $this->table(['Item', 'Value'], $rows);
    }
}
