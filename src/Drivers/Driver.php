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
    public static function executable(string $binary): bool
    {
        if (str_contains($binary, '/') || str_contains($binary, '\\')) {
            return is_file($binary);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * Runs one command and says what to do by hand when it fails. A command
     * that did not run to its end was already reported by the runner.
     *
     * @param  list<string>  $command
     * @param  string|null  $path  Where it runs; null for the main repository.
     */
    protected function attempt(SiteRunner $runner, array $command, ?string $path, string $advice): bool
    {
        $result = $runner->run($command, $path);

        if ($result?->successful() === true) {
            return true;
        }

        if ($result !== null) {
            $runner->warn('Could not run ['.implode(' ', $command)."]. {$advice}");
        }

        return false;
    }
}
