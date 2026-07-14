<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Exceptions\WorktreeException;

abstract class WorktreeCommand extends Command
{
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
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        $name = (string) config('database.default');

        /** @var array<string, mixed> $connection */
        $connection = config("database.connections.{$name}", []);

        return $connection;
    }

    /**
     * @param  string|array<int, string>  $command
     */
    protected function process(string|array $command, ?string $path = null): void
    {
        $result = Process::path($path ?? base_path())
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
            ->timeout(0)
            ->run($command)
            ->successful();
    }

    /**
     * @param  string|array<int, string>  $command
     */
    protected function capture(string|array $command, ?string $path = null): string
    {
        return Process::path($path ?? base_path())->run($command)->output();
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
