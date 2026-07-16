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

/**
 * @return array<string, array<string, mixed>>
 */
function serverConnections(): array
{
    return [
        'mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'root', 'password' => ''],
        'pgsql' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'username' => 'postgres', 'password' => 'postgres'],
    ];
}

function serverPdo(string $driver): PDO
{
    $config = serverConnections()[$driver];
    $dsn = $driver === 'pgsql'
        ? "pgsql:host={$config['host']};port={$config['port']};dbname=postgres"
        : "mysql:host={$config['host']};port={$config['port']}";

    return new PDO($dsn, (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * The server-database path is the package's main job, so it runs against a real
 * server rather than a mock. CI provides both; locally Herd's MySQL is picked up
 * and Postgres is skipped unless one happens to be running.
 */
function serverAvailable(string $driver): bool
{
    static $available = [];

    if (! array_key_exists($driver, $available)) {
        try {
            serverPdo($driver);
            $available[$driver] = true;
        } catch (Throwable) {
            $available[$driver] = false;
        }
    }

    return $available[$driver];
}

function useServer(string $driver): void
{
    config()->set('database.default', $driver);
    config()->set("database.connections.{$driver}", serverConnections()[$driver]);
}

function databaseExists(string $driver, string $name): bool
{
    $pdo = serverPdo($driver);

    $sql = $driver === 'pgsql'
        ? 'SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($name)
        : 'SHOW DATABASES LIKE '.$pdo->quote($name);

    return $pdo->query($sql)->fetchColumn() !== false;
}

function dropDatabase(string $driver, string $name): void
{
    try {
        $pdo = serverPdo($driver);

        $pdo->exec($driver === 'pgsql'
            ? 'DROP DATABASE IF EXISTS "'.str_replace('"', '""', $name).'" WITH (FORCE)'
            : 'DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`');
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

it('strips quotes a shell may leave around the branch', function () {
    // Warp substitutes a blank text param as '', and a mis-quoted command hands
    // that through verbatim; the resolved path must still be the real branch's.
    $expected = Worktree::make(base_path(), 'feature/login', config('worktree'))->path();

    $this->artisan('worktree:path', ['branch' => "'feature/login'"])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('rejects a branch that is only quotes', function () {
    $this->artisan('worktree:path', ['branch' => "''"])
        ->assertFailed();
});

it('auto-generates when the branch arrives as empty quotes', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => "''", '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // No "repo-''" worktree; a generated branch instead.
        $dirs = glob(dirname($repo).'/'.basename($repo).'-*') ?: [];

        expect($dirs)->toHaveCount(1)
            ->and(basename($dirs[0]))->toContain('feature-auto-')
            ->and(basename($dirs[0]))->not->toContain("'");
    } finally {
        removeRepo($repo);
    }
});

it('prints the path last with --print-path', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    // Worktree::path() normalizes to forward slashes; match that.
    $worktree = str_replace('\\', '/', dirname($repo)).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--print-path' => true])
            ->expectsOutputToContain($worktree)
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
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

it('gives the worktree a server database of its own', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))->toContain('DB_DATABASE='.$slug)
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))->toContain('value="'.$slug.'_testing"')
            ->and(databaseExists($driver, $slug))->toBeTrue()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeTrue();

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'_testing');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('quotes a database name that needs it', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);
    config()->set('worktree.database.test.suffix', '-testing');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(databaseExists($driver, $slug.'-testing'))->toBeTrue();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'-testing');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('drops a server database that still has a connection open', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // Postgres refuses a plain DROP while anyone is connected, which is why
        // dropStatement() uses WITH (FORCE). Hold a connection so it matters.
        $config = serverConnections()[$driver];
        $dsn = $driver === 'pgsql'
            ? "pgsql:host=127.0.0.1;port={$config['port']};dbname={$slug}"
            : "mysql:host=127.0.0.1;port={$config['port']};dbname={$slug}";
        $held = new PDO($dsn, (string) $config['username'], (string) $config['password']);
        $held->query('SELECT 1');

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'_testing');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

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
    useServer('mysql');

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
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
})->skip(fn (): bool => ! serverAvailable('mysql'), 'needs a MySQL server on 127.0.0.1');

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

it('sets up into an empty directory left behind by an earlier teardown', function () {
    // Windows can hold a handle open long enough that the final rmdir fails and
    // an empty directory survives. git populates one happily, so it must not
    // stop the branch being set up again.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    mkdir($worktree);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(is_file($worktree.'/.git'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('refuses a worktree directory that has something in it', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    mkdir($worktree);
    file_put_contents($worktree.'/mine.txt', "not mine to delete\n");

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true]);
    } finally {
        removeRepo($repo);
    }
})->throws(WorktreeException::class, 'already exists');

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

it('says how to discard a branch when nothing confirmed it', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true])
            ->expectsConfirmation('Discard all changes on [feature/login]?', 'no');
    } finally {
        removeRepo($repo);
    }
})->throws(WorktreeException::class, 'Pass --force to discard it without being asked.');

it('refuses to run from a linked worktree', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // Every command derives names from the base path, so from inside a
        // worktree setup would mis-name things and teardown would auto-select
        // the main repository as the only candidate to destroy.
        $this->app->setBasePath($worktree);

        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('linked worktree')
            ->assertFailed();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('linked worktree')
            ->assertFailed();

        $this->artisan('worktree:path', ['branch' => 'feature/login'])
            ->expectsOutputToContain('linked worktree')
            ->assertFailed();
    } finally {
        $this->app->setBasePath($repo);
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
