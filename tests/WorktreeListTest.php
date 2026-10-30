<?php

declare(strict_types=1);

use Mozex\Worktree\Support\WorktreeList;

it('parses the porcelain worktree list', function () {
    $porcelain = <<<'TXT'
    worktree /work/www/blog
    HEAD 1111111111111111111111111111111111111111
    branch refs/heads/main

    worktree /work/www/blog-feature-login
    HEAD 2222222222222222222222222222222222222222
    branch refs/heads/feature/login
    TXT;

    expect(WorktreeList::parse($porcelain))->toBe([
        ['path' => '/work/www/blog', 'branch' => 'main'],
        ['path' => '/work/www/blog-feature-login', 'branch' => 'feature/login'],
    ]);
});

it('handles a detached worktree without a branch', function () {
    $porcelain = <<<'TXT'
    worktree /work/www/blog
    HEAD 1111111111111111111111111111111111111111
    detached
    TXT;

    expect(WorktreeList::parse($porcelain))->toBe([
        ['path' => '/work/www/blog', 'branch' => null],
    ]);
});

it('normalizes windows paths', function () {
    $porcelain = "worktree C:\\code\\blog\nHEAD abc\nbranch refs/heads/main";

    expect(WorktreeList::parse($porcelain)[0]['path'])->toBe('C:/code/blog');
});

it('returns nothing for empty input', function () {
    expect(WorktreeList::parse(''))->toBe([]);
});

it('lists the worktrees git has locked, with or without a reason', function () {
    $porcelain = <<<'TXT'
    worktree /work/www/blog
    HEAD 1111111111111111111111111111111111111111
    branch refs/heads/main

    worktree /work/www/blog-usb
    HEAD 2222222222222222222222222222222222222222
    branch refs/heads/usb
    locked on a drive that is not always mounted

    worktree /work/www/blog-held
    HEAD 3333333333333333333333333333333333333333
    detached
    locked

    worktree /work/www/blog-gone
    HEAD 4444444444444444444444444444444444444444
    branch refs/heads/gone
    prunable gitdir file points to non-existent location
    TXT;

    expect(WorktreeList::locked($porcelain))->toBe(['/work/www/blog-usb', '/work/www/blog-held']);
});
