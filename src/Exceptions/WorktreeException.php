<?php

declare(strict_types=1);

namespace Mozex\Worktree\Exceptions;

use RuntimeException;

class WorktreeException extends RuntimeException
{
    public static function worktreeExists(string $path): self
    {
        return new self("A worktree already exists at [{$path}].");
    }

    public static function worktreeNotFound(string $name): self
    {
        return new self("No worktree found matching [{$name}].");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Database driver [{$driver}] is not supported. Worktree databases work with mysql, mariadb, and pgsql.");
    }

    public static function commandFailed(string $command, string $output): self
    {
        $output = trim($output);

        return new self(trim("Command [{$command}] failed. {$output}"));
    }

    public static function dirtyWorktree(string $path): self
    {
        return new self("The worktree at [{$path}] has uncommitted changes. Commit or stash them, or pass --force to discard.");
    }
}
