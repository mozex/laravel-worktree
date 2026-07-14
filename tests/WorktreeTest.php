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

it('honors a custom database name template', function () {
    $worktree = makeWorktree('/work/www/blog', 'main', [
        'database' => ['name' => 'wt_{slug}', 'test' => ['suffix' => '_test']],
    ]);

    expect($worktree->appDatabase())->toBe('wt_blog_main')
        ->and($worktree->testDatabase())->toBe('wt_blog_main_test');
});
