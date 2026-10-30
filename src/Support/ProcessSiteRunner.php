<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Contracts\SiteRunner;
use Symfony\Component\Process\Exception\RuntimeException;

/**
 * Runs a site driver's commands with the main app's .env keys unset, the same
 * as every other child process, and within a time limit. A server's CLI can
 * wait forever on its app (a dialog, an elevation prompt), so a command that
 * runs out of time is stopped and reported, and the driver's remaining
 * commands are skipped: teardown would otherwise wait out the limit once per
 * command.
 */
class ProcessSiteRunner implements SiteRunner
{
    protected bool $timedOut = false;

    /**
     * @param  string  $path  Where a command runs when given no directory: the main repository.
     * @param  array<string, false>  $environment  The main app's .env keys, each unset for the child.
     * @param  int  $timeout  Seconds a command may take; 0 for no limit.
     * @param  string  $hint  The driver's note on what a stuck command may be waiting on.
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        protected string $path,
        protected array $environment,
        protected int $timeout,
        protected string $hint,
        protected Closure $warn,
    ) {}

    public function run(array $command, ?string $path = null): ?ProcessResult
    {
        $label = implode(' ', $command);

        // Named, so a link teardown never removed can be removed by hand.
        if ($this->timedOut) {
            $this->warn("Skipped [{$label}] after the earlier timeout; run it yourself once the server responds.");

            return null;
        }

        try {
            return Process::path($path ?? $this->path)
                ->env($this->environment)
                ->timeout($this->timeout)
                ->run($command);
        } catch (ProcessTimedOutException) {
            $this->timedOut = true;

            $this->warn(trim("[{$label}] did not finish within {$this->timeout} seconds. {$this->hint}"));
        } catch (RuntimeException $exception) {
            // A missing working directory, or a binary that cannot be started.
            $this->warn("Could not start [{$label}]: {$exception->getMessage()}");
        }

        return null;
    }

    public function warn(string $message): void
    {
        ($this->warn)($message);
    }
}
