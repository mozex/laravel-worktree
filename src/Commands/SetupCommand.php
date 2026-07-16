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
        {--no-install : Skip composer install, plus the migrations and steps that need it}
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

        $this->display()->info("Creating worktree [{$worktree->name()}] on branch [{$worktree->branch()}]");

        $this->createWorktree($worktree);
        $this->serveWithHerd($worktree, $herd);
        $this->prepareEnvironment($worktree, $herd);
        $this->copyExtraEnvironmentFiles($worktree);

        if (! $this->option('no-install')) {
            $this->process('composer install', $worktree->path());
        }

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

        $env->save($target);
    }

    /**
     * Gitignored env files beyond the main one (.env.testing is the usual case)
     * never arrive through git, so the suite would boot without one. They are
     * copied as they are, apart from the host remap; database isolation for the
     * suite is phpunit.xml's job, whose values outrank an env file anyway.
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

            if (! (bool) Arr::get($this->settings(), 'host.remap_source_host', true)) {
                continue;
            }

            $env = EnvFile::fromFile($target);
            $env->remapHost($worktree->sourceHost(), $worktree->host());
            $env->save($target);
        }
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
            $this->display()->warn("The database file [{$current}] is outside the repository; the worktree will share it.");

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
            $this->display()->warn("Database driver [{$databases->driver()}] is not supported; skipping database creation.");

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

        // The suite may run on a different connection than the app (the file's
        // DB_CONNECTION), and that is the server the database must land on.
        $this->databases($this->testConnection($worktree))->create($worktree->testDatabase());

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

        if ($this->phpunitFile($worktree) === null) {
            return false;
        }

        return $this->databases($this->testConnection($worktree))->isServer();
    }

    /**
     * The connection the test suite runs on: the PHPUnit file's DB_CONNECTION,
     * or null (the app default) when the file does not pin one.
     */
    protected function testConnection(Worktree $worktree): ?string
    {
        $file = $this->phpunitFile($worktree);

        if ($file === null) {
            return null;
        }

        return PhpunitConfig::fromFile($worktree->path().'/'.$file)->env('DB_CONNECTION');
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
        // the worktree's own .env resolves to, not a name this package chose.
        if ($this->databaseEnabled() && $this->databases()->isServer()) {
            $rows[] = ['Database', $worktree->appDatabase()];
        }

        if ($this->providesTestDatabase($worktree)) {
            $rows[] = ['Test database', $worktree->testDatabase()];
        }

        $this->humanOutput()->table(['Item', 'Value'], $rows);
    }
}
