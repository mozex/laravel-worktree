<?php

declare(strict_types=1);

namespace Mozex\Worktree\Exceptions;

use Mozex\Worktree\Contracts\SiteDriver;
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

    /**
     * @param  list<string>  $drivers
     */
    public static function unknownSiteDriver(string $driver, array $drivers): self
    {
        return new self("Site driver [{$driver}] does not exist. Set worktree.site.driver (WORKTREE_DRIVER) to one of: ".implode(', ', $drivers).', or the name of a class implementing '.SiteDriver::class.'.');
    }

    public static function commandDriverUnconfigured(): self
    {
        return new self('The command site driver has no command to serve a site with. Set worktree.site.drivers.command.serve, or choose another driver.');
    }

    public static function duplicateDatabase(string $name): self
    {
        return new self("More than one connection resolves to the database [{$name}] on the same server. Give each connection in worktree.database.connections a distinct name.");
    }

    public static function sourceDatabase(string $name): self
    {
        return new self("The worktree database [{$name}] is the main repository's own database. Give each name in worktree.database.connections a worktree token such as {slug}.");
    }

    public static function cloneSourceBusy(string $source): self
    {
        return new self("Could not clone [{$source}]: Postgres refuses to copy a database while other sessions are connected to it, and pg_dump and pg_restore are not on your PATH to copy it another way. Close the other connections (a queue worker, a database GUI) or install the PostgreSQL client tools, then run worktree:setup again.");
    }

    public static function structureOnlyReferenced(string $database, string $reason): self
    {
        return new self("Could not empty the structure-only tables in [{$database}]: a table that keeps its rows has a foreign key into one of them, and emptying it anyway needs a Postgres superuser. Connect as one, or take the table out of worktree.database.clone.structure_only. ({$reason})");
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

    public static function unreadableRegistry(string $file): self
    {
        return new self("The worktree registry at [{$file}] could not be read, so it is left as it is and worktrees are found from the configuration instead. Fix or delete the file to have worktrees recorded again.");
    }

    public static function unwritableRegistry(string $file): self
    {
        return new self("The worktree registry at [{$file}] could not be written.");
    }

    public static function lockFailed(string $file): self
    {
        return new self("Could not open the lock file [{$file}].");
    }

    public static function lockTimeout(string $file, int $seconds): self
    {
        return new self("Another worktree command on this repository held the lock [{$file}] for more than {$seconds} seconds. Run this again once it has finished.");
    }

    public static function detachedWorktree(string $path): self
    {
        return new self("The worktree at [{$path}] has no branch checked out, so there is nothing to push or merge. Finish it with --abandon.");
    }

    public static function ambiguousWorktree(string $name): self
    {
        return new self("More than one worktree matches [{$name}]. Name the one to finish by its path.");
    }

    public static function linkedFile(string $path): self
    {
        return new self("[{$path}] in the worktree is a link that could not be removed, and writing to it would change what it points at. Replace it with a file or directory of its own, then run this again.");
    }

    public static function nameTaken(string $name, string $other, string $path): self
    {
        return new self("[{$name}] would share its databases and site with [{$other}] at [{$path}]. Pick a name no other worktree has (another branch, or another --name for worktree:adopt), or finish that worktree first.");
    }
}
