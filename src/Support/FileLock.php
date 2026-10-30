<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Closure;
use Illuminate\Support\Sleep;
use Mozex\Worktree\Exceptions\WorktreeException;

/**
 * An exclusive lock on a file, so two commands on one repository (agents
 * setting up worktrees side by side) take turns where they would trip over
 * each other. The operating system drops the lock when its process exits, so
 * a run that crashed leaves nothing stale behind.
 */
class FileLock
{
    /**
     * @var resource|null
     */
    protected $handle = null;

    public function __construct(protected string $file) {}

    /**
     * Waits for the lock, checking four times a second, up to the given
     * number of seconds.
     *
     * @param  Closure(): void  $waiting  Called once, when another process holds the lock.
     */
    public function acquire(int $seconds, Closure $waiting): void
    {
        $directory = dirname($this->file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $handle = @fopen($this->file, 'c');

        if ($handle === false) {
            throw WorktreeException::lockFailed($this->file);
        }

        for ($attempt = 0; ! flock($handle, LOCK_EX | LOCK_NB); $attempt++) {
            if ($attempt === 0) {
                $waiting();
            }

            if ($attempt >= $seconds * 4) {
                fclose($handle);

                throw WorktreeException::lockTimeout($this->file, $seconds);
            }

            Sleep::for(250)->milliseconds();
        }

        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);

        $this->handle = null;
    }
}
