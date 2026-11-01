<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Support\SiteList;
use Mozex\Worktree\Worktree;

/**
 * Serves worktrees through Laravel Valet, or one of the Valet ports that keep
 * its commands: valet-linux on Linux and valet-windows on Windows.
 *
 * Every command names the site: valet-linux's "unlink" without a name removes
 * the link named after its working directory, not the one linked from it.
 * Valet links synchronously and exits with an error when it cannot, so unlike
 * Herd's, its exit code is trusted and its list is not read after linking:
 * each extra Valet command may ask for a password.
 */
class ValetDriver extends Driver
{
    public function __construct(protected string $binary = 'valet') {}

    public function available(): bool
    {
        return static::executable($this->binary);
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        $name = $worktree->site();
        $path = $worktree->path();

        // "valet link" links its working directory.
        if (! $this->attempt($runner, [$this->binary, 'link', $name], $path, 'The site may need to be served manually.')) {
            return false;
        }

        return ! $secure || $this->attempt($runner, [$this->binary, 'secure', $name], $path, 'The site may need to be secured manually.');
    }

    /**
     * Valet prints its links only as a table.
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        $result = $runner->run([$this->binary, 'links']);

        if ($result === null || $result->failed()) {
            return null;
        }

        return SiteList::hasCell($result->output(), $name);
    }

    /**
     * Run from the main repository with the name given, so the site is removed
     * even when the worktree directory is already gone. "unsecure" runs only
     * for a secured site, since valet-linux asks for sudo to run it.
     */
    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        if ($secure) {
            $runner->run([$this->binary, 'unsecure', $name]);
        }

        $this->attempt($runner, [$this->binary, 'unlink', $name], null, 'The site may need to be removed manually.');
    }

    /**
     * Valet runs most of its commands through sudo on macOS, and valet-windows
     * asks for elevation, so a command with nobody to answer waits.
     */
    public function timeoutHint(): string
    {
        return 'It may be waiting for a password or an elevation prompt: run "valet trust" once on macOS, or use an elevated terminal on Windows.';
    }
}
