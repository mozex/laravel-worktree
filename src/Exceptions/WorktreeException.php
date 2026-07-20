<?php

declare(strict_types=1);

namespace Mozex\Worktree\Exceptions;

use RuntimeException;

class WorktreeException extends RuntimeException
{
    public static function worktreeExists(string $path): self
    {
        return new self("A worktree already exists at [{$path}]. Finish it with worktree:teardown, or choose another branch.");
    }

    public static function worktreeNotFound(string $name): self
    {
        return new self("No worktree found matching [{$name}].");
    }

    public static function abandonNotConfirmed(string $branch): self
    {
        return new self("Discarding [{$branch}] was not confirmed. Pass --force to discard it without being asked.");
    }

    public static function unreadablePhpunitFile(string $path): self
    {
        return new self("The PHPUnit config at [{$path}] could not be parsed as XML.");
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self("Database driver [{$driver}] has no server to create a database on. Worktree databases work with mysql, mariadb, pgsql, and sqlite.");
    }

    public static function duplicateDatabase(string $name): self
    {
        return new self("More than one connection resolves to the database [{$name}] on the same server. Give each connection in worktree.database.connections a distinct name.");
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

    public static function detachedWorktree(string $path): self
    {
        return new self("The worktree at [{$path}] has no branch checked out, so there is nothing to push or merge. Finish it with --abandon.");
    }
}
