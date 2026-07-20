<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\PhpunitConfig;
use Mozex\Worktree\Support\WorktreeList;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

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
        $name = $name === null || $name === '' ? (string) Config::get('database.default') : $name;

        /** @var array<string, mixed> $connection */
        $connection = Config::get("database.connections.{$name}", []);

        return $connection;
    }

    protected function databases(?string $connection = null): DatabaseManager
    {
        return new DatabaseManager($this->connectionConfig($connection));
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
     * The connection a connection entry's test database belongs on. The app
     * default entry (null) follows the suite's own connection, which the
     * PHPUnit file pins through DB_CONNECTION, so "develop on SQLite, test on
     * MySQL" keeps working. A named connection keeps its name in tests too.
     *
     * @param  array{connection: string|null, env: string, name: string, test: array{env: string, name: string}|null}  $entry
     */
    protected function testConnectionFor(array $entry, string $path): ?string
    {
        return $entry['connection'] === null ? $this->phpunitConnection($path) : $entry['connection'];
    }

    /**
     * The first configured PHPUnit file present at the path, relative to it.
     */
    protected function phpunitFile(string $path): ?string
    {
        /** @var array<int, string> $files */
        $files = Arr::get($this->settings(), 'database.phpunit_files', ['phpunit.xml', 'phpunit.xml.dist']);

        foreach ($files as $file) {
            if (File::exists($path.'/'.$file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The connection the suite runs on, read from the PHPUnit file's
     * DB_CONNECTION. Null when no file pins one, meaning the app default. An
     * unreadable file must not stop a teardown, so it too resolves to null.
     */
    protected function phpunitConnection(string $path): ?string
    {
        $file = $this->phpunitFile($path);

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
     */
    protected function process(string|array $command, ?string $path = null): void
    {
        $result = Process::path($path ?? $this->laravel->basePath())
            ->env($this->sourceEnvironment())
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
