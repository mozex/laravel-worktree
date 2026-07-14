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
    $porcelain = "worktree C:\\work\\www\\blog\nHEAD abc\nbranch refs/heads/main";

    expect(WorktreeList::parse($porcelain)[0]['path'])->toBe('C:/work/www/blog');
});

it('returns nothing for empty input', function () {
    expect(WorktreeList::parse(''))->toBe([]);
});
