<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Worktree;

function tempRepo(): string
{
    $repo = sys_get_temp_dir().'/wt-repo-'.bin2hex(random_bytes(4));
    mkdir($repo);

    file_put_contents($repo.'/.env', implode("\n", [
        'APP_URL=https://'.basename($repo).'.test',
        'APP_HOST='.basename($repo).'.test',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=main_app',
    ])."\n");

    file_put_contents($repo.'/phpunit.xml', implode("\n", [
        '<?xml version="1.0"?>',
        '<phpunit>',
        '    <php>',
        '        <env name="DB_DATABASE" value="testing"/>',
        '    </php>',
        '</phpunit>',
    ])."\n");

    foreach ([
        ['git', 'init', '-b', 'main'],
        ['git', 'config', 'user.email', 'test@example.com'],
        ['git', 'config', 'user.name', 'Test'],
        ['git', 'add', '-A'],
        ['git', 'commit', '-m', 'init'],
    ] as $command) {
        Process::path($repo)->run($command)->throw();
    }

    return $repo;
}

function removeRepo(string $repo): void
{
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    foreach ([$worktree, $repo] as $path) {
        if (is_dir($path)) {
            Process::run(PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', 'rmdir', '/s', '/q', $path] : ['rm', '-rf', $path]);
        }
    }
}

beforeEach(function () {
    config()->set('worktree.herd', 'none');
    config()->set('worktree.steps', []);
    config()->set('database.default', 'sqlite');
});

it('registers the worktree commands', function () {
    expect(array_keys(Artisan::all()))
        ->toContain('worktree:setup')
        ->toContain('worktree:teardown')
        ->toContain('worktree:path');
});

it('prints the resolved worktree path', function () {
    $expected = Worktree::make(base_path(), 'feature/login', config('worktree'))->path();

    $this->artisan('worktree:path', ['branch' => 'feature/login'])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('creates a worktree and rewrites its environment', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
        $slug = mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($repo).'-feature-login'));

        expect(is_dir($worktree))->toBeTrue()
            ->and((string) file_get_contents($worktree.'/.env'))
            ->toContain('DB_DATABASE='.$slug)
            ->toContain('APP_URL=http://'.basename($repo).'-feature-login.test')
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))
            ->toContain('value="'.$slug.'_testing"');
    } finally {
        removeRepo($repo);
    }
});

it('abandons a worktree and removes it', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
        expect(is_dir($worktree))->toBeTrue();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});
