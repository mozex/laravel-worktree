<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Contracts\SiteDriver;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Drivers\CommandDriver;
use Mozex\Worktree\Drivers\HerdDriver;
use Mozex\Worktree\Drivers\NoneDriver;
use Mozex\Worktree\Drivers\ValetDriver;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\FileLock;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Support\ProcessSiteRunner;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\SiteSettings;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

use function Laravel\Prompts\confirm;

/**
 * @phpstan-import-type Entry from Registry
 */
abstract class WorktreeCommand extends Command
{
    /**
     * @var array<string, false>|null
     */
    protected ?array $sourceEnvironment = null;

    /**
     * When true, human-facing output goes to stderr so stdout carries only the
     * data a shell wants to capture (the resolved worktree path).
     */
    protected bool $routeHumanToError = false;

    protected ?OutputStyle $errorStyle = null;

    protected ?Factory $errorComponents = null;

    /**
     * How many locked() calls are running, so a locked step can call another
     * one without waiting on its own lock.
     */
    protected int $lockDepth = 0;

    /**
     * Normalizes a branch argument. A blank Warp or shell parameter can arrive as
     * the literal characters '' or "", and trailing whitespace is never part of a
     * branch name, so both are stripped. Without this a mis-quoted blank would
     * create a "repo-''" worktree whose path then breaks teardown on Windows.
     */
    protected function cleanBranch(string $branch): string
    {
        return trim(trim($branch), "'\"");
    }

    /**
     * Where status messages go. With --print-path they are routed to stderr, so a
     * shell capturing "$(...)" gets a clean path while the user still sees progress.
     */
    protected function humanOutput(): OutputStyle
    {
        if (! $this->routeHumanToError) {
            return $this->output;
        }

        if ($this->errorStyle === null) {
            $output = $this->output->getOutput();
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $this->errorStyle = new OutputStyle($this->input, $stderr);
        }

        return $this->errorStyle;
    }

    protected function display(): Factory
    {
        if (! $this->routeHumanToError) {
            return $this->components;
        }

        return $this->errorComponents ??= new Factory($this->humanOutput());
    }

    /**
     * Laravel puts every variable from the main app's .env into the real process
     * environment, and child processes inherit it. Because phpdotenv refuses to
     * overwrite a variable that is already set, an artisan command run inside the
     * worktree would read the main app's values instead of the worktree's own
     * .env, and migrate against the main database. Passing false unsets each key
     * for the child, which then loads its own .env normally. Everything not
     * defined in the .env (PATH and friends) is still inherited.
     *
     * @return array<string, false>
     */
    protected function sourceEnvironment(): array
    {
        if ($this->sourceEnvironment !== null) {
            return $this->sourceEnvironment;
        }

        $source = $this->laravel->basePath().'/'.(string) Arr::get($this->settings(), 'env.source', '.env');

        if (! File::exists($source)) {
            return $this->sourceEnvironment = [];
        }

        return $this->sourceEnvironment = array_fill_keys(EnvFile::fromFile($source)->keys(), false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        /** @var array<string, mixed> $config */
        $config = Config::get('worktree', []);

        return $config;
    }

    /**
     * @param  string|null  $name  Defaults to the application's default connection.
     * @return array<string, mixed>
     */
    protected function connectionConfig(?string $name = null): array
    {
        $name = $this->connectionName($name);

        /** @var array<string, mixed> $connection */
        $connection = Config::get("database.connections.{$name}", []);

        return $connection;
    }

    protected function databases(?string $connection = null): DatabaseManager
    {
        return new DatabaseManager($this->connectionConfig($connection));
    }

    /**
     * A connection by the name it has now: null, the app default, is resolved
     * so a record still names the server after the default changes.
     */
    protected function connectionName(?string $connection): string
    {
        return $connection === null || $connection === '' ? (string) Config::get('database.default') : $connection;
    }

    /**
     * Where this package keeps its records for the repository: a directory in
     * git's common directory, which every worktree of the clone shares and git
     * never commits. Null when git gives no answer.
     */
    protected function registryDirectory(string $source): ?string
    {
        $lines = preg_split('/\R/', trim($this->capture(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], $source))) ?: [];
        $common = str_replace('\\', '/', (string) end($lines));

        // A git too old for --path-format answers relative to the repository,
        // not to this process's working directory.
        if ($common !== '' && preg_match('#^([A-Za-z]:)?/#', $common) !== 1) {
            $common = $source.'/'.$common;
        }

        return $common !== '' && is_dir($common) ? rtrim($common, '/').'/laravel-worktree' : null;
    }

    /**
     * The worktrees this package recorded for the repository. Null when there
     * is nothing to go on (git gave no answer, or the file cannot be read, which
     * is warned about unless a read the command made already did), and the
     * caller falls back to the configuration.
     */
    protected function registry(string $source, bool $quiet = false): ?Registry
    {
        $directory = $this->registryDirectory($source);

        if ($directory === null) {
            return null;
        }

        try {
            return Registry::fromFile($directory.'/registry.json');
        } catch (WorktreeException $exception) {
            if (! $quiet) {
                $this->display()->warn($exception->getMessage());
            }

            return null;
        }
    }

    /**
     * Changes the registry under the lock, reading it fresh so a change another
     * command made in the meantime survives. A registry that cannot be read or
     * written is warned about and left alone: it is bookkeeping, and the
     * command carries on without it.
     *
     * @param  Closure(Registry): void  $change
     */
    protected function updateRegistry(string $source, Closure $change): void
    {
        $directory = $this->registryDirectory($source);

        if ($directory === null) {
            return;
        }

        $file = $directory.'/registry.json';

        $this->locked($source, function () use ($file, $change): void {
            try {
                $registry = Registry::fromFile($file);
                $change($registry);
                $registry->save($file);
            } catch (WorktreeException $exception) {
                $this->display()->warn($exception->getMessage());
            }
        });
    }

    /**
     * Runs a step that two commands on one repository must not run at once:
     * creating a worktree and recording it, a site driver's commands, a
     * registry write. Agents set up worktrees side by side, and two of them
     * reading and rewriting the registry together would lose an entry.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function locked(string $source, Closure $callback): mixed
    {
        $directory = $this->lockDepth > 0 ? null : $this->registryDirectory($source);

        if ($directory === null) {
            return $callback();
        }

        $lock = new FileLock($directory.'/lock');
        $lock->acquire($this->lockSeconds(), fn () => $this->display()->info('Another worktree command is running on this repository; waiting for it to finish.'));

        $this->lockDepth++;

        try {
            return $callback();
        } finally {
            $this->lockDepth--;
            $lock->release();
        }
    }

    /**
     * How long a command waits for another one's lock. What runs under it
     * normally takes seconds: a git worktree add, a registry write, or a site
     * driver's commands, each of which site.timeout cuts off unless it is 0,
     * when a server stuck on a dialog holds the lock until it answers.
     */
    protected function lockSeconds(): int
    {
        return 600;
    }

    /**
     * The database connections to isolate per worktree, normalized so every
     * entry carries a connection (null for the app default), the .env key
     * holding its database name, a name template, and an optional test block.
     * Reading them in one place keeps setup, teardown, and list in step.
     *
     * @return array<int, array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}>
     */
    protected function databaseConnections(): array
    {
        /** @var array<int, mixed> $raw */
        $raw = Arr::get($this->settings(), 'database.connections', []);

        $connections = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $connection = $entry['connection'] ?? null;
            $env = (string) ($entry['env'] ?? 'DB_DATABASE');
            $name = (string) ($entry['name'] ?? '{slug}');

            $test = null;

            if (isset($entry['test']) && is_array($entry['test'])) {
                $test = [
                    'env' => (string) ($entry['test']['env'] ?? $env),
                    'name' => (string) ($entry['test']['name'] ?? $name.'_testing'),
                ];
            }

            $connections[] = [
                'connection' => $connection === null ? null : (string) $connection,
                'env' => $env,
                'name' => $name,
                'test' => $test,
            ];
        }

        return $connections;
    }

    /**
     * The connection a connection entry's test database belongs on, for one
     * PHPUnit file. The app default entry (null) follows that file's own
     * DB_CONNECTION, so "develop on SQLite, test on MySQL" keeps working, and
     * two files pinning different servers each get their database on the right
     * one. A named connection keeps its name in tests too.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function testConnectionFor(array $entry, string $path, ?string $file): ?string
    {
        return $entry['connection'] === null ? $this->phpunitConnection($path, $file) : $entry['connection'];
    }

    /**
     * Every connection an entry's test database was provisioned on: one per
     * PHPUnit file, since two files may pin different servers for the same
     * entry, deduplicated. With no PHPUnit file present this is the app default
     * alone, and setup created nothing at all in that case: a teardown drop
     * against it names a database that never existed, which the IF EXISTS makes
     * harmless.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     * @return list<string|null>
     */
    protected function testConnectionsFor(array $entry, string $path): array
    {
        $files = $this->phpunitFiles($path);
        $resolved = [];

        foreach ($files === [] ? [null] : $files as $file) {
            $resolved[] = $this->testConnectionFor($entry, $path, $file);
        }

        return array_values(array_unique($resolved, SORT_STRING));
    }

    /**
     * Every configured PHPUnit file present at the path, relative to it.
     *
     * All of them are patched, not just the first: a project that runs a second
     * suite from a second config (browser tests beside the main suite) needs the
     * worktree's test database name in each file, or the suite whose file was
     * skipped runs against the main repository's test database.
     *
     * @return list<string>
     */
    protected function phpunitFiles(string $path): array
    {
        /** @var array<int, string> $files */
        $files = Arr::get($this->settings(), 'database.phpunit_files', ['phpunit.xml', 'phpunit.xml.dist']);

        $present = [];

        foreach ($files as $file) {
            $file = (string) $file;

            if (File::exists($path.'/'.$file)) {
                $present[] = $file;
            }
        }

        return $present;
    }

    /**
     * The connection a suite runs on, read from its PHPUnit file's
     * DB_CONNECTION. Null when there is no file or it pins none, meaning the
     * app default. An unreadable file must not stop a teardown, so it too
     * resolves to null.
     */
    protected function phpunitConnection(string $path, ?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        try {
            return PhpunitConfig::fromFile($path.'/'.$file)->env('DB_CONNECTION');
        } catch (WorktreeException) {
            return null;
        }
    }

    /**
     * @param  string|array<int, string>  $command
     * @param  array<string, string>  $environment  Extra variables for the child, applied after the main app's keys are unset.
     */
    protected function process(string|array $command, ?string $path = null, array $environment = []): void
    {
        $result = Process::path($path ?? $this->laravel->basePath())
            ->env(array_merge($this->sourceEnvironment(), $environment))
            ->timeout(0)
            ->run($command, function (string $type, string $chunk): void {
                $this->humanOutput()->write($chunk);
            });

        if ($result->failed()) {
            throw $this->failure($command, $result);
        }
    }

    /**
     * Copies a directory tree with the fastest native tool: robocopy on Windows
     * (multithreaded, which matters for a node_modules full of tiny files), cp
     * elsewhere. Returns false to let the caller install instead.
     *
     * A Composer path repository leaves a junction in vendor. cp preserves it as
     * a link, but robocopy cannot recreate one and following it would copy whole
     * external trees, so on Windows a source holding any junction or symlink is
     * refused here and installed fresh instead.
     */
    protected function copyDirectory(string $from, string $to): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // robocopy rejects the forward slashes Worktree normalizes paths to.
            $from = str_replace('/', '\\', $from);
            $to = str_replace('/', '\\', $to);

            if ($this->hasReparsePoints($from)) {
                return false;
            }

            // /XJ so a stray junction is never followed; success is any code < 8.
            $result = Process::timeout(0)->run([
                'robocopy', $from, $to, '/E', '/XJ', '/MT:16', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1',
            ]);

            return $result->exitCode() !== null && $result->exitCode() < 8;
        }

        File::ensureDirectoryExists($to);

        return Process::timeout(0)->run(['cp', '-a', $from.'/.', $to])->successful();
    }

    /**
     * Whether a Windows directory holds a junction or symlink in its top two
     * levels, where Composer and npm place their local package links. The walk
     * uses .NET enumeration so it never follows a link (which could recurse into
     * a huge external tree just to answer the question).
     */
    protected function hasReparsePoints(string $dir): bool
    {
        // The path is read from an env var, not appended to -Command, which
        // PowerShell would otherwise treat as extra command text. Any error
        // enumerating counts as unsafe, so the caller installs rather than copies.
        $script = 'try{foreach($a in [IO.Directory]::EnumerateDirectories($env:WT_REPARSE_DIR)){'
            .'if([IO.File]::GetAttributes($a)-band[IO.FileAttributes]::ReparsePoint){"1";exit};'
            .'foreach($b in [IO.Directory]::EnumerateDirectories($a)){'
            .'if([IO.File]::GetAttributes($b)-band[IO.FileAttributes]::ReparsePoint){"1";exit}}}}catch{"1"}';

        return trim(Process::env(['WT_REPARSE_DIR' => $dir])->run(['powershell', '-NoProfile', '-Command', $script])->output()) !== '';
    }

    /**
     * @param  string|array<int, string>  $command
     */
    protected function attempt(string|array $command, ?string $path = null): bool
    {
        return Process::path($path ?? $this->laravel->basePath())
            ->env($this->sourceEnvironment())
            ->timeout(0)
            ->run($command)
            ->successful();
    }

    /**
     * The site driver settings, resolved fresh for each run: the console keeps
     * one instance of a command, and the configuration may change between runs.
     */
    protected function siteSettings(): SiteSettings
    {
        return SiteSettings::resolve($this->settings(), fn (string $driver): bool => $this->siteDriver($driver)->available());
    }

    /**
     * A driver by its name, or a project's own driver class, built by the
     * container so it can ask for what it needs.
     */
    protected function siteDriver(string $driver): SiteDriver
    {
        return match (true) {
            $driver === 'herd' => new HerdDriver($this->siteBinary('herd')),
            $driver === 'valet' => new ValetDriver($this->siteBinary('valet')),
            $driver === 'command' => new CommandDriver(
                (array) Arr::get($this->settings(), 'site.drivers.command', []),
                (string) Arr::get($this->settings(), 'host.tld', 'test'),
            ),
            $driver === 'none' => new NoneDriver,
            SiteSettings::isDriverClass($driver) => $this->laravel->make($driver),
            default => throw WorktreeException::unknownSiteDriver($driver, SiteSettings::drivers()),
        };
    }

    /**
     * A driver's configured binary. A config cached by a release without that
     * driver has no setting for it, so it falls back to the bare name.
     */
    protected function siteBinary(string $driver): string
    {
        return (string) (Arr::get($this->settings(), "site.drivers.{$driver}.binary") ?: $driver);
    }

    /**
     * Every driver command goes through here, so it keeps the main app's .env
     * out of the child like every other process this package starts. Each
     * runner skips its remaining commands after one runs out of time, so a
     * separate lookup gets a runner of its own.
     *
     * @param  string  $hint  What a command that runs out of time may be waiting on.
     */
    protected function siteRunner(SiteSettings $site, string $hint = ''): SiteRunner
    {
        return new ProcessSiteRunner(
            $this->laravel->basePath(),
            $this->sourceEnvironment(),
            $site->timeout(),
            $hint,
            fn (string $message) => $this->display()->warn($message),
        );
    }

    /**
     * Output of a command that is allowed to fail; callers treat an empty
     * string as "unknown" and fall back.
     *
     * @param  string|array<int, string>  $command
     */
    protected function capture(string|array $command, ?string $path = null): string
    {
        return Process::path($path ?? $this->laravel->basePath())->env($this->sourceEnvironment())->run($command)->output();
    }

    /**
     * Output of a command whose failure must not be mistaken for an empty
     * result, such as the dirty check guarding a merge.
     *
     * @param  string|array<int, string>  $command
     */
    protected function captureOrFail(string|array $command, ?string $path = null): string
    {
        $result = Process::path($path ?? $this->laravel->basePath())->env($this->sourceEnvironment())->run($command);

        if ($result->failed()) {
            throw $this->failure($command, $result);
        }

        return $result->output();
    }

    /**
     * Some tools report their failure on stdout and leave stderr empty, which
     * used to produce a bare "Command [x] failed." with nothing to go on.
     *
     * @param  string|array<int, string>  $command
     */
    protected function failure(string|array $command, ProcessResult $result): WorktreeException
    {
        $output = trim($result->errorOutput()) === '' ? $result->output() : $result->errorOutput();

        return WorktreeException::commandFailed($this->label($command), $output);
    }

    /**
     * The worktree for a branch. Names are capped so the host fits a TLS
     * certificate, but a worktree made before that cap carries the longer
     * name in its directory, and its host and databases were named after it.
     * When the branch's worktree (registered with git, or the path given)
     * sits in a directory with the uncapped name, that name is kept, so such a
     * worktree can still be found, resumed, listed, and torn down.
     */
    protected function worktreeFor(string $source, string $branch, ?string $path = null): Worktree
    {
        $worktree = Worktree::make($source, $branch, $this->settings());
        $legacy = $worktree->uncappedName();

        if ($legacy === $worktree->name()) {
            return $worktree;
        }

        $path ??= $this->registeredPath($source, $branch);

        if ($path === null || basename(str_replace('\\', '/', $path)) !== $legacy) {
            return $worktree;
        }

        return $worktree->withName($legacy);
    }

    /**
     * The name setup gave a worktree made before anything was recorded: its
     * directory's, but only when that is the name setup gives its branch's
     * worktree. Any other worktree came from another tool, so this package
     * never served it or made its databases, and its directory may carry the
     * main site's name (some tools check out every worktree as
     * <id>/<repository>), which unserving would take down. The directory may
     * already be gone, so names are compared, not paths.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function setupName(array $worktree, string $source): ?string
    {
        $name = basename($worktree['path']);

        if ($worktree['branch'] === null || $name !== $this->worktreeFor($source, $worktree['branch'], $worktree['path'])->name()) {
            return null;
        }

        return $name;
    }

    /**
     * Where git has the branch checked out, if it does anywhere but the main
     * repository.
     */
    protected function registeredPath(string $source, string $branch): ?string
    {
        foreach (WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $source)) as $entry) {
            if ($entry['branch'] === $branch && ! $this->samePath($entry['path'], $source)) {
                return $entry['path'];
            }
        }

        return null;
    }

    /**
     * The recorded worktrees whose directory is gone, but for any git has
     * locked: a worktree on a drive that is not always mounted is locked
     * precisely so that nothing prunes it while the drive is away.
     *
     * @return list<Entry>
     */
    protected function goneEntries(Registry $registry, string $porcelain): array
    {
        $locked = WorktreeList::locked($porcelain);

        return array_values(array_filter($registry->entries(), fn (array $entry): bool => ! is_dir($entry['path'])
            && array_filter($locked, fn (string $path): bool => Registry::samePath($path, $entry['path'])) === []));
    }

    /**
     * The driver and secure flag the worktree was served with, so its site is
     * stopped where it is served even after the configuration moved to another
     * server. A recorded driver that can no longer be built (its class is
     * gone, its commands were removed) stops nothing: the configured driver
     * never served the worktree, and a site of the same name there may be
     * someone else's. Neither does one whose server is no longer installed,
     * which is said too, since the site is still served wherever it was.
     *
     * @param  Entry  $record
     */
    protected function recordedSiteSettings(array $record): SiteSettings
    {
        $settings = $this->settings();
        Arr::set($settings, 'site.driver', $record['driver']);
        Arr::set($settings, 'site.secure', $record['secure']);

        try {
            $site = SiteSettings::resolve($settings, fn (string $driver): bool => $this->siteDriver($driver)->available());
        } catch (Throwable $exception) {
            $this->display()->warn("The [{$record['driver']}] site driver that served this worktree can no longer be used ({$exception->getMessage()}), so its site [{$record['name']}] is left for you to stop by hand.");

            return new SiteSettings('none', false, 60);
        }

        if ($site->driver() !== $record['driver']) {
            $this->display()->warn("The [{$record['driver']}] site driver that served this worktree is not available any more, so its site [{$record['name']}] is left for you to stop by hand.");
        }

        return $site;
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
                $this->display()->warn("Not dropping [{$database['database']}]: it was created on [{$database['dsn']}], and the [{$database['connection']}] connection no longer points there. Drop it there by hand once you no longer need it.");

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
     * @param  bool  $force  Whether to drop without asking first.
     * @param  string|null  $worktree  The path of the worktree being torn down, whose parallel-test databases go too; null drops only what is planned.
     * @return bool Whether they were dropped: false when a guard refused or nobody confirmed.
     */
    protected function dropPlanned(array $drops, array $files, string $source, bool $force, ?string $worktree = null): bool
    {
        if ($drops === [] && $files === []) {
            return true;
        }

        foreach (array_column($drops, 'database') as $database) {
            if (! $this->isSourceDatabase($source, $database)) {
                continue;
            }

            $this->display()->warn("Refusing to drop [{$database}]; it matches the main repository database.");

            return false;
        }

        foreach ($files as $file => $main) {
            if (! $this->isSameFile($file, $main) && ! $this->isSourceFile($source, $file)) {
                continue;
            }

            $this->display()->warn("Refusing to delete [{$file}]; it is the main repository database.");

            return false;
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

        foreach ($worktree === null ? [] : $drops as $meta) {
            foreach ($meta['manager']->parallelDerivatives($meta['database']) as $derivative) {
                if ($this->isSourceDatabase($source, $derivative)) {
                    $this->display()->warn("Refusing to drop [{$derivative}]; it matches the main repository database.");

                    continue;
                }

                $this->collectDrop($derivatives, $derivative, $meta['manager']);
            }
        }

        if ($worktree !== null && $derivatives !== []) {
            $drops += $this->unclaimedDerivatives($derivatives, $source, $worktree);
        }

        $list = implode('] and [', array_unique([...array_column($drops, 'database'), ...array_keys($files)]));

        if (! $force && ! confirm(label: "Drop databases [{$list}]?", default: true)) {
            return false;
        }

        foreach ($drops as $meta) {
            $meta['manager']->drop($meta['database']);
        }

        foreach (array_keys($files) as $file) {
            File::delete([$file, $file.'-wal', $file.'-shm', $file.'-journal']);
        }

        return true;
    }

    /**
     * The derivatives no other worktree has as its own database: branch
     * "feature/login-test-2" names its database like a lane of
     * "feature/login". Finding out reads every worktree's files and the
     * registry, and when that fails none is dropped, since any may be in use.
     *
     * @param  array<string, array{database: string, manager: DatabaseManager}>  $derivatives
     * @return array<string, array{database: string, manager: DatabaseManager}>
     */
    protected function unclaimedDerivatives(array $derivatives, string $source, string $worktree): array
    {
        $registry = $this->registry($source, true);

        if ($registry === null && $this->registryDirectory($source) !== null) {
            $this->display()->warn('The worktree registry cannot be read, so no parallel-test database is dropped: one may be another worktree\'s own.');

            return [];
        }

        try {
            $claimed = $this->claimed($source, $registry ?? new Registry, $worktree)['names'];
        } catch (Throwable $exception) {
            $this->display()->warn("Could not tell which databases the other worktrees use ({$exception->getMessage()}), so no parallel-test database is dropped.");

            return [];
        }

        return array_filter($derivatives, fn (array $meta): bool => ! isset($claimed[mb_strtolower($meta['database'])]));
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
     * What claims a database: the main repository's own names (its env files,
     * every env.copy file, its PHPUnit files and every configured
     * connection's database), every database the registry records, the names
     * setup gives a listed worktree it made before anything was recorded, and
     * whatever a listed worktree's .env and PHPUnit files name. Lowercased,
     * since MySQL on Windows and macOS ignores case. The slugs are those of
     * every recorded worktree and every such listed one, whose databases all
     * start with them.
     *
     * @param  string|null  $except  A worktree being torn down, whose own names claim nothing.
     * @return array{names: array<string, true>, slugs: list<string>}
     */
    protected function claimed(string $source, Registry $registry, ?string $except = null): array
    {
        $names = $this->sourceDatabaseNames($source);
        $main = [...$this->namedDatabases($source), ...$this->copiedEnvDatabases($source)];

        foreach ((array) Config::get('database.connections', []) as $connection) {
            if (is_array($connection) && is_string($connection['database'] ?? null)) {
                $main[] = $connection['database'];
            }
        }

        foreach ($main as $name) {
            if ($name !== '') {
                $names[mb_strtolower($name)] = true;
            }
        }

        $slugs = [];

        foreach ($registry->entries() as $entry) {
            if ($except !== null && Registry::samePath($entry['path'], $this->canonical($except))) {
                continue;
            }

            foreach ($entry['databases'] as $database) {
                $names[mb_strtolower($database['database'])] = true;
            }

            $slugs[] = $this->slugOf($source, $entry['name']);
        }

        foreach (WorktreeList::parse($this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source)) as $worktree) {
            if ($this->samePath($worktree['path'], $source) || ($except !== null && $this->samePath($worktree['path'], $except))) {
                continue;
            }

            foreach ($this->namedDatabases($worktree['path']) as $name) {
                $names[mb_strtolower($name)] = true;
            }

            if ($worktree['branch'] === null || $registry->find($this->canonical($worktree['path'])) !== null || $this->setupName($worktree, $source) === null) {
                continue;
            }

            $made = $this->worktreeFor($source, $worktree['branch'], $worktree['path']);
            $slugs[] = $made->slug();

            foreach ($this->databaseConnections() as $entry) {
                foreach (array_filter([$entry['name'], $entry['test']['name'] ?? null]) as $template) {
                    $names[mb_strtolower($made->database($template))] = true;
                }
            }
        }

        return ['names' => $names, 'slugs' => array_values(array_unique($slugs))];
    }

    /**
     * The database names a checkout's own .env and PHPUnit files hold, under
     * each configured connection's keys.
     *
     * @return list<string>
     */
    protected function namedDatabases(string $path): array
    {
        $env = File::exists($path.'/.env') ? EnvFile::fromFile($path.'/.env') : null;
        $phpunit = [];

        foreach ($this->phpunitFiles($path) as $file) {
            try {
                $phpunit[] = PhpunitConfig::fromFile($path.'/'.$file);
            } catch (WorktreeException) {
                // an unreadable file names nothing
            }
        }

        $names = [];

        foreach ($this->databaseConnections() as $entry) {
            $names[] = (string) $env?->get($entry['env']);

            foreach ($entry['test'] === null ? [] : $phpunit as $config) {
                $names[] = (string) $config->env($entry['test']['env']);
            }
        }

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }

    /**
     * Whether a checkout's own .env or PHPUnit files name one of an entry's
     * databases or SQLite files: a worktree that moved carries them along.
     *
     * @param  Entry  $entry
     */
    protected function namesDatabaseOf(array $entry, string $path): bool
    {
        $databases = array_map(mb_strtolower(...), array_column($entry['databases'], 'database'));

        foreach ($this->namedDatabases($path) as $name) {
            if (in_array(mb_strtolower($name), $databases, true)) {
                return true;
            }

            foreach (array_column($entry['files'], 'path') as $file) {
                if ($this->isSameFile($name, $file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The database names the main repository's env.copy files hold (its
     * .env.testing, say), under each configured connection's keys.
     *
     * @return list<string>
     */
    protected function copiedEnvDatabases(string $source): array
    {
        $names = [];

        foreach ((array) Arr::get($this->settings(), 'env.copy', []) as $file) {
            if (! is_string($file) || ! File::exists($source.'/'.$file)) {
                continue;
            }

            $env = EnvFile::fromFile($source.'/'.$file);

            foreach ($this->databaseConnections() as $entry) {
                foreach (array_filter([$entry['env'], $entry['test']['env'] ?? null]) as $key) {
                    $names[] = (string) $env->get($key);
                }
            }
        }

        return $names;
    }

    /**
     * Database names follow the slug, so two names with one slug share them.
     */
    protected function slugOf(string $source, string $name): string
    {
        return Worktree::make($source, $name, $this->settings())->withName($name)->slug();
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
     * Where a connection's SQLite file lives for a worktree, given the path the
     * main repository uses. Null for an in-memory or unset database.
     *
     * A file inside the repository lands at the same place inside the
     * worktree, which isolates it already. A file outside the repository
     * (an absolute path elsewhere, or a relative one climbing out with "../")
     * would be shared by every worktree, and migrate:fresh in one of them
     * would wipe the main app's data, so it is handled like a database server
     * instead: the worktree gets a file of its own next to it, named by the
     * connection's name template and keeping its extension, and teardown
     * deletes that file.
     */
    protected function databaseFile(Worktree $worktree, string $configured, string $template): ?string
    {
        if ($configured === '' || $configured === ':memory:') {
            return null;
        }

        return $worktree->mapPath($this->resolveFile($worktree, $configured))
            ?? $this->siblingDatabaseFile($worktree, $configured, $template);
    }

    protected function siblingDatabaseFile(Worktree $worktree, string $configured, string $template): string
    {
        $resolved = $this->resolveFile($worktree, $configured);
        $extension = pathinfo($resolved, PATHINFO_EXTENSION);

        return dirname($resolved).'/'.$worktree->database($template).($extension === '' ? '' : '.'.$extension);
    }

    /**
     * Whether the main repository's SQLite file sits outside it, so each
     * worktree gets a sibling file that teardown has to delete.
     */
    protected function isOutsideFile(Worktree $worktree, string $configured): bool
    {
        return $configured !== '' && $configured !== ':memory:'
            && $worktree->mapPath($this->resolveFile($worktree, $configured)) === null;
    }

    /**
     * The main app's SQLite path made absolute. Laravel resolves a relative
     * one against the app's directory, so that is what it is resolved against
     * here, with any "../" segments collapsed.
     */
    protected function resolveFile(Worktree $worktree, string $configured): string
    {
        $path = $worktree->isAbsolute($configured) ? $configured : $worktree->sourcePath().'/'.$configured;

        return $worktree->normalize($path);
    }

    /**
     * Two spellings of one file, compared without case because the
     * filesystems on Windows and macOS ignore it.
     */
    protected function isSameFile(string $a, string $b): bool
    {
        return mb_strtolower($this->canonical($a)) === mb_strtolower($this->canonical($b));
    }

    /**
     * Whether a database name belongs to the main repository, which setup must
     * never provision over and teardown must never drop.
     */
    protected function isSourceDatabase(string $source, string $database): bool
    {
        return isset($this->sourceDatabaseNames($source)[mb_strtolower($database)]);
    }

    /**
     * Every name the main repository's databases go by: each connection's
     * configured database, and the value each entry's env keys hold in the
     * main env file (the one setup copies from, so pointing env.source at
     * another file cannot quietly disable the guard). A worktree name is
     * checked against all of them, not only its own connection's, since two
     * connections can share a server. The names are lowercased because MySQL
     * on Windows and macOS ignores case, so "Blog" would land on "blog".
     *
     * @return array<string, true>
     */
    protected function sourceDatabaseNames(string $source): array
    {
        $path = $source.'/'.(string) Arr::get($this->settings(), 'env.source', '.env');
        $env = File::exists($path) ? EnvFile::fromFile($path) : null;

        $names = [];

        foreach ($this->databaseConnections() as $entry) {
            $values = [$this->databases($entry['connection'])->database()];

            foreach (array_filter([$entry['env'], $entry['test']['env'] ?? null]) as $key) {
                $values[] = (string) $env?->get($key);
            }

            foreach ($values as $value) {
                if ($value !== '') {
                    $names[mb_strtolower($value)] = true;
                }
            }
        }

        return $names;
    }

    /**
     * git with long path support on, for the commands that write or delete a
     * whole worktree. A worktree sits next to (or inside) the main checkout,
     * so its deepest files (vendor, node_modules) easily pass Windows' 260
     * character limit, and git for Windows then fails with "Filename too long"
     * unless core.longpaths is set. Other platforms ignore the setting.
     *
     * @return list<string>
     */
    protected function git(): array
    {
        return ['git', '-c', 'core.longpaths=true'];
    }

    protected function isGitRepository(string $path): bool
    {
        return $this->attempt(['git', 'rev-parse', '--is-inside-work-tree'], $path);
    }

    /**
     * The main working tree of the repository the path belongs to. git lists it
     * first in the porcelain output; null when git gives no answer.
     */
    protected function mainWorktreePath(string $path): ?string
    {
        $entries = WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $path));

        return $entries[0]['path'] ?? null;
    }

    /**
     * Every command is designed to run from the main repository: setup derives
     * names from its directory, and teardown must never mistake it for a
     * disposable worktree. Only inside a linked worktree do the git dir and the
     * common dir diverge; a path merely nested in the main working tree (a
     * monorepo app) keeps them equal. When git gives no answer (including a git
     * too old for --path-format) the path is not blocked.
     */
    protected function isMainRepository(string $path): bool
    {
        $output = trim($this->capture(['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir'], $path));

        if ($output === '') {
            return true;
        }

        $lines = preg_split('/\R/', $output) ?: [];

        return count($lines) < 2 || $this->samePath((string) $lines[0], (string) $lines[1]);
    }

    /**
     * git reports symlink-resolved paths while the app base path does not, so
     * the same directory can arrive spelled two different ways.
     */
    protected function samePath(string $a, string $b): bool
    {
        return $this->canonical($a) === $this->canonical($b);
    }

    protected function canonical(string $path): string
    {
        $resolved = realpath($path);

        return str_replace('\\', '/', rtrim($resolved === false ? $path : $resolved, '/\\'));
    }

    /**
     * @param  string|array<int, string>  $command
     */
    protected function label(string|array $command): string
    {
        return is_array($command) ? implode(' ', $command) : $command;
    }
}
