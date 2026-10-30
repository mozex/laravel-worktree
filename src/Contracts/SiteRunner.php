<?php

declare(strict_types=1);

namespace Mozex\Worktree\Contracts;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Runs a site driver's commands for one worktree command.
 */
interface SiteRunner
{
    /**
     * Runs a command in the given directory, or in the main repository when it
     * is null. Returns null when the command did not run to its end: it ran
     * out of time, could not start, or an earlier command of the same driver
     * ran out of time, after which the rest are skipped. Each of these is
     * already reported as a warning; a command that ran and failed is
     * returned for the driver to judge.
     *
     * @param  list<string>  $command
     */
    public function run(array $command, ?string $path = null): ?ProcessResult;

    /**
     * Reports something the user should know, without stopping the command.
     */
    public function warn(string $message): void;
}
