<?php

declare(strict_types=1);

/*
 * Sets up the git worktree this runs in with its own databases, .env,
 * dependencies and local site, through `php artisan worktree:adopt` in the
 * main checkout. Point a tool's worktree setup script at it:
 *
 *     php worktree-adopt.php
 *
 * Any options are passed on, such as `php worktree-adopt.php --clone`.
 * Artisan runs from the main checkout because a worktree another tool has
 * just made has no vendor directory yet.
 */

$git = static function (array $arguments): string {
    $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);

    if (! is_resource($process)) {
        return '';
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return proc_close($process) === 0 ? $output : '';
};

// The top of the worktree, wherever in it this runs, and the main checkout,
// which git lists first. The main checkout is not found through
// --git-common-dir, which points elsewhere when the repository keeps its git
// directory apart from its files.
$worktree = trim($git(['rev-parse', '--show-toplevel']));
$main = preg_match('/^worktree (.+)$/m', $git(['worktree', 'list', '--porcelain']), $match) === 1 ? trim($match[1]) : '';

if ($worktree === '' || $main === '') {
    fwrite(STDERR, "worktree-adopt: run this inside a git worktree of the application.\n");

    exit(1);
}

$process = proc_open(
    [PHP_BINARY, '-d', 'display_errors=stderr', $main.'/artisan', 'worktree:adopt', $worktree, ...array_slice($argv, 1)],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $main,
);

exit(is_resource($process) ? proc_close($process) : 1);
