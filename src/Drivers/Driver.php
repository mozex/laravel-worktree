<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Mozex\Worktree\Contracts\SiteDriver;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Worktree;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The defaults most drivers share: always available, served at the worktree
 * host, no way to tell whether a site is listed, nothing to add on a timeout.
 */
abstract class Driver implements SiteDriver
{
    public function available(): bool
    {
        return true;
    }

    public function url(Worktree $worktree, bool $secure): ?string
    {
        return ($secure ? 'https' : 'http').'://'.$worktree->host();
    }

    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        return null;
    }

    public function timeoutHint(): string
    {
        return '';
    }

    /**
     * Whether a binary can be run: a configured path must be a file, a bare
     * name must be found on PATH. On Linux and macOS a name missing from PATH
     * is looked up once more with "command -v", which reads no env file.
     */
    protected function executable(string $binary): bool
    {
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }
}
