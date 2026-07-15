<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use FilesystemIterator;

/**
 * Recursively deletes a directory without ever following a symlink or a Windows
 * junction. Laravel's Filesystem::deleteDirectory() cannot remove a junction,
 * because Windows reports one as neither a link nor a directory and only rmdir()
 * will unlink it. That is exactly what "php artisan storage:link" leaves inside a
 * worktree, and it is what stops "git worktree remove" from clearing the
 * directory.
 */
class Directory
{
    public static function isEmpty(string $path): bool
    {
        return is_dir($path) && ! (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS))->valid();
    }

    public static function delete(string $path): bool
    {
        if (! is_dir($path)) {
            return ! file_exists($path);
        }

        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            self::deleteEntry($item->getPathname());
        }

        return self::removeEmptyDirectory($path);
    }

    /**
     * Windows keeps a directory handle open for a while after its contents are
     * deleted, so rmdir() can fail on a directory that is already empty. Clearing
     * out a vendor and node_modules tree can hold it well past a second, hence the
     * generous last wait. A directory that outlives this is left for the caller to
     * report; it no longer blocks anything, since setup accepts an empty one.
     */
    protected static function removeEmptyDirectory(string $path): bool
    {
        foreach ([0, 50_000, 150_000, 400_000, 1_000_000, 2_000_000] as $wait) {
            if ($wait > 0) {
                usleep($wait);
            }

            clearstatcache(true, $path);

            if (@rmdir($path) || ! is_dir($path)) {
                return true;
            }
        }

        return false;
    }

    protected static function deleteEntry(string $path): void
    {
        // A real symlink. unlink() covers the unix case; a Windows directory
        // symlink needs rmdir() instead.
        if (is_link($path)) {
            if (! @unlink($path)) {
                @rmdir($path);
            }

            return;
        }

        if (is_dir($path)) {
            self::delete($path);

            return;
        }

        if (is_file($path)) {
            @unlink($path);

            return;
        }

        // Neither link, directory, nor file: a Windows junction, which is what
        // Laravel's storage:link creates. Only rmdir() removes it, and it must
        // not be followed or the target's contents would be deleted too.
        if (! @rmdir($path)) {
            @unlink($path);
        }
    }
}
