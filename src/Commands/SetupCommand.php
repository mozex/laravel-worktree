<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
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
        {--no-install : Skip composer install inside the worktree}
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
            $env->set('DB_DATABASE', $worktree->appDatabase());
        }

        $env->set((string) Arr::get($this->settings(), 'env.app_url_key', 'APP_URL'), $herd->scheme().'://'.$worktree->host());

        if ((bool) Arr::get($this->settings(), 'host.remap_source_host', true)) {
            $env->remapHost($worktree->sourceHost(), $worktree->host());
        }

        $env->save($target);

        $this->patchPhpunit($worktree);
    }

    protected function patchPhpunit(Worktree $worktree): void
    {
        if (! $this->databaseEnabled() || ! (bool) Arr::get($this->settings(), 'database.test.enabled', true)) {
            return;
        }

        /** @var array<int, string> $files */
        $files = Arr::get($this->settings(), 'database.test.phpunit_files', ['phpunit.xml']);
        $key = (string) Arr::get($this->settings(), 'database.test.phpunit_key', 'DB_DATABASE');

        foreach ($files as $file) {
            $path = $worktree->path().'/'.$file;

            if (! File::exists($path)) {
                continue;
            }

            PhpunitConfig::fromFile($path)->setEnv($key, $worktree->testDatabase())->save($path);

            // A stock Laravel phpunit.xml is tracked, so this edit would leave the
            // worktree permanently dirty: teardown --into would refuse to run, and
            // --pr would commit the local test database name into the branch.
            $this->attempt(['git', 'update-index', '--skip-worktree', $file], $worktree->path());

            return;
        }
    }

    protected function prepareDatabase(Worktree $worktree): void
    {
        if (! $this->databaseEnabled()) {
            return;
        }

        $databases = new DatabaseManager($this->connectionConfig());

        if (! $databases->supported()) {
            $this->components->warn("Database driver [{$databases->driver()}] is not supported; skipping database creation.");

            return;
        }

        $databases->create($worktree->appDatabase());

        if ((bool) Arr::get($this->settings(), 'database.test.enabled', true)) {
            $databases->create($worktree->testDatabase());
        }

        $this->migrate($worktree);
    }

    protected function migrate(Worktree $worktree): void
    {
        if ($this->option('no-migrate') || $this->option('no-install')) {
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
            $this->components->warn('Skipping extra steps because dependencies were not installed.');

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

        if ($this->databaseEnabled()) {
            $rows[] = ['Database', $worktree->appDatabase()];

            if ((bool) Arr::get($this->settings(), 'database.test.enabled', true)) {
                $rows[] = ['Test database', $worktree->testDatabase()];
            }
        }

        $this->table(['Item', 'Value'], $rows);
    }
}
