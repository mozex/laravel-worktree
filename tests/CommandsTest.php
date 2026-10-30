<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Drivers\Driver;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Worktree;

/**
 * Every repo tempRepo() made in the current test. afterEach() removes them all, so a
 * repo still gets cleaned up when a test's setup throws before its try/finally.
 */
final class TempRepos
{
    /** @var list<string> */
    public static array $created = [];

    /**
     * Where the default SQLite connection keeps its file: the testbench
     * skeleton, outside every test repo, so a setup gives each worktree a
     * sibling file there that afterEach() deletes.
     */
    public static string $sqliteDirectory = '';
}

/**
 * A driver a project writes for its own server, recording what it was asked.
 */
final class RecordingSiteDriver extends Driver
{
    /** @var list<string> */
    public static array $calls = [];

    public function url(Worktree $worktree, bool $secure): ?string
    {
        return 'https://'.$worktree->name().'.example';
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        self::$calls[] = 'serve '.$worktree->name();

        return true;
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        self::$calls[] = 'unserve '.$name;
    }
}

function tempRepo(): string
{
    $repo = sys_get_temp_dir().'/wt-repo-'.bin2hex(random_bytes(4));
    mkdir($repo);
    TempRepos::$created[] = $repo;

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
        // A developer's global core.fsmonitor=true would start a watcher daemon
        // for every test repo; keep the suite from spawning them at all.
        ['git', 'config', 'core.fsmonitor', 'false'],
        ['git', 'config', 'user.email', 'test@example.com'],
        ['git', 'config', 'user.name', 'Test'],
        ['git', 'config', 'core.autocrlf', 'false'],
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

function commitInWorktree(string $worktree): void
{
    file_put_contents($worktree.'/feature.txt', "done\n");

    foreach ([['git', 'add', '-A'], ['git', 'commit', '-m', 'Add feature']] as $command) {
        Process::path($worktree)->run($command)->throw();
    }
}

/**
 * Best effort, so a test's finally never hides its own failure: returns what it could
 * not remove, and afterEach() turns any leftover into a failure of its own.
 *
 * @return list<string>
 */
function removeRepo(string $repo): array
{
    $worktrees = glob(dirname($repo).'/'.basename($repo).'-*') ?: [];
    $leftovers = [];

    foreach ([...$worktrees, $repo] as $path) {
        // Windows can hold a handle in the tree for a moment (a scanner, a just-exited
        // git), so a failed pass is retried with backoff.
        foreach ([0, 100_000, 500_000, 1_000_000] as $wait) {
            clearstatcache(true, $path);

            if (! is_dir($path)) {
                continue 2;
            }

            usleep($wait);

            // rmdir is a cmd builtin that reads a forward slash as a switch ("Invalid
            // switch") and then deletes nothing, so hand it backslashes. Unlike PHP's
            // unlink(), it also removes git's read-only object files.
            Process::run(PHP_OS_FAMILY === 'Windows'
                ? ['cmd', '/c', 'rmdir', '/s', '/q', str_replace('/', '\\', $path)]
                : ['rm', '-rf', $path]);
        }

        clearstatcache(true, $path);

        if (is_dir($path)) {
            $leftovers[] = $path;
        }
    }

    return $leftovers;
}

beforeEach(function () {
    // No test may reach a real server: binaries that cannot exist keep "auto",
    // the legacy herd value, and the Lerd check from finding one on the machine.
    config()->set('worktree.site.driver', 'none');
    config()->set('worktree.site.drivers.herd.binary', 'wt-missing-herd');
    config()->set('worktree.site.drivers.valet.binary', 'wt-missing-valet');
    config()->set('worktree.site.lerd.binary', 'wt-missing-lerd');
    config()->set('worktree.steps', []);
    config()->set('database.default', 'sqlite');

    TempRepos::$sqliteDirectory = dirname((string) config('database.connections.sqlite.database'));
});

afterEach(function () {
    $created = TempRepos::$created;
    $leftovers = array_merge(...array_map(removeRepo(...), $created));
    TempRepos::$created = [];

    // Only the sibling files this test's worktrees got, named after their
    // repos, so a suite running beside this one keeps its own.
    foreach ($created as $repo) {
        foreach (glob(TempRepos::$sqliteDirectory.'/'.mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($repo))).'_*.sqlite*') ?: [] as $file) {
            @unlink($file);
        }
    }

    if ($leftovers !== []) {
        throw new RuntimeException('Could not remove test repositories: '.implode(', ', $leftovers));
    }
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

it('leaves the app url alone when no key is configured for it', function (?string $key) {
    // An empty key used to write a "=http://..." line into the worktree's .env.
    config()->set('worktree.env.app_url_key', $key);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $env = (string) file_get_contents(dirname($repo).'/'.basename($repo).'-feature-login/.env');

        // Only the host remap touched it, so the main app's scheme stays.
        expect($env)->not->toMatch('/^=/m')
            ->toContain('APP_URL=https://'.basename($repo).'-feature-login.test');
    } finally {
        removeRepo($repo);
    }
})->with(['null' => [null], 'empty' => ['']]);

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

/**
 * A main SQLite database kept outside the repository, holding two users, with
 * the .env and the resolved config both pointing at it.
 *
 * @return array{0: string, 1: string} The directory and the main file.
 */
function outsideSqlite(string $repo): array
{
    $dir = sys_get_temp_dir().'/wt-dbs-'.bin2hex(random_bytes(4));
    mkdir($dir);
    TempRepos::$created[] = $dir;
    $main = $dir.'/app.sqlite';

    $pdo = new PDO('sqlite:'.$main, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users (id integer PRIMARY KEY, name text)');
    $pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('bob')");
    unset($pdo);

    EnvFile::fromFile($repo.'/.env')->set('DB_DATABASE', $main)->save($repo.'/.env');
    config()->set('database.connections.sqlite.database', $main);

    return [str_replace('\\', '/', $dir), $main];
}

it('gives a sqlite file outside the repository a sibling of its own', function () {
    // Shared, the file would be wiped by the worktree's migrate:fresh. It is
    // handled like a server instead: a file of its own beside the main one.
    $repo = tempRepo();
    [$dir, $main] = outsideSqlite($repo);
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain($own)
            ->assertSuccessful();

        expect((string) file_get_contents($worktree.'/.env'))->toContain('DB_DATABASE='.$own)
            ->and(is_file($own))->toBeTrue()
            ->and(filesize($own))->toBe(0)
            ->and(sqliteRows($main, 'users'))->toBe(2);

        $this->artisan('worktree:list')
            ->expectsOutputToContain(slugFor($repo).'.sqlite')
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_file($own))->toBeFalse()
            ->and(sqliteRows($main, 'users'))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('clones a sqlite file outside the repository into its sibling', function () {
    $repo = tempRepo();
    [$dir, $main] = outsideSqlite($repo);
    $this->app->setBasePath($repo);
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->expectsOutputToContain("Cloning [{$own}]")
            ->assertSuccessful();

        expect(sqliteRows($own, 'users'))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('refuses a sibling sqlite file that would be the main one', function () {
    // A name template with no worktree token names the main file itself.
    config()->set('worktree.database.connections', [['connection' => null, 'env' => 'DB_DATABASE', 'name' => 'app']]);

    $repo = tempRepo();
    outsideSqlite($repo);
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->run();
        $this->fail('Setup should have refused the main database file.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain("is the main repository's own database")
            ->and(is_dir(dirname($repo).'/'.basename($repo).'-feature-login'))->toBeFalse();
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
    config()->set('worktree.database.connections', [[
        'connection' => null,
        'env' => 'DB_DATABASE',
        'name' => '{slug}',
        'test' => ['env' => 'DB_DATABASE', 'name' => '{slug}-testing'],
    ]]);

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

it('copies a dependency when the lock matches instead of installing', function () {
    config()->set('worktree.dependencies.vendor.copy', true);

    $repo = tempRepo();
    // A committed lock the worktree will match, and a vendor with a marker file
    // a real install would never produce.
    file_put_contents($repo.'/composer.lock', "{\"packages\":[]}\n");
    mkdir($repo.'/vendor');
    file_put_contents($repo.'/vendor/MARKER.txt', "copied\n");
    Process::path($repo)->run(['git', 'add', '-f', 'composer.lock'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'commit lock'])->throw();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect(is_file($worktree.'/vendor/MARKER.txt'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('installs a dependency when the lock does not match', function () {
    config()->set('worktree.dependencies.vendor.copy', true);

    // composer.lock is gitignored in tempRepo, so the worktree gets none to
    // match against and the copy is refused in favor of a real install.
    $repo = tempRepo();
    mkdir($repo.'/vendor');
    file_put_contents($repo.'/vendor/MARKER.txt', "copied\n");
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect(is_file($worktree.'/vendor/MARKER.txt'))->toBeFalse()
            ->and(is_file($worktree.'/vendor/autoload.php'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('installs instead of copying when the source holds a junction', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('junction handling is Windows-specific; cp preserves symlinks elsewhere');
    }

    // A Composer path repository leaves a junction in vendor. robocopy would
    // follow it and copy a whole external tree, so the copy must be refused.
    config()->set('worktree.dependencies.vendor.copy', true);

    $repo = tempRepo();
    // A real, committed lock so the copy is even attempted and the install
    // fallback (which runs against it) succeeds.
    Process::path($repo)->run(['composer', 'install', '--no-interaction'])->throw();
    Process::path($repo)->run(['git', 'add', '-f', 'composer.lock'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'lock'])->throw();

    // A path-repository style junction inside the source vendor.
    mkdir($repo.'/vendor/pkg', 0777, true);
    $external = sys_get_temp_dir().'/wt-junction-'.bin2hex(random_bytes(4));
    mkdir($external);
    file_put_contents($external.'/LIVE.txt', "external\n");
    // mklink is a cmd builtin that rejects forward slashes, so hand it backslashes.
    $link = str_replace('/', '\\', $repo.'/vendor/pkg/linked');
    Process::run(['cmd', '/c', 'mklink', '/J', $link, str_replace('/', '\\', $external)])->throw();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        // It installed (autoload present) rather than following the junction.
        expect(is_dir($worktree.'/vendor/pkg/linked'))->toBeFalse()
            ->and(is_file($worktree.'/vendor/autoload.php'))->toBeTrue();
    } finally {
        Process::run(['cmd', '/c', 'rmdir', $link]);
        (new Filesystem)->deleteDirectory($external);
        removeRepo($repo);
    }
});

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
    config()->set('worktree.database.connections', [[
        'connection' => null,
        'env' => 'DB_DATABASE',
        'name' => 'main_app',
    ]]);

    $repo = tempRepo();
    rename($repo.'/.env', $repo.'/.env.local');
    $this->app->setBasePath($repo);

    // Made by plain git, so teardown has no record and names the databases
    // from the configuration.
    Process::path($repo)->run(['git', 'worktree', 'add', dirname($repo).'/'.basename($repo).'-feature-login', '-b', 'feature/login'])->throw();

    try {
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

        $this->artisan('worktree:list')
            ->expectsOutputToContain('linked worktree')
            ->assertFailed();
    } finally {
        $this->app->setBasePath($repo);
        removeRepo($repo);
    }
});

it('resumes provisioning after a failed step', function () {
    // The failing step also reports on stdout only, covering the fallback that
    // keeps a silent stderr from producing a bare "Command [x] failed."
    config()->set('worktree.steps', ['php -r "echo \'BOOM-STDOUT\'; exit(1);"']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        try {
            $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])->run();
            $this->fail('Setup should have failed on the failing step.');
        } catch (WorktreeException $exception) {
            expect($exception->getMessage())->toContain('BOOM-STDOUT');
        }

        expect(is_dir($worktree))->toBeTrue();

        // A second run must pick the half-provisioned worktree back up rather
        // than demanding a teardown.
        config()->set('worktree.steps', []);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-migrate' => true])
            ->expectsOutputToContain('resuming')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('still refuses a worktree that belongs to another branch', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // The same directory, but requested for a different branch: resuming
        // would silently hand out a worktree on the wrong branch.
        config()->set('worktree.host.template', basename($repo).'-feature-login');

        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true, '--no-database' => true])->run();
        $this->fail('Setup should have refused the occupied directory.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain('already exists');
    } finally {
        removeRepo($repo);
    }
});

it('creates the test database on the phpunit connection', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a MySQL server on 127.0.0.1');
    }

    // The app itself stays on sqlite; only the suite runs against a server.
    config()->set('database.connections.mysql', serverConnections()['mysql']);

    $repo = tempRepo();
    file_put_contents($repo.'/phpunit.xml', str_replace(
        '<php>',
        "<php>\n        <env name=\"DB_CONNECTION\" value=\"mysql\"/>",
        (string) file_get_contents($repo.'/phpunit.xml'),
    ));
    Process::path($repo)->run(['git', 'commit', '-am', 'pin suite to mysql'])->throw();

    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect(databaseExists('mysql', $slug.'_testing'))->toBeTrue()
            ->and(databaseExists('mysql', $slug))->toBeFalse()
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))->toContain('value="'.$slug.'_testing"');

        // And teardown reads the same connection back before the file is gone.
        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
});

it('patches every configured phpunit file', function () {
    // A project running a second suite from a second config. An unpatched file
    // keeps the main repository's test database name, so that suite would run
    // against the main checkout's database from inside the worktree.
    useServer('mysql');

    $repo = tempRepo();
    copy($repo.'/phpunit.xml', $repo.'/phpunit.browser.xml');

    foreach ([['git', 'add', '-A'], ['git', 'commit', '-m', 'add a browser suite']] as $command) {
        Process::path($repo)->run($command)->throw();
    }

    config()->set('worktree.database.phpunit_files', ['phpunit.xml', 'phpunit.browser.xml']);

    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
        $index = Process::path($worktree)->run(['git', 'ls-files', '-v'])->output();

        expect((string) file_get_contents($worktree.'/phpunit.xml'))->toContain('value="'.$slug.'_testing"')
            ->and((string) file_get_contents($worktree.'/phpunit.browser.xml'))->toContain('value="'.$slug.'_testing"')
            // Both files are tracked, so both need the bit or the worktree is dirty.
            ->and($index)->toContain('S phpunit.xml')
            ->and($index)->toContain('S phpunit.browser.xml')
            ->and(databaseExists('mysql', $slug.'_testing'))->toBeTrue();

        // One name shared by two files is one database, and teardown drops it.
        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
})->skip(fn (): bool => ! serverAvailable('mysql'), 'needs a MySQL server on 127.0.0.1');

it('creates a test database on each phpunit file own connection', function () {
    // Two suites pinned to different servers share one test database NAME, so
    // setup creates it on both and teardown drops it from both. The app itself
    // stays on sqlite, which keeps this about the test databases alone.
    config()->set('database.connections.mysql', serverConnections()['mysql']);
    config()->set('database.connections.pgsql', serverConnections()['pgsql']);

    $repo = tempRepo();
    $template = (string) file_get_contents($repo.'/phpunit.xml');

    foreach (['phpunit.xml' => 'mysql', 'phpunit.browser.xml' => 'pgsql'] as $file => $connection) {
        file_put_contents($repo.'/'.$file, str_replace(
            '<php>',
            "<php>\n        <env name=\"DB_CONNECTION\" value=\"{$connection}\"/>",
            $template,
        ));
    }

    foreach ([['git', 'add', '-A'], ['git', 'commit', '-m', 'pin each suite to its own server']] as $command) {
        Process::path($repo)->run($command)->throw();
    }

    config()->set('worktree.database.phpunit_files', ['phpunit.xml', 'phpunit.browser.xml']);

    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug.'_testing'))->toBeTrue()
            ->and(databaseExists('pgsql', $slug.'_testing'))->toBeTrue();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug.'_testing'))->toBeFalse()
            ->and(databaseExists('pgsql', $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase('mysql', $slug.'_testing');
        dropDatabase('pgsql', $slug.'_testing');
        removeRepo($repo);
    }
})->skip(
    fn (): bool => ! serverAvailable('mysql') || ! serverAvailable('pgsql'),
    'needs both a MySQL and a PostgreSQL server on 127.0.0.1',
);

it('isolates a second database connection', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);
    // A second connection living on the same server.
    config()->set('database.connections.secondary', serverConnections()[$driver]);
    config()->set('worktree.database.connections', [
        [
            'connection' => null,
            'env' => 'DB_DATABASE',
            'name' => '{slug}',
            'test' => ['env' => 'DB_DATABASE', 'name' => '{slug}_testing'],
        ],
        [
            'connection' => 'secondary',
            'env' => 'SECONDARY_DB_DATABASE',
            'name' => '{slug}_secondary',
            'test' => ['env' => 'SECONDARY_DB_DATABASE', 'name' => '{slug}_secondary_testing'],
        ],
    ]);

    $repo = tempRepo();
    file_put_contents($repo.'/.env', "SECONDARY_DB_DATABASE=main_secondary\n", FILE_APPEND);
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $names = [$slug, $slug.'_testing', $slug.'_secondary', $slug.'_secondary_testing'];

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))
            ->toContain('DB_DATABASE='.$slug)
            ->toContain('SECONDARY_DB_DATABASE='.$slug.'_secondary')
            ->and((string) file_get_contents($worktree.'/phpunit.xml'))
            ->toContain('value="'.$slug.'_secondary_testing"');

        foreach ($names as $name) {
            expect(databaseExists($driver, $name))->toBeTrue();
        }

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        foreach ($names as $name) {
            expect(databaseExists($driver, $name))->toBeFalse();
        }
    } finally {
        foreach ($names as $name) {
            dropDatabase($driver, $name);
        }
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('refuses two connections that resolve to the same database', function () {
    // The guard only inspects driver, host, and name, so it needs no live server.
    config()->set('database.connections.primary', ['driver' => 'mysql', 'host' => '127.0.0.1']);
    config()->set('database.connections.other', ['driver' => 'mysql', 'host' => '127.0.0.1']);
    config()->set('worktree.database.connections', [
        ['connection' => 'primary', 'env' => 'DB_DATABASE', 'name' => '{slug}'],
        ['connection' => 'other', 'env' => 'OTHER_DB_DATABASE', 'name' => '{slug}'],
    ]);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true]);
    } finally {
        removeRepo($repo);
    }
})->throws(WorktreeException::class, 'distinct name');

it('copies gitignored extra env files with the host remapped', function () {
    $repo = tempRepo();
    file_put_contents($repo.'/.gitignore', ".env\n.env.testing\n/vendor\ncomposer.lock\n");
    file_put_contents($repo.'/.env.testing', "APP_ENV=testing\nAPP_URL=https://".basename($repo).".test\n");
    Process::path($repo)->run(['git', 'commit', '-am', 'ignore env.testing'])->throw();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
        $status = trim(Process::path($worktree)->run(['git', 'status', '--porcelain'])->output());

        expect((string) file_get_contents($worktree.'/.env.testing'))
            ->toContain('APP_URL=https://'.basename($repo).'-feature-login.test')
            ->and($status)->toBe('');
    } finally {
        removeRepo($repo);
    }
});

it('refuses to copy an extra env file that is not gitignored', function () {
    // Copying it would leave an untracked file: merge teardowns would refuse
    // to run and --pr would commit local env values into the branch.
    $repo = tempRepo();
    file_put_contents($repo.'/.env.testing', "APP_ENV=testing\n");
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('not gitignored')
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect(is_file($worktree.'/.env.testing'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('applies the configured env replacements to the worktree', function () {
    config()->set('worktree.env.replace', [
        'REDIS_PREFIX' => '{value}{slug}_',
        'CACHE_PREFIX' => '{slug}_cache_',
    ]);

    $repo = tempRepo();
    // A key that already has a value (prefix is appended) and one that does not
    // (the key is added). REDIS_PREFIX rides in the gitignored .env.
    file_put_contents($repo.'/.env', "REDIS_PREFIX=laravel_database_\n", FILE_APPEND);
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env'))
            ->toContain('REDIS_PREFIX=laravel_database_'.$slug.'_')
            ->toContain('CACHE_PREFIX='.$slug.'_cache_');
    } finally {
        removeRepo($repo);
    }
});

it('applies env replacements to copied extra env files', function () {
    config()->set('worktree.env.replace', ['REDIS_PREFIX' => '{value}{slug}_']);

    $repo = tempRepo();
    file_put_contents($repo.'/.gitignore', ".env\n.env.testing\n/vendor\ncomposer.lock\n");
    file_put_contents($repo.'/.env.testing', "REDIS_PREFIX=testing_\n");
    Process::path($repo)->run(['git', 'commit', '-am', 'ignore env.testing'])->throw();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

        expect((string) file_get_contents($worktree.'/.env.testing'))
            ->toContain('REDIS_PREFIX=testing_'.$slug.'_');
    } finally {
        removeRepo($repo);
    }
});

it('lists worktrees with their hosts', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/search', '--no-install' => true])
            ->assertSuccessful();

        // Output expectations are ordered and one line satisfies only one of
        // them, so each row gets a single assertion: the first row's branch,
        // then the second row's path.
        $this->artisan('worktree:list')
            ->expectsOutputToContain('feature/login')
            ->expectsOutputToContain(basename($repo).'-feature-search')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('reports when there is nothing to list', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:list')
            ->expectsOutputToContain('No worktrees.')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('refuses to push or merge a detached worktree', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $detached = dirname($repo).'/'.basename($repo).'-detached';
    Process::path($repo)->run(['git', 'worktree', 'add', '--detach', $detached])->throw();

    try {
        $this->artisan('worktree:teardown', ['name' => basename($detached), '--pr' => true])->run();
        $this->fail('Teardown should have refused the detached worktree.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain('no branch checked out');
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

it('drops the parallel-test databases a worktree left behind', function (string $driver) {
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

        // Test runs create these outside the worktree flow: a lane database
        // from mozex/laravel-test-lanes and a paratest worker database.
        $manager = new DatabaseManager(serverConnections()[$driver]);
        $manager->create($slug.'_testing_test_lane1');
        $manager->create($slug.'_testing_test_2');

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($driver, $slug.'_testing_test_lane1'))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing_test_2'))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeFalse()
            ->and(databaseExists($driver, $slug))->toBeFalse();
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_testing_test_lane1', $slug.'_testing_test_2'] as $name) {
            dropDatabase($driver, $name);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('keeps a sibling worktree whose name extends the derivative prefix', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    $manager = new DatabaseManager(serverConnections()[$driver]);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // What a live worktree on branch "feature/login-test-helpers" owns:
        // its slug starts with this worktree's slug plus "_test_", so a bare
        // LIKE sweep would destroy it. Also cover the application-database
        // discovery path with a worker derivative of the app database itself.
        $manager->create($slug.'_test_helpers');
        $manager->create($slug.'_test_helpers_testing');
        $manager->create($slug.'_test_4');

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($driver, $slug.'_test_helpers'))->toBeTrue()
            ->and(databaseExists($driver, $slug.'_test_helpers_testing'))->toBeTrue()
            ->and(databaseExists($driver, $slug.'_test_4'))->toBeFalse();
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_test_helpers', $slug.'_test_helpers_testing', $slug.'_test_4'] as $name) {
            dropDatabase($driver, $name);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('refuses a derivative that matches the main database', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a MySQL server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    $manager = new DatabaseManager(serverConnections()['mysql']);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // Pathological on purpose: the main .env now claims a database whose
        // name is exactly a discoverable derivative. The per-derivative guard
        // must skip it while everything else still drops.
        $manager->create($slug.'_testing_test_lane1');
        EnvFile::fromFile($repo.'/.env')
            ->set('DB_DATABASE', $slug.'_testing_test_lane1')
            ->save($repo.'/.env');

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain("Refusing to drop [{$slug}_testing_test_lane1]")
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug.'_testing_test_lane1'))->toBeTrue()
            ->and(databaseExists('mysql', $slug.'_testing'))->toBeFalse()
            ->and(databaseExists('mysql', $slug))->toBeFalse();
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_testing_test_lane1'] as $name) {
            dropDatabase('mysql', $name);
        }

        removeRepo($repo);
    }
});

/**
 * A main database with rows worth cloning, plus a queue table whose pending
 * job must stay behind.
 */
function sourceDatabase(string $driver, string $database): void
{
    dropDatabase($driver, $database);
    (new DatabaseManager(serverConnections()[$driver]))->create($database);

    $pdo = serverPdo($driver, $database);
    $pdo->exec('CREATE TABLE users (id integer PRIMARY KEY, name varchar(50))');
    $pdo->exec('CREATE TABLE jobs (id integer PRIMARY KEY, payload text)');
    $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'ada'), (2, 'bob')");
    $pdo->exec("INSERT INTO jobs (id, payload) VALUES (1, 'send-invoice')");
}

/**
 * Points the main app at a database the way a real app is: the resolved
 * connection config and the .env both name it.
 */
function useDatabase(string $repo, string $driver, string $database): void
{
    config()->set("database.connections.{$driver}.database", $database);
    EnvFile::fromFile($repo.'/.env')->set('DB_DATABASE', $database)->save($repo.'/.env');
}

function rowCount(string $driver, string $database, string $table): int
{
    return (int) serverPdo($driver, $database)->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}

/**
 * A stock Laravel SQLite app: the file is gitignored by database/.gitignore,
 * DB_DATABASE is unset, and the resolved config holds the absolute path.
 */
function sqliteRepo(): string
{
    $repo = tempRepo();

    mkdir($repo.'/database');
    file_put_contents($repo.'/database/.gitignore', "*.sqlite*\n");
    file_put_contents($repo.'/.env', str_replace("DB_DATABASE=main_app\n", '', (string) file_get_contents($repo.'/.env')));

    Process::path($repo)->run(['git', 'add', '-A'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'database'])->throw();

    $pdo = new PDO('sqlite:'.$repo.'/database/database.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE users (id integer PRIMARY KEY, name text)');
    $pdo->exec('CREATE TABLE jobs (id integer PRIMARY KEY, payload text)');
    $pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('bob')");
    $pdo->exec("INSERT INTO jobs (payload) VALUES ('send-invoice')");

    config()->set('database.connections.sqlite.database', $repo.'/database/database.sqlite');

    return $repo;
}

/**
 * Opens the file only for the count, so no handle outlives it and blocks the
 * repo's removal on Windows.
 */
function sqliteRows(string $file, string $table): int
{
    $pdo = new PDO('sqlite:'.$file);

    return (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}

/**
 * An artisan stand-in that records what it was asked to run, so the
 * migration command is observed without a Laravel app in the worktree.
 */
function recordingArtisan(string $repo): void
{
    file_put_contents($repo.'/artisan', "<?php\nfile_put_contents(__DIR__.'/artisan.log', implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);\n");
    file_put_contents($repo.'/.gitignore', "artisan.log\n", FILE_APPEND);
    Process::path($repo)->run(['git', 'add', '-A'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'artisan'])->throw();
}

/**
 * The busy-source fallback shells out to pg_dump, which refuses a server
 * newer than itself.
 */
function pgToolsMatchServer(): bool
{
    try {
        $client = Process::run(['pg_dump', '--version']);

        if ($client->failed() || preg_match('/\)\s*(\d+)/', $client->output(), $matches) !== 1) {
            return false;
        }

        $server = (int) serverPdo('pgsql')->query('SHOW server_version_num')->fetchColumn();

        return (int) $matches[1] >= intdiv($server, 10000);
    } catch (Throwable) {
        return false;
    }
}

it('clones the main database into the worktree', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $source = 'wt_main_'.bin2hex(random_bytes(3));

    try {
        sourceDatabase($driver, $source);
        useDatabase($repo, $driver, $source);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->expectsOutputToContain("Cloning [{$source}] into [{$slug}]")
            ->expectsOutputToContain("(cloned from {$source})")
            ->assertSuccessful();

        // The test database is never cloned: it starts empty.
        expect(rowCount($driver, $slug, 'users'))->toBe(2)
            ->and(rowCount($driver, $slug, 'jobs'))->toBe(0)
            ->and(databaseExists($driver, $slug.'_testing'))->toBeTrue()
            ->and(fn () => rowCount($driver, $slug.'_testing', 'users'))->toThrow(PDOException::class);

        // Running setup again re-clones, picking up what the main database gained.
        serverPdo($driver, $source)->exec("INSERT INTO users (id, name) VALUES (3, 'cy')");

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->assertSuccessful();

        expect(rowCount($driver, $slug, 'users'))->toBe(3);

        $this->artisan('worktree:teardown', [
            'name' => 'feature/login',
            '--abandon' => true,
            '--force' => true,
        ])->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $source))->toBeTrue()
            ->and(rowCount($driver, $source, 'jobs'))->toBe(1);
    } finally {
        foreach ([$source, $slug, $slug.'_testing'] as $name) {
            dropDatabase($driver, $name);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('clones through pg_dump while other sessions hold the main database', function () {
    if (! serverAvailable('pgsql') || ! pgToolsMatchServer()) {
        $this->markTestSkipped('needs a pgsql server on 127.0.0.1 and a pg_dump at least as new as it');
    }

    useServer('pgsql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $source = 'wt_main_'.bin2hex(random_bytes(3));

    try {
        sourceDatabase('pgsql', $source);
        useDatabase($repo, 'pgsql', $source);

        // What a running queue worker or an open database GUI does to a template copy.
        $held = serverPdo('pgsql', $source);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->expectsOutputToContain('copied with pg_dump')
            ->assertSuccessful();

        expect(rowCount('pgsql', $slug, 'users'))->toBe(2)
            ->and(rowCount('pgsql', $slug, 'jobs'))->toBe(0)
            ->and($held->query('SELECT COUNT(*) FROM users')->fetchColumn())->toEqual(2);
    } finally {
        unset($held);

        foreach ([$source, $slug, $slug.'_testing'] as $name) {
            dropDatabase('pgsql', $name);
        }

        removeRepo($repo);
    }
});

it('starts empty when the database to clone does not exist', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $missing = 'wt_missing_'.bin2hex(random_bytes(3));

    try {
        useDatabase($repo, $driver, $missing);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->expectsOutputToContain("Nothing to clone: the database [{$missing}] does not exist")
            ->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeTrue()
            ->and(databaseExists($driver, $missing))->toBeFalse();
    } finally {
        foreach ([$slug, $slug.'_testing'] as $name) {
            dropDatabase($driver, $name);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('refuses a worktree database named like the main one before creating anything', function (string $env, string $template) {
    // The guard reads config and the main .env only, so no server is needed:
    // it has to stop setup before a connection is ever attempted.
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'main_app']);
    config()->set('worktree.database.connections', [[
        'connection' => null,
        'env' => 'DB_DATABASE',
        'name' => $env === 'app' ? $template : '{slug}',
        'test' => ['env' => 'DB_DATABASE', 'name' => $env === 'test' ? $template : '{slug}_testing'],
    ]]);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        try {
            $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])->run();
            $this->fail('Setup should have refused the main database.');
        } catch (WorktreeException $exception) {
            expect($exception->getMessage())->toContain("[{$template}] is the main repository's own database");
        }

        expect(is_dir(dirname($repo).'/'.basename($repo).'-feature-login'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
})->with([
    'application database' => ['app', 'main_app'],
    'test database' => ['test', 'main_app'],
    // MySQL on Windows and macOS ignores case, so this is the same database.
    'different case' => ['app', 'Main_App'],
]);

it('clones the main sqlite database into the worktree', function () {
    config()->set('worktree.database.clone.enabled', true);

    $repo = sqliteRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('Cloning [database/database.sqlite] from the main repository')
            ->expectsOutputToContain('database/database.sqlite (cloned)')
            ->assertSuccessful();

        expect(sqliteRows($worktree.'/database/database.sqlite', 'users'))->toBe(2)
            ->and(sqliteRows($worktree.'/database/database.sqlite', 'jobs'))->toBe(0)
            ->and(sqliteRows($repo.'/database/database.sqlite', 'jobs'))->toBe(1)
            ->and(trim(Process::path($worktree)->run(['git', 'status', '--porcelain'])->output()))->toBe('');

        // A resumed setup replaces last run's copy with a fresh one.
        $pdo = new PDO('sqlite:'.$repo.'/database/database.sqlite');
        $pdo->exec("INSERT INTO users (name) VALUES ('cy')");
        unset($pdo);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(sqliteRows($worktree.'/database/database.sqlite', 'users'))->toBe(3);
    } finally {
        removeRepo($repo);
    }
});

it('starts empty with --no-clone even when cloning is configured', function () {
    config()->set('worktree.database.clone.enabled', true);

    $repo = sqliteRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-clone' => true])
            ->doesntExpectOutputToContain('Cloning')
            ->assertSuccessful();

        expect(filesize($worktree.'/database/database.sqlite'))->toBe(0);
    } finally {
        removeRepo($repo);
    }
});

it('refuses a worktree database that lands on another connection main database', function () {
    // A second connection on the same server whose token-less name is the
    // default connection's main database: its own main database differs, so
    // only checking an entry against itself would let the clone drop it.
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'main_app']);
    config()->set('database.connections.reports', ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'reports']);
    config()->set('worktree.database.connections', [
        ['connection' => null, 'env' => 'DB_DATABASE', 'name' => '{slug}'],
        ['connection' => 'reports', 'env' => 'REPORTS_DB_DATABASE', 'name' => 'main_app'],
    ]);

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])->run();
        $this->fail('Setup should have refused the main database.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain("[main_app] is the main repository's own database");
    } finally {
        removeRepo($repo);
    }
});

it('migrates the default connection fresh when only another connection was cloned', function () {
    // migrate and --seed act on the default connection, so another
    // connection's clone must not stop the default from being reset and seeded.
    config()->set('worktree.dependencies', []);
    config()->set('worktree.database.seed', true);
    config()->set('worktree.database.clone.enabled', true);

    $repo = sqliteRepo();

    // The default has no main database to copy; the second connection does.
    rename($repo.'/database/database.sqlite', $repo.'/database/other.sqlite');
    config()->set('database.connections.other', ['driver' => 'sqlite', 'database' => $repo.'/database/other.sqlite']);
    config()->set('worktree.database.connections', [
        ['connection' => null, 'env' => 'DB_DATABASE', 'name' => '{slug}'],
        ['connection' => 'other', 'env' => 'OTHER_DATABASE', 'name' => '{slug}_other'],
    ]);

    recordingArtisan($repo);
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login'])->assertSuccessful();

        expect(trim((string) file_get_contents($worktree.'/artisan.log')))->toBe('migrate:fresh --force --seed')
            ->and(sqliteRows($worktree.'/database/other.sqlite', 'users'))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('migrates a clone forward instead of fresh, and seeds it only on request', function () {
    config()->set('worktree.dependencies', []);
    config()->set('worktree.database.seed', true);
    config()->set('worktree.database.clone.enabled', true);

    $repo = sqliteRepo();
    recordingArtisan($repo);
    $this->app->setBasePath($repo);
    $log = dirname($repo).'/'.basename($repo).'-feature-login/artisan.log';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login'])->assertSuccessful();

        expect(trim((string) file_get_contents($log)))->toBe('migrate --force');

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--seed' => true])->assertSuccessful();

        expect((string) file_get_contents($log))->toContain('migrate --force --seed');

        // Without a clone, the configured fresh migration and seed run as before.
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-clone' => true])->assertSuccessful();

        expect((string) file_get_contents($log))->toContain('migrate:fresh --force --seed');
    } finally {
        removeRepo($repo);
    }
});

/**
 * A stand-in for a site tool such as herd, reached by its absolute path through
 * the driver's binary setting, so no test runs the real tool or edits PATH. It
 * logs every call's working directory and arguments, and per subcommand it can
 * wait, print canned output, and exit with a given code. Every call to it starts
 * a fresh log.
 *
 * @param  array<string, array{output?: string, exit?: int, sleep?: int}>  $subcommands
 */
function fakeSiteTool(string $name, array $subcommands = []): string
{
    $dir = fakeSiteToolDirectory();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($dir.'/'.$name.'.log', '');

    $windows = PHP_OS_FAMILY === 'Windows';

    // The redirect goes first in the batch file: "echo x 1>>log" would read the
    // trailing 1 as a stream number. WT_LEAK_CHECK is logged too, to show the
    // main app's environment never reaches a driver command.
    $lines = $windows
        ? ['@echo off', '>>"%~dp0'.$name.'.log" echo %CD%^|%*^|%WT_LEAK_CHECK%']
        : ['#!/bin/sh', 'echo "$PWD|$*|${WT_LEAK_CHECK-}" >> "$(dirname "$0")/'.$name.'.log"'];

    foreach ($subcommands as $subcommand => $behaviour) {
        $output = $name.'.'.$subcommand.'.out';
        file_put_contents($dir.'/'.$output, ($behaviour['output'] ?? '')."\n");

        $sleep = $behaviour['sleep'] ?? 0;
        $exit = $behaviour['exit'] ?? 0;

        // cmd's "type" reads a forward slash as a switch, so the batch file
        // names its output relative to its own directory.
        array_push($lines, ...($windows ? [
            'if "%1"=="'.$subcommand.'" (',
            $sleep > 0 ? '  ping -n '.($sleep + 1).' 127.0.0.1 > nul' : '  rem',
            '  type "%~dp0'.$output.'"',
            '  exit /b '.$exit,
            ')',
        ] : [
            'if [ "$1" = "'.$subcommand.'" ]; then',
            $sleep > 0 ? '  sleep '.$sleep : '  :',
            '  cat "$(dirname "$0")/'.$output.'"',
            '  exit '.$exit,
            'fi',
        ]));
    }

    $lines[] = $windows ? 'exit /b 0' : 'exit 0';

    $binary = $dir.'/'.$name.($windows ? '.bat' : '');
    $eol = $windows ? "\r\n" : "\n";
    file_put_contents($binary, implode($eol, $lines).$eol);
    chmod($binary, 0755);

    return $binary;
}

/**
 * Each call a fake site tool logged, split into its working directory, its
 * arguments, and the WT_LEAK_CHECK value it saw.
 *
 * @return list<array<int, string>>
 */
function fakeSiteToolLog(string $name): array
{
    $log = (string) file_get_contents(fakeSiteToolDirectory().'/'.$name.'.log');
    $lines = array_filter(preg_split('/\R/', trim($log)) ?: [], fn (string $line): bool => $line !== '');

    return array_values(array_map(fn (string $line): array => array_map(trim(...), explode('|', $line)), $lines));
}

/**
 * The calls a fake site tool received, as "arguments in directory", naming only
 * the directory's last segment.
 *
 * @return list<string>
 */
function fakeSiteToolCalls(string $name): array
{
    return array_map(
        fn (array $call): string => $call[1].' in '.basename(str_replace('\\', '/', $call[0])),
        fakeSiteToolLog($name),
    );
}

/**
 * The WT_LEAK_CHECK value each call to a fake site tool saw. cmd expands an
 * unset variable in a batch file to nothing, so unset reads as empty on every
 * platform.
 *
 * @return list<string>
 */
function fakeSiteToolLeaks(string $name): array
{
    return array_map(fn (array $call): string => $call[2] ?? '', fakeSiteToolLog($name));
}

function fakeSiteToolDirectory(): string
{
    return sys_get_temp_dir().'/wt-site-tools-'.getmypid();
}

/**
 * What "herd links --json" prints for the given sites, reduced to the key the
 * driver reads.
 */
function herdLinksJson(string ...$sites): string
{
    return (string) json_encode(array_map(fn (string $site): array => ['site' => $site], $sites));
}

afterAll(function () {
    (new Filesystem)->deleteDirectory(fakeSiteToolDirectory());
});

it('serves nothing and says so when the configured herd is missing', function () {
    // beforeEach points the herd binary at a name that does not exist. A Linux
    // user with a config published for Herd used to get a failed "herd link"
    // on every setup and an https URL nothing answered.
    config()->set('worktree.site.driver', 'herd');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('WORKTREE_DRIVER')
            ->assertSuccessful();

        expect((string) file_get_contents(dirname($repo).'/'.$name.'/.env'))
            ->toContain('APP_URL=http://'.$name.'.test');

        $this->artisan('worktree:list')
            ->doesntExpectOutputToContain('://')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('keeps serving through herd over https when the legacy herd value is unknown', function () {
    // An unknown value used to become Herd with HTTPS without a word; it still
    // does, so no published config changes behaviour, but now it says so.
    config()->set('worktree.site.driver', null);
    config()->set('worktree.herd', 'valet');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['links' => ['output' => herdLinksJson($name)]]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('worktree.herd')
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$name} in {$name}", 'links --json in '.basename($repo), "secure {$name} in {$name}"]);
    } finally {
        removeRepo($repo);
    }
});

it('serves a worktree through herd and stops serving it on teardown', function () {
    // The main app's .env keys must not reach a driver command any more than
    // they reach a step; the main .env defines WT_LEAK_CHECK.
    putenv('WT_LEAK_CHECK=parent');
    $_ENV['WT_LEAK_CHECK'] = 'parent';
    $_SERVER['WT_LEAK_CHECK'] = 'parent';

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', [
        'links' => ['output' => herdLinksJson(basename($repo), $name)],
    ]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->doesntExpectOutputToContain('does not list it')
            ->expectsOutputToContain("https://{$name}.test")
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$name} in {$name}", 'links --json in '.basename($repo), "secure {$name} in {$name}"])
            ->and(fakeSiteToolLeaks('herd'))->toBe(['', '', ''])
            ->and((string) file_get_contents(dirname($repo).'/'.$name.'/.env'))->toContain("APP_URL=https://{$name}.test");

        $this->artisan('worktree:list')
            ->expectsOutputToContain("https://{$name}.test")
            ->assertSuccessful();

        fakeSiteTool('herd');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["unsecure {$name} in ".basename($repo), "unlink {$name} in ".basename($repo)])
            ->and(fakeSiteToolLeaks('herd'))->toBe(['', '']);
    } finally {
        putenv('WT_LEAK_CHECK');
        unset($_ENV['WT_LEAK_CHECK'], $_SERVER['WT_LEAK_CHECK']);
        removeRepo($repo);
    }
});

it('serves through herd over http when the legacy herd value says link', function () {
    // A config published before site drivers existed carries only this key.
    config()->set('worktree.site.driver', null);
    config()->set('worktree.herd', 'link');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['links' => ['output' => herdLinksJson($name)]]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$name} in {$name}", 'links --json in '.basename($repo)])
            ->and((string) file_get_contents(dirname($repo).'/'.$name.'/.env'))->toContain("APP_URL=http://{$name}.test");
    } finally {
        removeRepo($repo);
    }
});

it('stops waiting on a herd command that never finishes', function () {
    // Herd's CLI waits on the Herd app with no timeout, so a stuck app used to
    // hang setup forever. A stand-in herd that sleeps past the limit proves the
    // command is stopped, reported, and setup carries on.
    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.timeout', 2);
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['link' => ['sleep' => 30]]));

    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $started = microtime(true);

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('did not finish within 2 seconds')
            ->assertSuccessful();

        expect(microtime(true) - $started)->toBeLessThan(25);
    } finally {
        removeRepo($repo);
    }
});

it('warns when herd says it linked a site it does not list, and shows no url for it', function (bool $listed) {
    // Herd's CLI ignores a failed request to the Herd app, so "herd link" can
    // exit cleanly having linked nothing. Herd's own site list tells, and the
    // summary used to show the URL all the same.
    Sleep::fake();

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.secure', false);
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', [
        // The name of a site that extends the worktree's must not count.
        'links' => ['output' => herdLinksJson($listed ? $name : $name.'-v2')],
    ]));

    try {
        $setup = $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true]);

        $listed
            ? $setup->doesntExpectOutputToContain('does not list it')->expectsOutputToContain("http://{$name}.test")
            : $setup->expectsOutputToContain('does not list it')->doesntExpectOutputToContain("http://{$name}.test");

        // Run before the finally removes the repository; a pending command
        // held in a variable would otherwise only run once it goes away.
        $setup->assertSuccessful()->run();
    } finally {
        removeRepo($repo);
    }
})->with(['linked' => [true], 'silently not linked' => [false]]);

it('stops serving a worktree whose directory is already gone', function () {
    // Teardown used to skip the server entirely once the directory was gone,
    // leaving the site linked; the site is removed by name instead.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['links' => ['output' => herdLinksJson($name)]]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        Directory::delete(dirname($repo).'/'.$name);
        fakeSiteTool('herd');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["unsecure {$name} in ".basename($repo), "unlink {$name} in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('serves through valet when auto finds valet and not herd', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', 'auto');
    config()->set('worktree.site.drivers.valet.binary', fakeSiteTool('valet'));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain("http://{$name}.test")
            ->assertSuccessful();

        expect(fakeSiteToolCalls('valet'))->toBe(["link {$name} in {$name}"]);

        $this->artisan('worktree:list')
            ->expectsOutputToContain("http://{$name}.test")
            ->assertSuccessful();

        fakeSiteTool('valet');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('valet'))->toBe(["unlink {$name} in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('serves through the commands the project configures', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';
    $path = Worktree::make($repo, 'feature/login', config('worktree'))->path();

    $server = fakeSiteTool('server', ['sites' => ['output' => "{$name}.test -> {$path}"]]);

    config()->set('worktree.site.driver', 'command');
    config()->set('worktree.site.drivers.command', [
        'serve' => [$server, 'link', '{name}', '{path}'],
        'check' => [$server, 'sites'],
        'unserve' => [$server, 'unlink', '{host}'],
    ]);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain("http://{$name}.test")
            ->doesntExpectOutputToContain('does not list')
            ->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(["link {$name} {$path} in {$name}", 'sites in '.basename($repo)]);

        fakeSiteTool('server');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(["unlink {$name}.test in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('serves through a driver class the project writes', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', RecordingSiteDriver::class);
    RecordingSiteDriver::$calls = [];

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain("https://{$name}.example")
            ->assertSuccessful();

        $this->artisan('worktree:list')
            ->expectsOutputToContain("https://{$name}.example")
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(RecordingSiteDriver::$calls)->toBe(["serve {$name}", "unserve {$name}"]);
    } finally {
        removeRepo($repo);
    }
});

it('warns when lerd serves the repository too', function (string $how, bool $served) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $repository = basename($repo);

    if ($how === 'its .lerd.yaml') {
        file_put_contents($repo.'/.lerd.yaml', "php: '8.4'\n");
    }

    config()->set('worktree.site.lerd.binary', fakeSiteTool('lerd', ['sites' => ['output' => match ($how) {
        'its site list' => "NAME   DOMAIN   PATH\n{$repository}   {$repository}.test   ~/sites/{$repository}",
        default => "NAME   DOMAIN   PATH\n{$repository}-api   {$repository}-api.test   ~/sites/{$repository}-api",
    }]]));

    try {
        $setup = $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true]);

        $served
            ? $setup->expectsOutputToContain('Lerd seems to serve this repository')
            : $setup->doesntExpectOutputToContain('Lerd seems to serve this repository');

        $setup->assertSuccessful()->run();

        expect(fakeSiteToolCalls('lerd'))->toBe($how === 'its .lerd.yaml' ? [] : ["sites in {$repository}"]);
    } finally {
        removeRepo($repo);
    }
})->with([
    'by its site list' => ['its site list', true],
    'by its .lerd.yaml' => ['its .lerd.yaml', true],
    'not at all' => ['a site of another name', false],
]);

it('removes a worktree whose files pass the windows path limit', function () {
    // A vendor tree nested deep enough to pass 260 characters, which git for
    // Windows cannot delete without core.longpaths.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $deep = $worktree.'/vendor/'.str_repeat('deeply-nested-directory-name/', 9);
        mkdir($deep, 0777, true);
        file_put_contents($deep.'/file.php', '<?php');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->doesntExpectOutputToContain('not all of its files')
            ->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('finishes the cleanup when git unregisters a worktree it could not fully delete', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('only Windows refuses to delete a file another process holds open');
    }

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // Another program holding a file with no sharing, the way an editor or
        // an antivirus scan can: git unregisters the worktree, then fails.
        file_put_contents($worktree.'/held.txt', "held\n");
        $holder = Process::env(['WT_FILE' => str_replace('/', '\\', $worktree.'/held.txt')])->start([
            'powershell', '-NoProfile', '-Command',
            '$f=[IO.File]::Open($env:WT_FILE,"Open","Read","None"); Start-Sleep 4; $f.Close()',
        ]);
        usleep(1_500_000);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('git removed the worktree but not all of its files')
            ->assertSuccessful();

        $holder->wait();

        expect(trim(Process::path($repo)->run(['git', 'branch', '--list', 'feature/login'])->output()))->toBe('');
    } finally {
        removeRepo($repo);
    }
});

it('keeps finding a long-named worktree made before names were capped', function () {
    // An older release named this worktree, its host, and its databases after
    // the full name. Path, setup, and teardown must keep using that name.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $legacy = basename($repo).'-'.str_replace('/', '-', $branch);
    $path = dirname($repo).'/'.$legacy;

    Process::path($repo)->run(['git', 'worktree', 'add', $path, '-b', $branch])->throw();

    $useServer = serverAvailable('mysql');
    $slug = Worktree::make($repo, $branch, config('worktree'))->withName($legacy)->database('{slug}');

    if ($useServer) {
        useServer('mysql');
        (new DatabaseManager(serverConnections()['mysql']))->create($slug);
    }

    try {
        $this->artisan('worktree:path', ['branch' => $branch])
            ->expectsOutput(str_replace('\\', '/', $path))
            ->assertSuccessful();

        $this->artisan('worktree:setup', ['branch' => $branch, '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('resuming')
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => $branch, '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_dir($path))->toBeFalse();

        if ($useServer) {
            expect(databaseExists('mysql', $slug))->toBeFalse();
        }
    } finally {
        if ($useServer) {
            dropDatabase('mysql', $slug);
        }

        removeRepo($repo);
    }
});

it('gives a relative sqlite path that climbs out of the repository a sibling of its own', function () {
    // "../dbs/app.sqlite" is outside the repository just as an absolute path
    // there is; run from the worktree it would land on the main file.
    $repo = tempRepo();
    [$dir, $main] = outsideSqlite($repo);
    $relative = '../'.basename($dir).'/app.sqlite';
    EnvFile::fromFile($repo.'/.env')->set('DB_DATABASE', $relative)->save($repo.'/.env');
    config()->set('database.connections.sqlite.database', $relative);
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--clone' => true])
            ->assertSuccessful();

        expect((string) file_get_contents($worktree.'/.env'))->toContain('DB_DATABASE='.$own)
            ->and(sqliteRows($own, 'users'))->toBe(2);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_file($own))->toBeFalse()
            ->and(sqliteRows($main, 'users'))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('isolates a sqlite file outside the repository that only the config names', function () {
    // No DB_DATABASE in the .env: the app's config alone points outside, so
    // the worktree's .env has to gain the key.
    $repo = tempRepo();
    [$dir, $main] = outsideSqlite($repo);
    file_put_contents($repo.'/.env', (string) preg_replace('/^DB_DATABASE=.*\R/m', '', (string) file_get_contents($repo.'/.env')));
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        expect((string) file_get_contents($worktree.'/.env'))->toContain('DB_DATABASE='.$own)
            ->and(is_file($own))->toBeTrue()
            ->and(sqliteRows($main, 'users'))->toBe(2);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_file($own))->toBeFalse()
            ->and(sqliteRows($main, 'users'))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('refuses two sqlite connections whose worktree files would be one', function () {
    $repo = tempRepo();
    [$dir, $main] = outsideSqlite($repo);
    config()->set('database.connections.reports', ['driver' => 'sqlite', 'database' => $dir.'/reports.sqlite']);
    config()->set('worktree.database.connections', [
        ['connection' => null, 'env' => 'DB_DATABASE', 'name' => '{slug}'],
        ['connection' => 'reports', 'env' => 'REPORTS_DATABASE', 'name' => '{slug}'],
    ]);
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->run();
        $this->fail('Setup should have refused two connections sharing one worktree file.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain('More than one connection resolves to the database');
    } finally {
        removeRepo($repo);
    }
});

function registryFile(string $repo): string
{
    return $repo.'/.git/laravel-worktree/registry.json';
}

/**
 * @return list<array<string, mixed>>
 */
function registryEntries(string $repo): array
{
    return json_decode((string) file_get_contents(registryFile($repo)), true)['worktrees'];
}

it('records the worktree it sets up and forgets it on teardown', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        $entries = registryEntries($repo);

        expect($entries)->toHaveCount(1)
            ->and($entries[0])->toMatchArray([
                'name' => $name,
                'branch' => 'feature/login',
                'tool' => 'setup',
                'driver' => 'none',
                'secure' => false,
                'host' => $name.'.test',
                'url' => null,
                'databases' => [],
            ])
            ->and(basename($entries[0]['path']))->toBe($name);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(registryEntries($repo))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('drops the databases setup made after the name template changed', function (string $driver) {
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

        // Teardown used to derive the names again from the configuration, so
        // a template changed in between left the real databases behind.
        config()->set('worktree.database.connections', [[
            'connection' => null,
            'env' => 'DB_DATABASE',
            'name' => '{slug}_v2',
            'test' => ['env' => 'DB_DATABASE', 'name' => '{slug}_v2_testing'],
        ]]);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'_testing');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('leaves a database that setup did not make', function (string $driver) {
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

        // A connection added to the configuration after setup names a
        // database setup never made, here one that belongs to something else.
        serverPdo($driver)->exec('CREATE DATABASE '.$slug.'_audit');
        config()->set('worktree.database.connections', [
            ...config('worktree.database.connections'),
            ['connection' => $driver, 'env' => 'AUDIT_DATABASE', 'name' => '{slug}_audit'],
        ]);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_audit'))->toBeTrue();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'_testing');
        dropDatabase($driver, $slug.'_audit');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('keeps a recorded database whose connection now points at another server', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        config()->set('database.connections.mysql.host', 'db.example.invalid');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('Not dropping ['.$slug.']')
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeTrue()
            ->and(registryEntries($repo))->toBe([]);
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
});

it('stops serving through the driver that served the worktree', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    $server = fakeSiteTool('server');

    config()->set('worktree.site.driver', 'command');
    config()->set('worktree.site.drivers.command', [
        'serve' => [$server, 'link', '{name}'],
        'unserve' => [$server, 'unlink', '{name}'],
    ]);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // Moved to another server since: the worktree is still served by the
        // commands, and only they can stop serving it.
        config()->set('worktree.site.driver', 'none');
        fakeSiteTool('server');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(['unlink '.$name.' in '.basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('stops serving nothing when the recorded driver cannot be built', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', RecordingSiteDriver::class);
    RecordingSiteDriver::$calls = [];

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $entries = registryEntries($repo);
        $entries[0]['driver'] = 'App\\Sites\\RemovedDriver';
        file_put_contents(registryFile($repo), json_encode(['version' => 1, 'worktrees' => $entries]));

        // The configured driver never served the worktree, and a site of the
        // same name there may be someone else's.
        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('left for you to stop by hand')
            ->assertSuccessful();

        expect(RecordingSiteDriver::$calls)->toBe(['serve '.$name]);
    } finally {
        removeRepo($repo);
    }
});

it('lists a recorded worktree as setup made it', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', RecordingSiteDriver::class);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        config()->set('worktree.site.driver', 'none');

        $this->artisan('worktree:list')
            ->expectsOutputToContain('https://'.$name.'.example')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('deletes the recorded sqlite file of a worktree whose head is detached', function () {
    $repo = tempRepo();
    [$dir] = outsideSqlite($repo);
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // With no branch there are no names to derive, which used to leave
        // the file behind.
        Process::path($worktree)->run(['git', 'checkout', '--detach'])->throw();

        $this->artisan('worktree:list')
            ->expectsOutputToContain(slugFor($repo).'.sqlite')
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => basename($worktree), '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_file($own))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('leaves a registry it cannot read as it is', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $worktree = dirname($repo).'/'.basename($repo).'-feature-login';

    mkdir(dirname(registryFile($repo)));
    file_put_contents(registryFile($repo), '{"worktrees": [');

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('could not be read')
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('could not be read')
            ->assertSuccessful();

        expect(is_dir($worktree))->toBeFalse()
            ->and((string) file_get_contents(registryFile($repo)))->toBe('{"worktrees": [');
    } finally {
        removeRepo($repo);
    }
});

it('waits for another command on the repository and gives up after the limit', function () {
    Sleep::fake();

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $lock = dirname(registryFile($repo)).'/lock';

    mkdir(dirname($lock));
    $held = fopen($lock, 'c');
    flock($held, LOCK_EX);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->expectsOutputToContain('waiting for it to finish')
            ->run();
        $this->fail('Setup should have given up waiting for the lock.');
    } catch (WorktreeException $exception) {
        expect($exception->getMessage())->toContain('held the lock')
            ->and(is_dir(dirname($repo).'/'.basename($repo).'-feature-login'))->toBeFalse();
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
        removeRepo($repo);
    }
});

it('refuses a recorded database that is the main one', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = dirname($repo).'/'.basename($repo).'-feature-login';

    Process::path($repo)->run(['git', 'worktree', 'add', $path, '-b', 'feature/login'])->throw();

    // Only a server driver would really drop, and the guard has to stop it
    // before any connection is attempted.
    $connection = ['driver' => 'mysql', 'host' => '127.0.0.1'];
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', $connection);

    mkdir(dirname(registryFile($repo)));
    file_put_contents(registryFile($repo), json_encode(['version' => 1, 'worktrees' => [[
        'path' => str_replace('\\', '/', (string) realpath($path)),
        'name' => basename($path),
        'databases' => [['connection' => 'mysql', 'database' => 'main_app', 'dsn' => (new DatabaseManager($connection))->dsn(), 'test' => false]],
    ]]]));

    try {
        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('Refusing to drop [main_app]')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('refuses to delete a recorded sqlite file the main app now uses', function () {
    $repo = tempRepo();
    [$dir] = outsideSqlite($repo);
    $this->app->setBasePath($repo);
    $own = $dir.'/'.slugFor($repo).'.sqlite';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])
            ->assertSuccessful();

        // The main app was pointed at the worktree's file to keep its data.
        config()->set('database.connections.sqlite.database', $own);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('Refusing to delete')
            ->assertSuccessful();

        expect(is_file($own))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('drops the databases of a worktree it has no record of', function (string $driver) {
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

        // As a worktree made by a release that kept no record.
        unlink(registryFile($repo));

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeFalse();
    } finally {
        dropDatabase($driver, $slug);
        dropDatabase($driver, $slug.'_testing');
        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);
