<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\EnvFile;

abstract class WorktreeCommand extends Command
{
    /**
     * @var array<string, false>|null
     */
    protected ?array $sourceEnvironment = null;

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

        $source = base_path().'/'.(string) Arr::get($this->settings(), 'env.source', '.env');

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
        $config = config('worktree', []);

        return $config;
    }

    /**
     * @param  string|null  $name  Defaults to the application's default connection.
     * @return array<string, mixed>
     */
    protected function connectionConfig(?string $name = null): array
    {
        $name = $name === null || $name === '' ? (string) config('database.default') : $name;

        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$name}", []);

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
        $result = Process::path($path ?? base_path())
            ->env($this->sourceEnvironment())
            ->timeout(0)
            ->run($command, function (string $type, string $chunk): void {
                $this->output->write($chunk);
            });

        if ($result->failed()) {
            throw WorktreeException::commandFailed($this->label($command), $result->errorOutput());
        }
    }

    /**
     * @param  string|array<int, string>  $command
     */
    protected function attempt(string|array $command, ?string $path = null): bool
    {
        return Process::path($path ?? base_path())
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
        return Process::path($path ?? base_path())->env($this->sourceEnvironment())->run($command)->output();
    }

    /**
     * Output of a command whose failure must not be mistaken for an empty
     * result, such as the dirty check guarding a merge.
     *
     * @param  string|array<int, string>  $command
     */
    protected function captureOrFail(string|array $command, ?string $path = null): string
    {
        $result = Process::path($path ?? base_path())->env($this->sourceEnvironment())->run($command);

        if ($result->failed()) {
            throw WorktreeException::commandFailed($this->label($command), $result->errorOutput());
        }

        return $result->output();
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
