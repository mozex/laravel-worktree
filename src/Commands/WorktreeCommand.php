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
     * @param  string|array<int, string>  $command
     */
    protected function label(string|array $command): string
    {
        return is_array($command) ? implode(' ', $command) : $command;
    }
}
