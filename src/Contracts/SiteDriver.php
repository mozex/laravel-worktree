<?php

declare(strict_types=1);

namespace Mozex\Worktree\Contracts;

use Mozex\Worktree\Worktree;

/**
 * Serves a worktree as a local site through one kind of server, and stops
 * serving it on teardown. A driver never starts a process itself: it hands each
 * command to the runner, which keeps the main app's environment away from the
 * child and stops a command that hangs.
 *
 * Every method is best effort. A failure is reported through the runner as a
 * warning and never stops setup or teardown.
 */
interface SiteDriver
{
    /**
     * Whether the server's tools are on this machine. A driver that is not
     * available is replaced by one that serves nothing.
     */
    public function available(): bool;

    /**
     * The address the worktree answers at once served, or null when this
     * driver serves nothing.
     */
    public function url(Worktree $worktree, bool $secure): ?string;

    /**
     * Serves the worktree's directory under its name, over HTTPS when secure
     * is true. Returns false when the site could not be served.
     */
    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool;

    /**
     * Whether the server lists the site, or null when it cannot tell.
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool;

    /**
     * Stops serving the site. The worktree directory may already be gone, and
     * the worktree may have no branch, so only its name and path are known.
     */
    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void;

    /**
     * Added to the warning when one of the driver's commands runs out of time,
     * to say what the server may be waiting on. Empty for nothing to add.
     */
    public function timeoutHint(): string;
}
