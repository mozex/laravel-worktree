<?php

declare(strict_types=1);

use Mozex\Worktree\Worktree;

function makeWorktree(string $source, string $branch, array $overrides = []): Worktree
{
    $config = array_replace_recursive([
        'path' => '..',
        'host' => ['template' => '{repo}-{branch}', 'tld' => 'test'],
        'database' => ['name' => '{slug}', 'test' => ['suffix' => '_testing']],
    ], $overrides);

    return Worktree::make($source, $branch, $config);
}

it('derives names and hosts from the branch', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/login');

    expect($worktree->repository())->toBe('blog')
        ->and($worktree->branch())->toBe('feature/login')
        ->and($worktree->branchSlug())->toBe('feature-login')
        ->and($worktree->name())->toBe('blog-feature-login')
        ->and($worktree->host())->toBe('blog-feature-login.test')
        ->and($worktree->sourceHost())->toBe('blog.test');
});

it('builds database names that are safe for mysql and postgres', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/Login-Form.v2');

    expect($worktree->slug())->toBe('blog_feature_login_form_v2')
        ->and($worktree->appDatabase())->toBe('blog_feature_login_form_v2')
        ->and($worktree->testDatabase())->toBe('blog_feature_login_form_v2_testing');
});

it('places the worktree next to the source repository by default', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login')->path())
        ->toBe('/work/www/blog-feature-login');
});

it('supports a nested worktree directory', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login', ['path' => '.worktrees'])->path())
        ->toBe('/work/www/blog/.worktrees/blog-feature-login');
});

it('supports an absolute worktree directory', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login', ['path' => '/tmp/trees'])->path())
        ->toBe('/tmp/trees/blog-feature-login');
});

it('normalizes windows source paths', function () {
    $worktree = makeWorktree('C:\\work\\www\\blog', 'feature/login');

    expect($worktree->repository())->toBe('blog')
        ->and($worktree->path())->toBe('C:/work/www/blog-feature-login');
});

it('honors a custom host template', function () {
    $worktree = makeWorktree('/work/www/blog', 'main', [
        'host' => ['template' => 'wt-{branch}-{repo}', 'tld' => 'localhost'],
    ]);

    expect($worktree->host())->toBe('wt-main-blog.localhost');
});

it('maps a path inside the source repository onto the worktree', function () {
    $worktree = Worktree::make('/sites/blog', 'feature/login', []);

    expect($worktree->mapPath('/sites/blog/database/database.sqlite'))
        ->toBe('/sites/blog-feature-login/database/database.sqlite')
        ->and($worktree->mapPath('/sites/blog'))
        ->toBe('/sites/blog-feature-login');
});

it('maps a windows path inside the source repository', function () {
    $worktree = Worktree::make('C:\Sites\blog', 'feature/login', []);

    expect($worktree->mapPath('C:\Sites\blog\database\database.sqlite'))
        ->toBe('C:/Sites/blog-feature-login/database/database.sqlite');
});

it('refuses to map a path outside the source repository', function () {
    $worktree = Worktree::make('/sites/blog', 'feature/login', []);

    expect($worktree->mapPath('/var/data/shared.sqlite'))->toBeNull()
        ->and($worktree->mapPath('/sites/blog-other/db.sqlite'))->toBeNull();
});

it('honors a custom database name template', function () {
    $worktree = makeWorktree('/work/www/blog', 'main', [
        'database' => ['name' => 'wt_{slug}', 'test' => ['suffix' => '_test']],
    ]);

    expect($worktree->appDatabase())->toBe('wt_blog_main')
        ->and($worktree->testDatabase())->toBe('wt_blog_main_test');
});

it('caps database names at the server identifier limit', function () {
    // MySQL rejects names over 64 characters and Postgres truncates at 63,
    // after which the full name in phpunit.xml could never be connected to.
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $worktree = makeWorktree('/work/www/blog', $branch);

    expect(mb_strlen($worktree->testDatabase()))->toBeLessThanOrEqual(63)
        ->and($worktree->testDatabase())->toBe($worktree->appDatabase().'_testing')
        ->and($worktree->appDatabase())->toBe(makeWorktree('/work/www/blog', $branch)->appDatabase());
});

it('keeps two truncated branches on distinct databases', function () {
    $shared = 'feature/'.str_repeat('long-branch-segment-', 4);

    expect(makeWorktree('/work/www/blog', $shared.'one')->appDatabase())
        ->not->toBe(makeWorktree('/work/www/blog', $shared.'two')->appDatabase());
});
