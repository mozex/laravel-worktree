<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Worktree;

function tempRepo(): string
{
    $repo = sys_get_temp_dir().'/wt-repo-'.bin2hex(random_bytes(4));
    mkdir($repo);

    // Mirrors a stock Laravel app: .env is ignored, phpunit.xml is tracked.
    file_put_contents($repo.'/.gitignore', ".env\n/vendor\ncomposer.lock\n");

    file_put_contents($repo.'/composer.json', '{"name":"mozex/wt-test","require":{}}'."\n");

    file_put_contents($repo.'/.env', implode("\n", [
        'APP_URL=https://'.basename($repo).'.test',
        'APP_HOST='.basename($repo).'.test',
        'DB_CONNECTION=sqlite',
        'DB_DATABASE=main_app',
        'WT_LEAK_CHECK=parent',
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

function slugFor(string $repo): string
{
    return mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($repo).'-feature-login'));
}

function mysqlPdo(): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * The server-database path is the package's main job, so it is exercised against
 * a real server when one is reachable (CI provides it, Herd provides it locally)
 * rather than mocked.
 */
function mysqlAvailable(): bool
{
    static $available = null;

    if ($available === null) {
        try {
            mysqlPdo();
            $available = true;
        } catch (Throwable) {
            $available = false;
        }
    }

    return $available;
}

function useMysql(): void
{
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => '',
    ]);
}

function databaseExists(string $name): bool
{
    $pdo = mysqlPdo();

    return $pdo->query('SHOW DATABASES LIKE '.$pdo->quote($name))->fetchColumn() !== false;
}

function dropDatabase(string $name): void
{
    try {
        mysqlPdo()->exec('DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`');
    } catch (Throwable) {
        // nothing to clean up when there is no server
    }
}

function commitInWorktree(string $worktree): void
{
    file_put_contents($worktree.'/feature.txt', "done\n");

    foreach ([['git', 'add', '-A'], ['git', 'commit', '-m', 'Add feature']] as $command) {
        Process::path($worktree)->run($command)->throw();
    }
}

function removeRepo(string $repo): void
{
    $worktrees = glob(dirname($repo).'/'.basename($repo).'-*') ?: [];

    foreach ([...$worktrees, $repo] as $path) {
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

        expect(is_dir($worktree))->toBeTrue()
            ->and((string) file_get_contents($worktree.'/.env'))
            ->toContain('APP_URL=http://'.basename($repo).'-feature-login.test')
            ->toContain('APP_HOST='.basename($repo).'-feature-login.test');
    } finally {
        removeRepo($repo);
    }
});

it('leaves a sqlite database to the worktree', function () {
    // A stock Laravel app: the sqlite file rides inside the worktree and the
    // suite runs in memory, so neither needs a name of this package's choosing.
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))
            ->toContain('DB_DATABASE=main_app')
            ->not->toContain(slugFor($repo))
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))
            ->toContain('value="testing"');
    } finally {
        removeRepo($repo);
    }
});

it('redirects an absolute sqlite path back into the worktree', function () {
    $repo = tempRepo();

    // What a real app resolves: config holds the source app's own file path.
    config()->set('database.connections.sqlite.database', $repo.'/database/database.sqlite');

    file_put_contents($repo.'/.env', str_replace(
        'DB_DATABASE=main_app',
        'DB_DATABASE='.$repo.'/database/database.sqlite',
        (string) file_get_contents($repo.'/.env'),
    ));
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = str_replace('\\', '/', dirname($repo)).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))
            ->toContain('DB_DATABASE='.$worktree.'/database/database.sqlite')
            ->and(is_file($worktree.'/database/database.sqlite'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('creates the sqlite file a stock Laravel app expects', function () {
    // Stock Laravel leaves DB_DATABASE unset and lets database_path() resolve it,
    // which already points inside the worktree. The file still has to exist for
    // migrate to run.
    $repo = tempRepo();
    config()->set('database.connections.sqlite.database', $repo.'/database/database.sqlite');

    file_put_contents($repo.'/.env', str_replace(
        "DB_DATABASE=main_app\n",
        '',
        (string) file_get_contents($repo.'/.env'),
    ));
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect(is_file($worktree.'/database/database.sqlite'))->toBeTrue()
            ->and((string) file_get_contents($worktree.'/.env'))->not->toContain('DB_DATABASE=');
    } finally {
        removeRepo($repo);
    }
});

it('warns when a sqlite file sits outside the repository', function () {
    $shared = sys_get_temp_dir().'/wt-shared-'.bin2hex(random_bytes(4)).'.sqlite';

    $repo = tempRepo();
    file_put_contents($repo.'/.env', str_replace(
        'DB_DATABASE=main_app',
        'DB_DATABASE='.$shared,
        (string) file_get_contents($repo.'/.env'),
    ));
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('outside the repository')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('gives the worktree a server database of its own', function () {
    useMysql();

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))->toContain('DB_DATABASE='.$slug)
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))->toContain('value="'.$slug.'_testing"')
            ->and(databaseExists($slug))->toBeTrue()
            ->and(databaseExists($slug.'_testing'))->toBeTrue();

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($slug))->toBeFalse()
            ->and(databaseExists($slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase($slug);
        dropDatabase($slug.'_testing');
        removeRepo($repo);
    }
})->skip(fn (): bool => ! mysqlAvailable(), 'needs a MySQL server on 127.0.0.1');

it('quotes a database name that needs it', function () {
    useMysql();
    config()->set('worktree.database.test.suffix', '-testing');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(databaseExists($slug.'-testing'))->toBeTrue();
    } finally {
        dropDatabase($slug);
        dropDatabase($slug.'-testing');
        removeRepo($repo);
    }
})->skip(fn (): bool => ! mysqlAvailable(), 'needs a MySQL server on 127.0.0.1');

it('leaves the worktree clean after setup', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
        $status = trim(Process::path($worktree)->run(['git', 'status', '--porcelain'])->output());

        expect($status)->toBe('');
    } finally {
        removeRepo($repo);
    }
});

it('does not leak the main app environment into worktree commands', function () {
    // Exactly what Laravel's dotenv does with the main .env: putenv plus the
    // superglobals. Symfony only passes on variables present in $_SERVER, so a
    // naive child inherits this and ignores the worktree's own .env, migrating
    // against the main database.
    putenv('WT_LEAK_CHECK=parent');
    $_ENV['WT_LEAK_CHECK'] = 'parent';
    $_SERVER['WT_LEAK_CHECK'] = 'parent';

    config()->set('worktree.steps', ['php -r "echo \'LEAK=\'.(getenv(\'WT_LEAK_CHECK\') ?: \'unset\');"']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])
            ->expectsOutputToContain('LEAK=unset')
            ->assertSuccessful();
    } finally {
        putenv('WT_LEAK_CHECK');
        unset($_ENV['WT_LEAK_CHECK'], $_SERVER['WT_LEAK_CHECK']);
        removeRepo($repo);
    }
});

it('patches phpunit even when there is no env file', function () {
    useMysql();

    $repo = tempRepo();
    unlink($repo.'/.env');
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/phpunit.xml'))
            ->toContain('value="'.$slug.'_testing"');
    } finally {
        dropDatabase($slug);
        dropDatabase($slug.'_testing');
        removeRepo($repo);
    }
})->skip(fn (): bool => ! mysqlAvailable(), 'needs a MySQL server on 127.0.0.1');

it('skips extra steps when dependencies are not installed', function () {
    config()->set('worktree.steps', ['exit 1']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('merges the branch into the target and cleans up', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        commitInWorktree($worktree);

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--into' => 'main',
            '--keep-database' => true,
        ])->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse()
            ->and(is_file($repo.'/feature.txt'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('asks for the merge target when it is not given', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        commitInWorktree($worktree);

        $this->artisan('worktree:teardown', ['--keep-database' => true])
            ->expectsQuestion('How do you want to finish this work?', 'merge')
            ->expectsQuestion('Which branch should this merge into?', 'main')
            ->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse()
            ->and(is_file($repo.'/feature.txt'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('leaves no directory behind when a step created a link', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // What "php artisan storage:link" leaves behind: git will not remove it,
        // so without cleanup the directory survives and blocks the next setup.
        mkdir($worktree.'/storage/app/public', 0777, true);
        (new Filesystem)->link($worktree.'/storage/app/public', $worktree.'/public-storage');

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse();

        // And the branch can be set up again, which the leftover used to prevent.
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('finishes the worktree chosen from the list', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    // git reports worktree paths with forward slashes, so the select keys are normalized too.
    $login = str_replace('\\', '/', dirname($repo).'/'.basename($repo).'-feature-login');
    $search = str_replace('\\', '/', dirname($repo).'/'.basename($repo).'-feature-search');

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/search', '--no-install' => true])
            ->assertSuccessful();

        $this->artisan('worktree:teardown', [
            '--abandon' => true,
            '--force' => true,
            '--keep-database' => true,
        ])
            ->expectsQuestion('Which worktree do you want to finish?', $search)
            ->assertSuccessful();

        expect(is_dir($search))->toBeFalse()
            ->and(is_dir($login))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('honors the configured env file when guarding the main database', function () {
    config()->set('worktree.env.source', '.env.local');
    config()->set('worktree.database.name', 'main_app');

    $repo = tempRepo();
    rename($repo.'/.env', $repo.'/.env.local');
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', [
            'branch' => 'feature/login',
            '--no-install' => true,
            '--no-database' => true,
        ])->assertSuccessful();

        // Only a server driver would really drop, and the guard has to stop it
        // before any connection is attempted.
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1']);

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Refusing to drop [main_app]')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('fails when the named worktree does not exist', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/nope', '--force' => true]);
    } finally {
        removeRepo($repo);
    }
})->throws(WorktreeException::class, 'No worktree found matching [feature/nope].');

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
