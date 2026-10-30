<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\FileLock;

function lockFile(): string
{
    return sys_get_temp_dir().'/wt-lock-'.getmypid().'/nested/lock';
}

/**
 * Another holder of the lock. A second handle on the file conflicts with the
 * first one even within this process, on Windows and elsewhere.
 *
 * @return resource
 */
function holdLock(string $file)
{
    $handle = fopen($file, 'c');
    flock($handle, LOCK_EX);

    return $handle;
}

afterEach(function () {
    (new Filesystem)->deleteDirectory(dirname(lockFile(), 2));
});

it('takes a free lock without waiting', function () {
    Sleep::fake();
    $waited = false;

    $lock = new FileLock(lockFile());
    $lock->acquire(5, function () use (&$waited): void {
        $waited = true;
    });
    $lock->release();

    expect($waited)->toBeFalse()
        ->and(is_file(lockFile()))->toBeTrue();

    Sleep::assertNeverSlept();
});

it('says once that it waits, then gives up after the limit', function () {
    Sleep::fake();
    $waits = 0;

    (new FileLock(lockFile()))->acquire(1, fn () => null);
    $held = holdLock(lockFile());

    // Defined outside the arrow function, which would capture a copy of $waits.
    $waiting = function () use (&$waits): void {
        $waits++;
    };

    try {
        expect(fn () => (new FileLock(lockFile()))->acquire(2, $waiting))
            ->toThrow(WorktreeException::class, 'for more than 2 seconds');

        expect($waits)->toBe(1);

        Sleep::assertSleptTimes(8);
    } finally {
        fclose($held);
    }
});

it('lets the next holder in once released', function () {
    Sleep::fake();

    $first = new FileLock(lockFile());
    $first->acquire(1, fn () => null);
    $first->release();

    $second = new FileLock(lockFile());
    $second->acquire(1, fn () => throw new RuntimeException('should not wait'));
    $second->release();

    Sleep::assertNeverSlept();
});
