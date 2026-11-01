<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
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
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Worktree;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

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

        hookArtisan($worktree);

        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain('linked worktree')
            ->assertFailed();

        expect(is_dir($worktree.'/.claude'))->toBeFalse();
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

/**
 * A worktree another tool made with plain git, where removeRepo() finds it.
 *
 * @param  list<string>  $how  What git worktree add gets besides the path.
 */
function toolWorktree(string $repo, string $suffix, array $how = ['--detach']): string
{
    $path = dirname($repo).'/'.basename($repo).'-'.$suffix;

    Process::path($repo)->run(['git', 'worktree', 'add', $path, ...$how])->throw();

    return $path;
}

it('adopts a worktree another tool made, and finds and finishes it by its name', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout', ['-b', 'agent-work']);
    $name = basename($repo).'-agent';

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--no-install' => true])
            ->expectsOutputToContain("as [{$name}]")
            ->assertSuccessful();

        $entries = registryEntries($repo);

        expect((string) file_get_contents($path.'/.env'))
            ->toContain('APP_URL=http://'.$name.'.test')
            ->toContain('APP_HOST='.$name.'.test')
            ->and($entries)->toHaveCount(1)
            ->and($entries[0])->toMatchArray(['name' => $name, 'branch' => 'agent-work', 'tool' => 'adopt', 'host' => $name.'.test'])
            ->and(realpath($entries[0]['path']))->toBe(realpath($path));

        $this->artisan('worktree:list')
            ->expectsOutputToContain(basename($repo).'-tool-checkout')
            ->assertSuccessful();

        $this->artisan('worktree:path', ['branch' => $name])
            ->expectsOutputToContain($entries[0]['path'])
            ->assertSuccessful();

        $this->artisan('worktree:path', ['branch' => 'agent-work'])
            ->expectsOutputToContain(basename($repo).'-tool-checkout')
            ->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => $name, '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(is_dir($path))->toBeFalse()
            ->and(registryEntries($repo))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('adopts a detached worktree under a short hash of its path', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-detached');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--no-install' => true])
            ->expectsOutputToContain('(detached)')
            ->assertSuccessful();

        $entry = registryEntries($repo)[0];

        expect($entry['name'])->toMatch('/^'.preg_quote(basename($repo), '/').'-[0-9a-f]{8}$/')
            ->and($entry['branch'])->toBeNull();

        $this->artisan('worktree:teardown', ['name' => basename($path), '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('Finished worktree ['.basename($path).']')
            ->assertSuccessful();

        expect(is_dir($path))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('takes the name a tool gave the worktree it made, and only that worktree', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-conductor');
    $other = toolWorktree($repo, 'tool-other');

    $variables = ['CONDUCTOR_WORKSPACE_NAME' => 'lisbon', 'CONDUCTOR_WORKSPACE_PATH' => $path];

    foreach ($variables as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--no-install' => true])->assertSuccessful();
        $this->artisan('worktree:adopt', ['path' => $other, '--no-install' => true])->assertSuccessful();

        $entries = collect(registryEntries($repo))->keyBy(fn (array $entry): string => basename($entry['path']));

        expect($entries[basename($path)])->toMatchArray(['name' => basename($repo).'-lisbon', 'tool' => 'conductor'])
            ->and($entries[basename($other)]['name'])->not->toBe(basename($repo).'-lisbon')
            ->and($entries[basename($other)]['tool'])->toBe('adopt');
    } finally {
        foreach (array_keys($variables) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        removeRepo($repo);
    }
});

it('refuses to adopt what is not a linked worktree of the repository', function (string $what, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $plain = dirname($repo).'/'.basename($repo).'-plain';
    mkdir($plain);

    $path = match ($what) {
        'main' => $repo,
        'plain' => $plain,
        'missing' => dirname($repo).'/'.basename($repo).'-missing',
    };

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--no-install' => true])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(is_file(registryFile($repo)))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
})->with([
    'the main repository' => ['main', 'is the main repository'],
    'a directory git does not list' => ['plain', 'is not a worktree of this repository'],
    'a directory that is not there' => ['missing', 'There is no directory'],
]);

it('sends a worktree setup made back to setup', function (bool $recorded) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        if (! $recorded) {
            unlink(registryFile($repo));
        }

        $this->artisan('worktree:adopt', ['path' => $path, '--no-install' => true])
            ->expectsOutputToContain('run worktree:setup feature/login instead')
            ->assertFailed();
    } finally {
        removeRepo($repo);
    }
})->with(['recorded' => [true], 'made by an earlier release' => [false]]);

it('refuses a name another worktree already has', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $first = toolWorktree($repo, 'tool-first');
    $second = toolWorktree($repo, 'tool-second');

    try {
        $this->artisan('worktree:adopt', ['path' => $first, '--name' => 'feature-login', '--no-install' => true])->assertSuccessful();

        // Both would get the first one's site and databases, and the second
        // one's migrate:fresh would wipe its data.
        expect(fn () => $this->artisan('worktree:adopt', ['path' => $second, '--name' => 'feature-login', '--no-install' => true])->run())
            ->toThrow(WorktreeException::class, 'would share its databases');

        // Setup names a branch's worktree the same way, so it is refused too.
        expect(fn () => $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->run())
            ->toThrow(WorktreeException::class, 'would share its databases');

        expect(is_file($second.'/.env'))->toBeFalse()
            ->and(is_dir(dirname($repo).'/'.basename($repo).'-feature-login'))->toBeFalse()
            ->and(registryEntries($repo))->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
});

it('keeps the name a worktree was adopted under', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--tool' => 'cursor', '--no-install' => true])->assertSuccessful();

        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'renamed', '--no-install' => true])
            ->expectsOutputToContain('keeps that name')
            ->assertSuccessful();

        $entries = registryEntries($repo);

        expect($entries)->toHaveCount(1)
            ->and($entries[0]['name'])->toBe(basename($repo).'-agent')
            ->and($entries[0]['tool'])->toBe('cursor')
            ->and((string) file_get_contents($path.'/.env'))->toContain('APP_URL=http://'.basename($repo).'-agent.test');
    } finally {
        removeRepo($repo);
    }
});

it('does not leak the main app environment into an adopted worktree', function () {
    putenv('WT_LEAK_CHECK=parent');
    $_ENV['WT_LEAK_CHECK'] = 'parent';
    $_SERVER['WT_LEAK_CHECK'] = 'parent';

    config()->set('worktree.steps', ['php -r "echo \'LEAK=\'.(getenv(\'WT_LEAK_CHECK\') ?: \'unset\');"']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--no-migrate' => true])
            ->expectsOutputToContain('LEAK=unset')
            ->assertSuccessful();
    } finally {
        putenv('WT_LEAK_CHECK');
        unset($_ENV['WT_LEAK_CHECK'], $_SERVER['WT_LEAK_CHECK']);
        removeRepo($repo);
    }
});

it('runs adopt in the main checkout from anywhere in the worktree through the shim', function () {
    $repo = tempRepo();
    $path = toolWorktree($repo, 'tool-checkout');
    mkdir($path.'/app');

    // The main checkout's artisan, reporting what it was started with.
    file_put_contents($repo.'/artisan', "<?php\nfile_put_contents(__DIR__.'/shim.json', json_encode(['arguments' => array_slice(\$argv, 1), 'cwd' => getcwd(), 'display_errors' => ini_get('display_errors')]));\nexit(3);\n");

    try {
        $result = Process::path($path.'/app')->run([PHP_BINARY, __DIR__.'/../resources/stubs/worktree-adopt.php', '--clone']);
        $call = json_decode((string) file_get_contents($repo.'/shim.json'), true);

        expect($result->exitCode())->toBe(3)
            ->and($call['arguments'][0])->toBe('worktree:adopt')
            ->and(realpath($call['arguments'][1]))->toBe(realpath($path))
            ->and(array_slice($call['arguments'], 2))->toBe(['--clone'])
            ->and(realpath($call['cwd']))->toBe(realpath($repo))
            ->and($call['display_errors'])->toBe('stderr');
    } finally {
        removeRepo($repo);
    }
});

it('refuses a name that would share databases with another worktree or the main site', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        // A different name, but the same blog_feature_login databases.
        expect(fn () => $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'feature_login', '--no-install' => true])->run())
            ->toThrow(WorktreeException::class, 'would share its databases');

        // With no repository in the template, the name could be the main site's.
        config()->set('worktree.host.template', '{branch}');

        expect(fn () => $this->artisan('worktree:adopt', ['path' => $path, '--name' => basename($repo), '--no-install' => true])->run())
            ->toThrow(WorktreeException::class, 'would share its databases');

        expect(is_file($path.'/.env'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('keeps --tool=setup for the worktrees setup makes', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--tool' => 'setup', '--no-install' => true])
            ->expectsOutputToContain('--tool=setup is kept')
            ->assertFailed();
    } finally {
        removeRepo($repo);
    }
});

it('refuses to guess which worktree a name means when it matches two', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $adopted = toolWorktree($repo, 'tool-checkout');
    toolWorktree($repo, 'tool-branch', ['-b', basename($repo).'-agent']);

    try {
        $this->artisan('worktree:adopt', ['path' => $adopted, '--name' => 'agent', '--no-install' => true])->assertSuccessful();

        expect(fn () => $this->artisan('worktree:teardown', ['name' => basename($repo).'-agent', '--abandon' => true, '--force' => true])->run())
            ->toThrow(WorktreeException::class, 'More than one worktree matches');

        expect(is_dir($adopted))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('never stops serving the main site for a worktree it has no record of', function () {
    // Some tools check out every worktree as <id>/<repository>, so the
    // directory carries the main site's name, and teardown used to unserve
    // a worktree it had no record of by its directory.
    RecordingSiteDriver::$calls = [];
    config()->set('worktree.site.driver', RecordingSiteDriver::class);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'codex/'.basename($repo), ['-b', 'codex-work']);

    try {
        // Nor is it shown with a site or databases it never had.
        $this->artisan('worktree:list')
            ->doesntExpectOutputToContain(basename($repo).'-codex-work')
            ->assertSuccessful();

        // By its path as the user types it, which git spells another way.
        $this->artisan('worktree:teardown', ['name' => PHP_OS_FAMILY === 'Windows' ? str_replace('/', '\\', $path) : $path, '--abandon' => true, '--force' => true])
            ->expectsOutputToContain("taken for another tool's")
            ->assertSuccessful();

        expect(is_dir($path))->toBeFalse()
            ->and(RecordingSiteDriver::$calls)->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('gives an adopted worktree its own vendor when the tool linked the main one', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    mkdir($repo.'/vendor');
    file_put_contents($repo.'/vendor/marker.txt', "main\n");
    // What storage:link makes too: a symlink, or a junction on Windows.
    (new Filesystem)->link($repo.'/vendor', $path.'/vendor');

    try {
        // composer install would otherwise write into the main vendor.
        $this->artisan('worktree:adopt', ['path' => $path, '--no-migrate' => true])
            ->expectsOutputToContain('[vendor] in the worktree was a link')
            ->assertSuccessful();

        expect(is_file($repo.'/vendor/marker.txt'))->toBeTrue()
            ->and(is_file($repo.'/vendor/autoload.php'))->toBeFalse()
            ->and(is_file($path.'/vendor/autoload.php'))->toBeTrue()
            ->and(Directory::isLink($path.'/vendor'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

/**
 * A file symlink, made through mklink on Windows, where PHP's symlink() needs
 * a right that mklink in developer mode does not.
 */
function fileSymlink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    return PHP_OS_FAMILY === 'Windows'
        && Process::run(['cmd', '/c', 'mklink', str_replace('/', '\\', $link), str_replace('/', '\\', $target)])->successful();
}

it('gives an adopted worktree its own .env when the tool linked the main one', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');
    $main = (string) file_get_contents($repo.'/.env');

    if (! fileSymlink($repo.'/.env', $path.'/.env')) {
        $this->markTestSkipped('this system cannot create file symlinks');
    }

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--no-install' => true])
            ->assertSuccessful();

        expect((string) file_get_contents($repo.'/.env'))->toBe($main)
            ->and(is_link($path.'/.env'))->toBeFalse()
            ->and((string) file_get_contents($path.'/.env'))->toContain('APP_URL=http://'.basename($repo).'-agent.test');
    } finally {
        removeRepo($repo);
    }
});

it('gives an adopted worktree its own .env when the tool hard linked the main one', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');
    $main = (string) file_get_contents($repo.'/.env');

    // What Laravel's File::link() makes for a file on Windows.
    link($repo.'/.env', $path.'/.env');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--no-install' => true])
            ->expectsOutputToContain('[.env] in the worktree was a link')
            ->assertSuccessful();

        expect((string) file_get_contents($repo.'/.env'))->toBe($main)
            ->and((string) file_get_contents($path.'/.env'))->toContain('APP_URL=http://'.basename($repo).'-agent.test');
    } finally {
        removeRepo($repo);
    }
});

it('keeps a link that stays inside the adopted worktree', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');
    file_put_contents($path.'/.env.shared', "APP_URL=https://shared.test\n");

    if (! fileSymlink($path.'/.env.shared', $path.'/.env')) {
        $this->markTestSkipped('this system cannot create file symlinks');
    }

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--no-install' => true])
            ->doesntExpectOutputToContain('was a link')
            ->assertSuccessful();

        expect(is_link($path.'/.env'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('stops serving a worktree setup made before anything was recorded', function (bool $gone) {
    RecordingSiteDriver::$calls = [];
    config()->set('worktree.site.driver', RecordingSiteDriver::class);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // As a release that kept no record left it.
        unlink(registryFile($repo));

        if ($gone) {
            Directory::delete(dirname($repo).'/'.$name);
        }

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->doesntExpectOutputToContain("taken for another tool's")
            ->assertSuccessful();

        expect(RecordingSiteDriver::$calls)->toBe(['serve '.$name, 'unserve '.$name]);
    } finally {
        removeRepo($repo);
    }
})->with(['directory present' => [false], 'directory gone' => [true]]);

/**
 * The recorded path of a branch's worktree, after deleting its directory by
 * hand the way a tool or a person does, without a teardown.
 */
function goneWorktree(string $repo, string $branch): string
{
    $entry = array_values(array_filter(registryEntries($repo), fn (array $entry): bool => $entry['branch'] === $branch))[0];

    expect(Directory::delete($entry['path']))->toBeTrue();

    return $entry['path'];
}

/**
 * The command driver serving through a fake tool, so what it is asked to stop
 * can be checked.
 */
function serveThroughFakeTool(): void
{
    $server = fakeSiteTool('server');

    config()->set('worktree.site.driver', 'command');
    config()->set('worktree.site.drivers.command', [
        'serve' => [$server, 'link', '{name}'],
        'unserve' => [$server, 'unlink', '{name}'],
    ]);
}

it('serves a branch with capitals and an underscore at a hostname-safe site, and stops serving it there', function () {
    // A host holds lowercase letters, digits and hyphens only; the directory
    // keeps the branch's own characters.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-Feature_Login';
    $site = basename($repo).'-feature-login';

    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['links' => ['output' => herdLinksJson($site)]]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'Feature_Login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain("https://{$site}.test")
            ->assertSuccessful();

        $env = (string) file_get_contents(dirname($repo).'/'.$name.'/.env');

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$site} in {$name}", 'links --json in '.basename($repo), "secure {$site} in {$name}"])
            ->and($env)->toContain("APP_URL=https://{$site}.test")
            ->and($env)->not->toContain('SESSION_COOKIE')
            ->and(registryEntries($repo)[0])->toMatchArray(['name' => $name, 'site' => $site, 'host' => $site.'.test']);

        fakeSiteTool('herd');

        $this->artisan('worktree:teardown', ['name' => 'Feature_Login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["unsecure {$site} in ".basename($repo), "unlink {$site} in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('serves a worktree under the main site with a subdomain site template', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';
    $site = 'feature-login.'.basename($repo);

    // A cookie domain that covers the main site's subdomains sends the main
    // app's session cookie to the worktree too.
    file_put_contents($repo.'/.env', 'SESSION_DOMAIN=.'.basename($repo).".test\n", FILE_APPEND);

    config()->set('worktree.host.site', '{branch}.{repo}');
    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', ['links' => ['output' => herdLinksJson($site)]]));

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain("https://{$site}.test")
            ->assertSuccessful();

        $env = (string) file_get_contents(dirname($repo).'/'.$name.'/.env');

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$site} in {$name}", 'links --json in '.basename($repo), "secure {$site} in {$name}"])
            ->and($env)->toContain("APP_URL=https://{$site}.test")
            ->and($env)->toContain("APP_HOST={$site}.test")
            ->and($env)->toContain("SESSION_DOMAIN=.{$site}.test")
            ->and($env)->toContain('SESSION_COOKIE='.slugFor($repo).'_session');

        $this->artisan('worktree:list')
            ->expectsOutputToContain("https://{$site}.test")
            ->assertSuccessful();

        fakeSiteTool('herd');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["unsecure {$site} in ".basename($repo), "unlink {$site} in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('lets env.replace name the session cookie of a worktree under the main site', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    config()->set('worktree.host.site', '{branch}.{repo}');
    config()->set('worktree.env.replace', ['SESSION_COOKIE' => '{slug}-cookie']);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        expect((string) file_get_contents(dirname($repo).'/'.basename($repo).'-feature-login/.env'))
            ->toContain('SESSION_COOKIE='.slugFor($repo).'-cookie')
            ->toContain('APP_URL=http://feature-login.'.basename($repo).'.test');
    } finally {
        removeRepo($repo);
    }
});

it('keeps the site a worktree was first served as when setup resumes it', function (string $made) {
    // Served again under its site name worked out afresh, the worktree would
    // get a second site and keep the first one forever.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-Feature_Login';
    serveThroughFakeTool();

    try {
        if ($made === 'before sites had names') {
            $this->artisan('worktree:setup', ['branch' => 'Feature_Login', '--no-install' => true, '--no-database' => true])
                ->assertSuccessful();

            $registry = json_decode((string) file_get_contents(registryFile($repo)), true);
            unset($registry['worktrees'][0]['site']);
            file_put_contents(registryFile($repo), json_encode($registry));
        } else {
            Process::path($repo)->run(['git', 'worktree', 'add', dirname($repo).'/'.$name, '-b', 'Feature_Login'])->throw();

            $this->artisan('worktree:list')
                ->expectsOutputToContain("http://{$name}.test")
                ->assertSuccessful();
        }

        fakeSiteTool('server');

        $this->artisan('worktree:setup', ['branch' => 'Feature_Login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(["link {$name} in {$name}"])
            ->and((string) file_get_contents(dirname($repo).'/'.$name.'/.env'))->toContain("APP_URL=http://{$name}.test")
            ->and(registryEntries($repo)[0]['site'])->toBe($name);

        fakeSiteTool('server');

        $this->artisan('worktree:teardown', ['name' => 'Feature_Login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(["unlink {$name} in ".basename($repo)]);
    } finally {
        removeRepo($repo);
    }
})->with(['before sites had names', 'before anything was recorded']);

it('refuses a worktree whose site another worktree already has', function () {
    // Their databases differ (a trailing underscore survives the slug), but a
    // host has no underscore, so both would be served as one site.
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature-x', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        expect(fn () => $this->artisan('worktree:setup', ['branch' => 'feature-x_', '--no-install' => true, '--no-database' => true])->run())
            ->toThrow(WorktreeException::class, 'The site ['.basename($repo).'-feature-x] is already');

        expect(is_dir(dirname($repo).'/'.basename($repo).'-feature-x_'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
});

it('keeps a gone worktree whose site a live worktree is served under now', function () {
    // feature-x_ and feature-x get different databases but one site, so
    // unserving the gone one would take the live one's site down.
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $site = basename($repo).'-feature-x';
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature-x_', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $gone = registryEntries($repo)[0]['path'];
        Process::path($repo)->run(['git', 'worktree', 'remove', '--force', $gone])->throw();

        $this->artisan('worktree:setup', ['branch' => 'feature-x', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe([])
            ->and(array_column(registryEntries($repo), 'site'))->toContain($site)
            ->and(count(registryEntries($repo)))->toBe(2);
    } finally {
        removeRepo($repo);
    }
});

it('prunes a gone worktree under the site it was served as', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'Feature_Login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        goneWorktree($repo, 'Feature_Login');
        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])->assertSuccessful();

        expect(fakeSiteToolCalls('server'))->toBe(['unlink '.basename($repo).'-feature-login in '.basename($repo)]);
    } finally {
        removeRepo($repo);
    }
});

it('lists a recorded worktree whose directory is gone and changes nothing', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        goneWorktree($repo, 'feature/login');
        $entries = registryEntries($repo);
        fakeSiteTool('server');

        $this->artisan('worktree:prune')
            ->expectsOutputToContain($name)
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        expect(registryEntries($repo))->toBe($entries)
            ->and(fakeSiteToolCalls('server'))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('cleans up after a gone worktree and leaves what the registry did not record', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';
    $slug = slugFor($repo);
    $other = str_replace('_feature_login', '_feature_other', $slug);
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true])->assertSuccessful();

        // Named like the gone worktree's databases, but nothing recorded it.
        serverPdo($driver)->exec('CREATE DATABASE '.$slug.'_audit');

        $path = goneWorktree($repo, 'feature/login');
        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain("Pruned [{$name}]")
            ->assertSuccessful();

        expect(databaseExists($driver, $slug))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_testing'))->toBeFalse()
            ->and(databaseExists($driver, $slug.'_audit'))->toBeTrue()
            ->and(databaseExists($driver, $other))->toBeTrue()
            ->and(databaseExists($driver, $other.'_testing'))->toBeTrue()
            ->and(fakeSiteToolCalls('server'))->toBe(['unlink '.$name.' in '.basename($repo)])
            ->and(array_column(registryEntries($repo), 'branch'))->toBe(['feature/other'])
            ->and(Process::path($repo)->run(['git', 'worktree', 'list', '--porcelain'])->output())->not->toContain(basename($path));
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_audit', $other, $other.'_testing'] as $database) {
            dropDatabase($driver, $database);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('keeps the databases of a worktree that was moved, and records it where it went', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $moved = $repo.'-moved';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        // git worktree move leaves the record at the old path.
        Process::path($repo)->run(['git', 'worktree', 'move', registryEntries($repo)[0]['path'], $moved])->throw();

        $this->artisan('worktree:prune')
            ->expectsOutputToContain('seems to have moved')
            ->doesntExpectOutputToContain('Nothing was changed')
            ->assertSuccessful();

        // Adopted afresh, it would get new databases and leave its own.
        $this->artisan('worktree:adopt', ['path' => $moved, '--no-install' => true])
            ->expectsOutputToContain('seems to have moved here. Run worktree:prune --force first')
            ->assertFailed();

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('so its record now follows it there')
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeTrue()
            ->and(databaseExists('mysql', $slug.'_testing'))->toBeTrue()
            ->and(realpath(registryEntries($repo)[0]['path']))->toBe(realpath($moved));

        // Once the record has followed, adopt picks it up under its name.
        $this->artisan('worktree:adopt', ['path' => $moved, '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('as ['.basename($repo).'-feature-login]')
            ->assertSuccessful();

        expect(registryEntries($repo))->toHaveCount(1);

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeFalse()
            ->and(registryEntries($repo))->toBe([]);
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
});

it('keeps the databases of a moved worktree that only its .env ties to its record', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($repo).'-agent'));
    $path = toolWorktree($repo, 'agent');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--no-install' => true])->assertSuccessful();

        // Detached, and moved to a directory named like nothing recorded.
        Process::path($repo)->run(['git', 'worktree', 'move', $path, $repo.'-moved'])->throw();

        $this->artisan('worktree:prune', ['--force' => true])->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeTrue()
            ->and(realpath(registryEntries($repo)[0]['path']))->toBe(realpath($repo.'-moved'));
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
});

it('leaves a gone worktree that git has locked alone', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // A worktree on a drive that is not mounted right now.
        Process::path($repo)->run(['git', 'worktree', 'lock', registryEntries($repo)[0]['path']])->throw();
        goneWorktree($repo, 'feature/login');
        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('git has it locked')
            ->expectsOutputToContain('No recorded worktree is gone')
            ->assertSuccessful();

        expect(registryEntries($repo))->toHaveCount(1)
            ->and(fakeSiteToolCalls('server'))->toBe([]);

        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true, '--no-database' => true])
            ->doesntExpectOutputToContain('gone without a teardown')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('says when recorded worktrees are gone, and leaves them to prune', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        goneWorktree($repo, 'feature/login');

        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true, '--no-database' => true])
            ->expectsOutputToContain('1 worktree recorded for this repository is gone without a teardown. Run worktree:prune')
            ->assertSuccessful();

        expect(registryEntries($repo))->toHaveCount(2);
    } finally {
        removeRepo($repo);
    }
});

it('names the site of a gone worktree whose server is not installed any more', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        // Served through Valet, which has been uninstalled since.
        $entries = registryEntries($repo);
        $entries[0]['driver'] = 'valet';
        file_put_contents(registryFile($repo), json_encode(['version' => 1, 'worktrees' => $entries]));
        goneWorktree($repo, 'feature/login');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain("The [valet] site driver that served this worktree is not available any more, so its site [{$name}] is left for you to stop by hand.")
            ->assertSuccessful();

        expect(registryEntries($repo))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('lists databases named like a worktree that nothing claims, and drops one only when asked', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    useServer($driver);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $prefix = mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', basename($repo)));
    $slug = slugFor($repo);
    $orphan = $prefix.'_gone_branch';
    $main = $prefix.'_main';
    $dsn = (new DatabaseManager(serverConnections()[$driver]))->dsn();

    // The main app's own databases are named like a worktree's here: the
    // one its .env names, the one its .env.testing names, and a reporting
    // connection's that isn't isolated per worktree.
    file_put_contents($repo.'/.env', str_replace('DB_DATABASE=main_app', 'DB_DATABASE='.$main, (string) file_get_contents($repo.'/.env')));
    file_put_contents($repo.'/.env.testing', 'DB_DATABASE='.$prefix."_envtesting\n");
    config()->set('database.connections.reporting', [...serverConnections()[$driver], 'database' => $prefix.'_reporting']);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        foreach ([$orphan, $main, $prefix.'_envtesting', $prefix.'_reporting', $slug.'_test_1'] as $database) {
            serverPdo($driver)->exec('CREATE DATABASE '.$database);
        }

        $this->artisan('worktree:prune', ['--orphans' => true])
            ->expectsOutputToContain($orphan)
            ->doesntExpectOutputToContain($main)
            ->doesntExpectOutputToContain('_envtesting')
            ->doesntExpectOutputToContain('_reporting')
            ->doesntExpectOutputToContain($slug)
            ->assertSuccessful();

        $this->artisan('worktree:prune', ['--orphans' => true, '--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('this run cannot ask')
            ->assertSuccessful();

        expect(databaseExists($driver, $orphan))->toBeTrue();

        $this->artisan('worktree:prune', ['--orphans' => true, '--force' => true])
            ->expectsConfirmation("Drop [{$orphan}] on [{$dsn}]?", 'no')
            ->assertSuccessful();

        expect(databaseExists($driver, $orphan))->toBeTrue();

        $this->artisan('worktree:prune', ['--orphans' => true, '--force' => true])
            ->expectsConfirmation("Drop [{$orphan}] on [{$dsn}]?", 'yes')
            ->expectsOutputToContain("Dropped [{$orphan}]")
            ->assertSuccessful();

        expect(databaseExists($driver, $orphan))->toBeFalse()
            ->and(databaseExists($driver, $main))->toBeTrue()
            ->and(databaseExists($driver, $slug))->toBeTrue()
            ->and(databaseExists($driver, $slug.'_test_1'))->toBeTrue();
    } finally {
        foreach ([$orphan, $main, $prefix.'_envtesting', $prefix.'_reporting', $slug, $slug.'_testing', $slug.'_test_1'] as $database) {
            dropDatabase($driver, $database);
        }

        removeRepo($repo);
    }
})->with(['mysql', 'pgsql']);

it('does not look for databases a template names without the repository name', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    // A host template of the branch alone would match every database on the
    // server. No server is reached: the template is refused before that.
    useServer('mysql');
    config()->set('worktree.host.template', '{branch}');

    try {
        $this->artisan('worktree:prune', ['--orphans' => true])
            ->expectsOutputToContain('cannot be told apart from other projects')
            ->expectsOutputToContain('No database is named like a worktree of this repository')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('keeps another worktree database that is named like a lane of the one torn down', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        // Branch "feature/login-test-2" names its database like a lane of
        // "feature/login": {slug}_test_2.
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/login-test-2', '--no-install' => true])->assertSuccessful();
        serverPdo('mysql')->exec('CREATE DATABASE '.$slug.'_test_lane1');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeFalse()
            ->and(databaseExists('mysql', $slug.'_test_lane1'))->toBeFalse()
            ->and(databaseExists('mysql', $slug.'_test_2'))->toBeTrue();
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_test_lane1', $slug.'_test_2', $slug.'_test_2_testing'] as $database) {
            dropDatabase('mysql', $database);
        }

        removeRepo($repo);
    }
});

it('drops only what the registry recorded for a gone worktree, and lists its lanes as orphans', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/login-test-2', '--no-install' => true])->assertSuccessful();
        serverPdo('mysql')->exec('CREATE DATABASE '.$slug.'_test_lane1');
        goneWorktree($repo, 'feature/login');

        $this->artisan('worktree:prune', ['--force' => true])->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeFalse()
            ->and(databaseExists('mysql', $slug.'_test_2'))->toBeTrue()
            ->and(databaseExists('mysql', $slug.'_test_lane1'))->toBeTrue();

        $this->artisan('worktree:prune', ['--orphans' => true])
            ->expectsOutputToContain($slug.'_test_lane1')
            ->assertSuccessful();
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_test_lane1', $slug.'_test_2', $slug.'_test_2_testing'] as $database) {
            dropDatabase('mysql', $database);
        }

        removeRepo($repo);
    }
});

it('leaves a gone worktree alone when a recorded worktree has its name', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $gone = goneWorktree($repo, 'feature/login');
        Process::path($repo)->run(['git', 'worktree', 'prune'])->throw();

        // With git's entry gone too, a tool's worktree can be adopted under
        // the same name.
        $path = toolWorktree($repo, 'agent');
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'feature-login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();
        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('so its site and databases are left alone')
            ->assertSuccessful();

        expect(array_column(registryEntries($repo), 'name'))->toBe([$name, $name])
            ->and(Registry::samePath(registryEntries($repo)[0]['path'], $gone))->toBeTrue()
            ->and(fakeSiteToolCalls('server'))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('keeps the record where it was when another checkout only shares the branch or the name', function (array $suffixes) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    serveThroughFakeTool();

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $gone = goneWorktree($repo, 'feature/login');
        Process::path($repo)->run(['git', 'worktree', 'prune'])->throw();

        // A new checkout of the branch, and one named like the gone worktree,
        // neither of which carries anything of its own.
        foreach ($suffixes as $suffix => $how) {
            toolWorktree($repo, $suffix, $how);
        }

        fakeSiteTool('server');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('so its site and databases are left alone')
            ->doesntExpectOutputToContain('record now follows it')
            ->assertSuccessful();

        expect(registryEntries($repo))->toHaveCount(1)
            ->and(Registry::samePath(registryEntries($repo)[0]['path'], $gone))->toBeTrue()
            ->and(fakeSiteToolCalls('server'))->toBe([]);
    } finally {
        removeRepo($repo);
    }
})->with([
    'the branch' => [['checkout' => ['feature/login']]],
    'the branch and the name' => [['checkout' => ['feature/login'], 'feature_login' => ['--detach']]],
]);

it('says at teardown when the server that served the worktree is not installed any more', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $name = basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $entries = registryEntries($repo);
        $entries[0]['driver'] = 'valet';
        file_put_contents(registryFile($repo), json_encode(['version' => 1, 'worktrees' => $entries]));

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain("its site [{$name}] is left for you to stop by hand")
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('does not look for databases when the repository name is too long to tell them apart', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    useServer('mysql');
    config()->set('worktree.host.template', '{repo}-a-prefix-long-enough-to-push-the-branch-past-the-host-limit-{branch}');

    try {
        $this->artisan('worktree:prune', ['--orphans' => true])
            ->expectsOutputToContain('cannot be told apart by name')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('keeps the record of a gone worktree whose database server cannot be reached', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    // Nothing listens on port 1, so the drop fails to connect.
    config()->set('database.connections.dead', [...serverConnections()['mysql'], 'port' => 1]);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true, '--no-database' => true])
            ->assertSuccessful();

        $entries = registryEntries($repo);
        $entries[0]['databases'] = [[
            'connection' => 'dead',
            'database' => slugFor($repo),
            'dsn' => (new DatabaseManager(config('database.connections.dead')))->dsn(),
            'test' => false,
        ]];
        file_put_contents(registryFile($repo), json_encode(['version' => 1, 'worktrees' => $entries]));
        goneWorktree($repo, 'feature/login');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('so its record is kept for worktree:prune to try again')
            ->assertSuccessful();

        expect(registryEntries($repo))->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
});

it('keeps a gone worktree database the main app points at now', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        // Its data was worth keeping, so the main app's tests run on it now.
        file_put_contents($repo.'/.env.testing', 'DB_DATABASE='.$slug."\n");
        goneWorktree($repo, 'feature/login');

        $this->artisan('worktree:prune', ['--force' => true])
            ->expectsOutputToContain('is in use by the main app or another worktree now')
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeTrue()
            ->and(registryEntries($repo))->toHaveCount(1);
    } finally {
        dropDatabase('mysql', $slug);
        dropDatabase('mysql', $slug.'_testing');
        removeRepo($repo);
    }
});

it('drops no lane at teardown when it cannot tell which databases the other worktrees use', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    useServer('mysql');

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $slug = slugFor($repo);
    $other = str_replace('_feature_login', '_feature_other', $slug);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();
        $this->artisan('worktree:setup', ['branch' => 'feature/other', '--no-install' => true])->assertSuccessful();
        serverPdo('mysql')->exec('CREATE DATABASE '.$slug.'_test_1');

        // An .env that cannot be read as a file.
        $otherPath = array_values(array_filter(registryEntries($repo), fn (array $entry): bool => $entry['branch'] === 'feature/other'))[0]['path'];
        unlink($otherPath.'/.env');
        mkdir($otherPath.'/.env');

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true])
            ->expectsOutputToContain('so no parallel-test database is dropped')
            ->assertSuccessful();

        expect(databaseExists('mysql', $slug))->toBeFalse()
            ->and(databaseExists('mysql', $slug.'_test_1'))->toBeTrue()
            ->and(array_column(registryEntries($repo), 'branch'))->toBe(['feature/other']);
    } finally {
        foreach ([$slug, $slug.'_testing', $slug.'_test_1', $other, $other.'_testing'] as $database) {
            dropDatabase('mysql', $database);
        }

        removeRepo($repo);
    }
});

it('does not look for databases a template names past the server limit', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    useServer('mysql');
    config()->set('worktree.database.connections', [[
        'connection' => null,
        'env' => 'DB_DATABASE',
        'name' => 'a_fixed_start_long_enough_to_fill_the_database_limit_{slug}',
    ]]);

    try {
        $this->artisan('worktree:prune', ['--orphans' => true])
            ->expectsOutputToContain("cut short to fit the server's limit")
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

/**
 * Console output with stdout and stderr kept apart, as Claude Code reads them:
 * the worktree's path from stdout, nothing else.
 */
final class SplitOutput extends StreamOutput implements ConsoleOutputInterface
{
    protected OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(fopen('php://memory', 'w+'), self::VERBOSITY_NORMAL, false);

        $this->stderr = new StreamOutput(fopen('php://memory', 'w+'), self::VERBOSITY_NORMAL, false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('Sections are not used here.');
    }

    public function stdout(): string
    {
        return self::contents($this);
    }

    public function stderr(): string
    {
        return $this->stderr instanceof StreamOutput ? self::contents($this->stderr) : '';
    }

    protected static function contents(StreamOutput $output): string
    {
        rewind($output->getStream());

        return (string) stream_get_contents($output->getStream());
    }
}

/**
 * Runs worktree:hook the way Claude Code does, with the hook's JSON on stdin.
 * The console kernel rethrows a command's exception in tests, so a failure
 * hands back the output so far along with it.
 *
 * @param  array<array-key, mixed>|string  $payload
 * @param  array<string, mixed>  $options
 * @param  string|null  $from  Where the hook starts: Claude runs it from where it works.
 * @return array{code: int|null, stdout: string, stderr: string, exception: Throwable|null}
 */
function runHook(string $event, array|string $payload, array $options = [], string $tool = 'claude', ?string $from = null): array
{
    return runHookInput(new ArrayInput(['command' => 'worktree:hook', 'tool' => $tool, 'event' => $event, ...$options]), $payload, $from);
}

/**
 * @param  array<array-key, mixed>|string  $payload
 * @return array{code: int|null, stdout: string, stderr: string, exception: Throwable|null}
 */
function runHookInput(Input $input, array|string $payload, ?string $from = null): array
{
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, is_string($payload) ? $payload : (string) json_encode($payload));
    rewind($stdin);

    $input->setStream($stdin);

    $output = new SplitOutput;
    $code = null;
    $exception = null;

    // The hook moves to the main checkout, which a test must leave again for
    // its repository to be deleted on Windows.
    $cwd = (string) getcwd();

    if ($from !== null) {
        chdir($from);
    }

    try {
        $code = app(ConsoleKernel::class)->handle($input, $output);
    } catch (Throwable $thrown) {
        $exception = $thrown;
    } finally {
        chdir($cwd);
    }

    return ['code' => $code, 'stdout' => $output->stdout(), 'stderr' => $output->stderr(), 'exception' => $exception];
}

function hookWorktree(string $repo, string $name): string
{
    return dirname($repo).'/'.basename($repo).'-worktree-'.$name;
}

function branchExists(string $repo, string $branch): bool
{
    return Process::path($repo)->run(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"])->successful();
}

it('creates a worktree for a Claude Code hook and prints only its path', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'bold-oak-a3f2');

    try {
        $run = runHook('create', ['session_id' => 'abc', 'cwd' => $repo, 'hook_event_name' => 'WorktreeCreate', 'name' => 'bold-oak-a3f2', 'agent_id' => 'agent-7'], ['--no-install' => true]);

        expect($run['exception'])->toBeNull()
            ->and($run['code'])->toBe(0)
            // The path is all Claude may find on stdout, as the filesystem names it.
            ->and($run['stdout'])->toBe(realpath($path).PHP_EOL)
            ->and($run['stderr'])->toContain('Creating worktree')
            ->and((string) file_get_contents($path.'/.env'))->toContain('APP_URL=http://'.basename($path).'.test');

        $entry = registryEntries($repo)[0];

        expect($entry['tool'])->toBe('claude')
            ->and($entry['branch'])->toBe('worktree-bold-oak-a3f2')
            ->and($entry['agent'])->toBe('agent-7');
    } finally {
        removeRepo($repo);
    }
});

it('hands back a worktree the hook already set up without provisioning it again', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'again');

    try {
        expect(runHook('create', ['name' => 'again'], ['--no-install' => true])['code'])->toBe(0);

        file_put_contents($path.'/.env', "KEPT=1\n");

        $run = runHook('create', ['name' => 'again'], ['--no-install' => true]);

        // A second provisioning would copy the .env in fresh and migrate again.
        expect($run['code'])->toBe(0)
            ->and($run['stdout'])->toBe(realpath($path).PHP_EOL)
            ->and($run['stderr'])->toContain('already set up')->not->toContain('resuming')
            ->and((string) file_get_contents($path.'/.env'))->toBe("KEPT=1\n");
    } finally {
        removeRepo($repo);
    }
});

it('removes what a failed hook create made, keeping a branch that was there before', function (bool $branchExisted) {
    config()->set('worktree.steps', ['php -r "exit(1);"']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'broken');

    if ($branchExisted) {
        Process::path($repo)->run(['git', 'branch', 'worktree-broken'])->throw();
    }

    try {
        $run = runHook('create', ['name' => 'broken'], ['--no-migrate' => true]);

        // Claude never learns the path of a create that failed, so nothing
        // would ever finish it. The reason goes to stderr, which Claude shows.
        expect($run['exception'])->toBeNull()
            ->and($run['code'])->toBe(1)
            ->and($run['stdout'])->toBe('')
            ->and($run['stderr'])->toContain('removed again')->toContain('exit(1)')
            ->and(is_dir($path))->toBeFalse()
            ->and(registryEntries($repo))->toBe([])
            ->and(branchExists($repo, 'worktree-broken'))->toBe($branchExisted);
    } finally {
        removeRepo($repo);
    }
})->with(['a new branch' => false, 'an existing branch' => true]);

it('removes a clean hook worktree and keeps its branch', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'done');

    try {
        expect(runHook('create', ['name' => 'done'], ['--no-install' => true])['code'])->toBe(0);

        commitInWorktree($path);

        // Claude runs the hook from where it works, inside the worktree, and
        // Windows will not delete a directory a process is in.
        $run = runHook('remove', ['hook_event_name' => 'WorktreeRemove', 'worktree_path' => realpath($path)], from: $path);

        expect($run['exception'])->toBeNull()
            ->and($run['code'])->toBe(0)
            ->and(is_dir($path))->toBeFalse()
            ->and(registryEntries($repo))->toBe([])
            // The commit is still on the branch.
            ->and(branchExists($repo, 'worktree-done'))->toBeTrue()
            ->and(trim(Process::path($repo)->run(['git', 'log', '-1', '--format=%s', 'worktree-done'])->output()))->toBe('Add feature');
    } finally {
        removeRepo($repo);
    }
});

it('keeps a hook worktree with uncommitted work', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'busy');

    // A setting that hides untracked files from a plain git status.
    Process::path($repo)->run(['git', 'config', 'status.showUntrackedFiles', 'no'])->throw();

    try {
        expect(runHook('create', ['name' => 'busy'], ['--no-install' => true])['code'])->toBe(0);

        file_put_contents($path.'/draft.txt', "not committed\n");

        $run = runHook('remove', ['worktree_path' => $path]);

        expect($run['code'])->toBe(1)
            ->and($run['stderr'])->toContain('uncommitted or untracked changes')
            ->and(is_file($path.'/draft.txt'))->toBeTrue()
            ->and(registryEntries($repo))->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
});

it('keeps a hook worktree whose commits no branch holds', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'detached');

    try {
        expect(runHook('create', ['name' => 'detached'], ['--no-install' => true])['code'])->toBe(0);

        Process::path($path)->run(['git', 'checkout', '--detach'])->throw();
        commitInWorktree($path);

        // A ref of the worktree's own goes with it.
        Process::path($path)->run(['git', 'update-ref', 'refs/worktree/mine', 'HEAD'])->throw();

        $run = runHook('remove', ['worktree_path' => $path]);

        expect($run['code'])->toBe(1)
            ->and($run['stderr'])->toContain('commits no branch holds')
            ->and(is_dir($path))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('provisions a hook worktree again when its last run stopped partway', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'halfway');

    try {
        expect(runHook('create', ['name' => 'halfway'], ['--no-install' => true])['code'])->toBe(0);

        // A run killed after git made the worktree records it, but never
        // marks it provisioned.
        $registry = json_decode((string) file_get_contents(registryFile($repo)), true);
        unset($registry['worktrees'][0]['provisioned']);
        file_put_contents(registryFile($repo), (string) json_encode($registry));
        file_put_contents($path.'/.env', "STALE=1\n");

        $run = runHook('create', ['name' => 'halfway'], ['--no-install' => true]);

        expect($run['code'])->toBe(0)
            ->and($run['stdout'])->toBe(realpath($path).PHP_EOL)
            ->and($run['stderr'])->toContain('resuming')
            ->and((string) file_get_contents($path.'/.env'))->not->toContain('STALE=1')
            ->and(registryEntries($repo)[0]['provisioned'])->not->toBeNull();
    } finally {
        removeRepo($repo);
    }
});

it('hands back a hook worktree the session moved to another branch', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'moved');

    try {
        expect(runHook('create', ['name' => 'moved'], ['--no-install' => true])['code'])->toBe(0);

        Process::path($path)->run(['git', 'checkout', '-b', 'feature/elsewhere'])->throw();

        $run = runHook('create', ['name' => 'moved'], ['--no-install' => true]);

        expect($run['code'])->toBe(0)
            ->and($run['stdout'])->toBe(realpath($path).PHP_EOL)
            ->and($run['stderr'])->toContain('already set up');
    } finally {
        removeRepo($repo);
    }
});

it('refuses a hook worktree whose directory is gone but git still lists', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'deleted');

    try {
        expect(runHook('create', ['name' => 'deleted'], ['--no-install' => true])['code'])->toBe(0);
        expect(Directory::delete($path))->toBeTrue();

        $run = runHook('create', ['name' => 'deleted'], ['--no-install' => true]);

        expect($run['code'])->toBe(1)
            ->and($run['stdout'])->toBe('')
            ->and($run['stderr'])->toContain('worktree:prune');
    } finally {
        removeRepo($repo);
    }
});

it('leaves what was there before alone when a hook create fails', function (string $before) {
    config()->set('worktree.steps', ['php -r "exit(1);"']);

    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = hookWorktree($repo, 'kept');

    if ($before === 'worktree') {
        Process::path($repo)->run(['git', 'worktree', 'add', $path, '-b', 'worktree-kept'])->throw();
    } else {
        mkdir($path);
    }

    file_put_contents($path.'/mine.txt', "someone's work\n");

    try {
        $run = runHook('create', ['name' => 'kept'], ['--no-migrate' => true]);

        // A worktree with no record could hold anything, so it is not set up
        // over either.
        expect($run['code'])->toBe(1)
            ->and($run['stderr'])->not->toContain('removed again')->toContain($before === 'worktree' ? 'has no record of' : 'already exists')
            ->and(is_file($path.'/mine.txt'))->toBeTrue()
            ->and(branchExists($repo, 'worktree-kept'))->toBe($before === 'worktree');
    } finally {
        removeRepo($repo);
    }
})->with(['a worktree on the branch' => 'worktree', 'a directory with something in it' => 'directory']);

it('leaves a worktree the hook did not make alone', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = dirname($repo).'/'.basename($repo).'-feature-login';

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        $run = runHook('remove', ['worktree_path' => $path]);

        expect($run['code'])->toBe(1)
            ->and($run['stderr'])->toContain('not set up by worktree:hook')
            ->and(is_dir($path))->toBeTrue();

        // Nor the main checkout.
        $main = runHook('remove', ['worktree_path' => $repo]);

        expect($main['code'])->toBe(1)
            ->and($main['stderr'])->toContain('is the main repository')
            ->and(is_dir($repo.'/.git'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('takes a worktree that is already gone as removed', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        expect(runHook('remove', ['worktree_path' => hookWorktree($repo, 'never')])['code'])->toBe(0);
    } finally {
        removeRepo($repo);
    }
});

it('refuses hook input it cannot read', function (string $event, array|string $payload, string $tool, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $run = runHook($event, $payload, [], $tool);

        expect($run['code'])->toBe(1)
            ->and($run['stdout'])->toBe('')
            ->and($run['stderr'])->toContain($message)
            ->and(glob(dirname($repo).'/'.basename($repo).'-*') ?: [])->toBe([]);
    } finally {
        removeRepo($repo);
    }
})->with([
    'not JSON' => ['create', 'not json', 'claude', 'not a JSON object'],
    'no name' => ['create', ['session_id' => 'abc'], 'claude', 'names no worktree'],
    'a name of symbols only' => ['create', ['name' => '/.../'], 'claude', 'names no worktree'],
    'no path' => ['remove', ['session_id' => 'abc'], 'claude', 'names no worktree'],
    'another event' => ['update', ['name' => 'x'], 'claude', 'create or remove'],
    'another tool' => ['create', ['name' => 'x'], 'cursor', 'the tool is claude'],
]);

it('keeps the branch when told to', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    try {
        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        $this->artisan('worktree:teardown', ['name' => 'feature/login', '--abandon' => true, '--force' => true, '--keep-branch' => true])
            ->assertSuccessful();

        expect(branchExists($repo, 'feature/login'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

/**
 * An artisan in the main checkout, for the installer to point the hooks at,
 * that reports what it was started with when run.
 */
function hookArtisan(string $repo): void
{
    file_put_contents($repo.'/artisan', "<?php\nfile_put_contents(__DIR__.'/hook-call.json', json_encode(['arguments' => array_slice(\$argv, 1), 'display_errors' => ini_get('display_errors')]));\n");
}

/**
 * The settings file the installer writes.
 *
 * @return array<string, mixed>
 */
function claudeSettings(string $repo): array
{
    return json_decode((string) file_get_contents($repo.'/.claude/settings.local.json'), true);
}

/**
 * The handler the installer wrote for an event.
 *
 * @return array{type: string, command: string, args: list<string>, timeout: int, statusMessage: string}
 */
function installedHook(string $repo, string $event): array
{
    return claudeSettings($repo)['hooks'][$event][0]['hooks'][0];
}

/**
 * Runs an installed hook the way Claude Code would, the console reading the
 * arguments after the artisan file as artisan itself does.
 *
 * @param  array<array-key, mixed>  $payload
 * @return array{code: int|null, stdout: string, stderr: string, exception: Throwable|null}
 */
function runInstalledHook(string $repo, string $event, array $payload, ?string $from = null): array
{
    return runHookInput(new ArgvInput(['artisan', ...array_slice(installedHook($repo, $event)['args'], 3)]), $payload, $from);
}

it('installs the Claude Code hooks into the local settings', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    // Claude Code adds the file to a global excludes file when it writes one.
    Process::path($repo)->run(['git', 'config', 'core.excludesFile', $repo.'/.git/no-excludes'])->throw();

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain('is not gitignored')
            ->assertSuccessful();

        $artisan = (string) realpath($repo.'/artisan');

        // Exec form: Claude spawns PHP itself, with no shell in between.
        expect(installedHook($repo, 'WorktreeCreate'))->toBe([
            'type' => 'command',
            'command' => PHP_BINARY,
            'args' => ['-d', 'display_errors=stderr', $artisan, 'worktree:hook', 'claude', 'create', '--base=main', '--no-secure'],
            'timeout' => 1800,
            'statusMessage' => 'Setting up the worktree',
        ])->and(installedHook($repo, 'WorktreeRemove'))->toBe([
            'type' => 'command',
            'command' => PHP_BINARY,
            'args' => ['-d', 'display_errors=stderr', $artisan, 'worktree:hook', 'claude', 'remove'],
            'timeout' => 1800,
            'statusMessage' => 'Finishing the worktree',
        ])->and(is_file($repo.'/.claude/settings.json'))->toBeFalse();

        // Started as written, it reaches the app's artisan.
        $result = Process::path(dirname($repo))->run([PHP_BINARY, ...installedHook($repo, 'WorktreeCreate')['args']]);
        $call = json_decode((string) file_get_contents($repo.'/hook-call.json'), true);

        expect($result->successful())->toBeTrue()
            ->and($call['arguments'])->toBe(['worktree:hook', 'claude', 'create', '--base=main', '--no-secure'])
            ->and($call['display_errors'])->toBe('stderr');

        // A rerun changes nothing, and once the file is gitignored, says
        // nothing about it.
        $first = (string) file_get_contents($repo.'/.claude/settings.local.json');
        file_put_contents($repo.'/.gitignore', ".claude/settings.local.json\n", FILE_APPEND);

        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->doesntExpectOutputToContain('is not gitignored')
            ->assertSuccessful();

        expect((string) file_get_contents($repo.'/.claude/settings.local.json'))->toBe($first);

        // Another run's options replace the earlier ones.
        $this->artisan('worktree:install-agent', ['agent' => 'claude', '--secure' => true, '--clone' => true, '--no-migrate' => true, '--timeout' => '900'])
            ->assertSuccessful();

        expect(claudeSettings($repo)['hooks']['WorktreeCreate'])->toHaveCount(1)
            ->and(installedHook($repo, 'WorktreeCreate')['args'])->toBe(['-d', 'display_errors=stderr', $artisan, 'worktree:hook', 'claude', 'create', '--base=main', '--no-migrate', '--clone'])
            ->and(installedHook($repo, 'WorktreeRemove')['timeout'])->toBe(900);
    } finally {
        removeRepo($repo);
    }
});

it('adds the hooks to local settings that are already there', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    mkdir($repo.'/.claude');
    file_put_contents($repo.'/.claude/settings.local.json', (string) json_encode([
        'env' => new stdClass,
        'permissions' => ['allow' => ['Bash(composer test)']],
        'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => './check.sh']]]]],
    ]));

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude'])->assertSuccessful();

        $settings = claudeSettings($repo);

        expect((string) file_get_contents($repo.'/.claude/settings.local.json'))->toContain('"env": {}')
            ->and($settings['permissions'])->toBe(['allow' => ['Bash(composer test)']])
            ->and($settings['hooks']['PreToolUse'][0]['hooks'][0]['command'])->toBe('./check.sh')
            ->and(array_keys($settings['hooks']))->toBe(['PreToolUse', 'WorktreeCreate', 'WorktreeRemove']);
    } finally {
        removeRepo($repo);
    }
});

it('refuses to install beside worktree hooks it did not write', function (string $file, string $contents, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    mkdir(dirname($repo.'/'.$file));
    file_put_contents($repo.'/'.$file, $contents);

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain($message)
            ->assertFailed();

        // What was there is left byte for byte, and nothing else is written.
        expect((string) file_get_contents($repo.'/'.$file))->toBe($contents)
            ->and(glob(dirname($repo.'/'.$file).'/*') ?: [])->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
})->with([
    'another create hook' => ['.claude/settings.local.json', '{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"./make-worktree.sh"}]}]}}', 'already has a WorktreeCreate hook of its own'],
    'another remove hook' => ['.claude/settings.local.json', '{"hooks":{"WorktreeRemove":[{"hooks":[{"type":"http","url":"http://localhost:9000"}]}]}}', 'already has a WorktreeRemove hook of its own'],
    'a hook in the shared settings' => ['.claude/settings.json', '{"hooks":{"WorktreeCreate":[{"hooks":[{"type":"command","command":"php artisan worktree:hook claude create"}]}]}}', 'Remove it there'],
    'local settings it cannot read' => ['.claude/settings.local.json', '{"hooks": [', 'left as it is'],
    'shared settings it cannot read' => ['.claude/settings.json', '{"hooks": {"WorktreeCreate": {}}}', 'is unknown'],
]);

it('bases the hook worktrees on the branch checked out when the configured one is missing', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    Process::path($repo)->run(['git', 'branch', '-m', 'main', 'master'])->throw();

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain('branch off [master]')
            ->assertSuccessful();

        expect(installedHook($repo, 'WorktreeCreate')['args'])->toContain('--base=master');

        $this->artisan('worktree:install-agent', ['agent' => 'claude', '--base' => 'develop'])
            ->expectsOutputToContain('[develop] is not a branch')
            ->assertFailed();

        expect(installedHook($repo, 'WorktreeCreate')['args'])->toContain('--base=master');

        Process::path($repo)->run(['git', 'branch', 'develop'])->throw();

        $this->artisan('worktree:install-agent', ['agent' => 'claude', '--base' => 'develop'])
            ->doesntExpectOutputToContain('branch off [')
            ->assertSuccessful();

        expect(installedHook($repo, 'WorktreeCreate')['args'])->toContain('--base=develop');

        // With nothing checked out, there is no branch to fall back on.
        Process::path($repo)->run(['git', 'checkout', '--detach'])->throw();

        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain('Pass --base')
            ->assertFailed();
    } finally {
        removeRepo($repo);
    }
});

it('refuses to install what it cannot', function (string $agent, array $options, bool $artisan, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);

    if ($artisan) {
        hookArtisan($repo);
    }

    try {
        $this->artisan('worktree:install-agent', ['agent' => $agent, ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(is_dir($repo.'/.claude'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
})->with([
    'another agent' => ['aider', [], true, 'or devin, not [aider]'],
    'a time limit that is not a number' => ['claude', ['--timeout' => 'soon'], true, 'number of seconds'],
    'no artisan file' => ['claude', [], false, 'no artisan file'],
]);

it('provisions and finishes worktrees through the hooks it installs, as it was told', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);
    $path = hookWorktree($repo, 'agent-one');
    $name = basename($path);

    // Without the installed profile, this would secure the site.
    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.secure', true);
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', [
        'links' => ['output' => herdLinksJson(basename($repo), $name)],
    ]));

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude', '--no-install' => true])->assertSuccessful();

        $run = runInstalledHook($repo, 'WorktreeCreate', ['name' => 'agent-one']);
        $entry = registryEntries($repo)[0];

        expect($run['exception'])->toBeNull()
            ->and($run['code'])->toBe(0)
            ->and($run['stdout'])->toBe(realpath($path).PHP_EOL)
            ->and(fakeSiteToolCalls('herd'))->toBe(["link {$name} in {$name}", 'links --json in '.basename($repo)])
            ->and((string) file_get_contents($path.'/.env'))->toContain("APP_URL=http://{$name}.test")
            ->and($entry['secure'])->toBeFalse()
            ->and($entry['tool'])->toBe('claude')
            // --no-install reached setup: composer.json is there, vendor is not.
            ->and(is_file($path.'/composer.json'))->toBeTrue()
            ->and(is_dir($path.'/vendor'))->toBeFalse();

        // The temporary directory's path may hold "claude" too, so the cell
        // is matched on its own, between the spaces that pad the columns.
        expect(Artisan::call('worktree:list'))->toBe(0)
            ->and(Artisan::output())->toMatch('/\sTool\s/')->toMatch('/\sclaude\s/');

        fakeSiteTool('herd');

        $removed = runInstalledHook($repo, 'WorktreeRemove', ['worktree_path' => realpath($path)], from: $path);

        // Never secured, so never unsecured.
        expect($removed['code'])->toBe(0)
            ->and(is_dir($path))->toBeFalse()
            ->and(fakeSiteToolCalls('herd'))->toBe(["unlink {$name} in ".basename($repo)])
            ->and(branchExists($repo, 'worktree-agent-one'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('warns when the local settings are committed and when Claude stops reading .worktreeinclude', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    mkdir($repo.'/.claude');
    file_put_contents($repo.'/.claude/settings.local.json', "{}\n");
    file_put_contents($repo.'/.worktreeinclude', "storage/oauth-*.key\n");
    Process::path($repo)->run(['git', 'add', '-f', '.claude/settings.local.json'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'settings'])->throw();

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'claude'])
            ->expectsOutputToContain('git rm --cached')
            ->expectsOutputToContain('no longer reads .worktreeinclude')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

/**
 * An artisan in the main checkout that logs each adopt the git hook starts,
 * then fails, which the hook must keep from failing git worktree add.
 */
function adoptArtisan(string $repo): void
{
    file_put_contents($repo.'/artisan', "<?php\nfile_put_contents(__DIR__.'/adopt-calls.log', json_encode(['arguments' => array_slice(\$argv, 1), 'cwd' => getcwd(), 'display_errors' => ini_get('display_errors')]).\"\n\", FILE_APPEND);\nexit(3);\n");
}

/**
 * What the stand-in artisan was started with, one entry per run.
 *
 * @return list<array{arguments: list<string>, cwd: string, display_errors: string}>
 */
function adoptCalls(string $repo): array
{
    $log = $repo.'/adopt-calls.log';

    return is_file($log) ? array_map(fn (string $line): array => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
}

function gitHookFile(string $repo): string
{
    return $repo.'/.git/hooks/post-checkout';
}

it('adopts each worktree git worktree add makes through the post-checkout hook it installs', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    adoptArtisan($repo);

    file_put_contents($repo.'/conductor.json', '{}');

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'git', '--clone' => true])
            ->expectsOutputToContain('post-checkout hook')
            ->expectsOutputToContain("hold a tool's settings")
            ->assertSuccessful();

        $hook = (string) file_get_contents(gitHookFile($repo));

        expect($hook)->toStartWith("#!/bin/sh\n")->not->toContain("\r");

        // The adopt fails, and git worktree add still succeeds: git keeps the
        // worktree either way.
        $branch = Process::path($repo)->run(['git', 'worktree', 'add', dirname($repo).'/'.basename($repo).'-tool-branch', '-b', 'feature/tool']);
        $detached = Process::path($repo)->run(['git', 'worktree', 'add', '--detach', dirname($repo).'/'.basename($repo).'-tool-detached']);
        $calls = adoptCalls($repo);

        expect($branch->successful())->toBeTrue()
            ->and($detached->successful())->toBeTrue()
            ->and($calls)->toHaveCount(2)
            ->and(array_slice($calls[0]['arguments'], 0, 5))->toBe(['worktree:adopt', '--tool=git', '--unless-set-up', '--no-secure', '--clone'])
            ->and(realpath($calls[0]['arguments'][5]))->toBe(realpath($repo.'-tool-branch'))
            ->and(realpath($calls[1]['arguments'][5]))->toBe(realpath($repo.'-tool-detached'))
            ->and(realpath($calls[0]['cwd']))->toBe(realpath($repo))
            ->and($calls[0]['display_errors'])->toBe('stderr');

        // Switching branches, in the main checkout or a worktree, adopts nothing,
        // and neither does an add that asks to be left alone.
        Process::path($repo)->run(['git', 'checkout', '-b', 'other'])->throw();
        Process::path($repo.'-tool-branch')->run(['git', 'checkout', '-b', 'feature/tool-two'])->throw();
        Process::path($repo)->env(['LARAVEL_WORKTREE_SKIP_ADOPT' => '1'])->run(['git', 'worktree', 'add', '--detach', dirname($repo).'/'.basename($repo).'-tool-skipped'])->throw();

        expect(adoptCalls($repo))->toHaveCount(2);

        // A file checked out on an orphan branch starts from the null commit
        // but checks out no branch; switching back from the orphan branch
        // reaches adopt, which --unless-set-up keeps from setting it up again.
        $worktree = $repo.'-tool-branch';
        Process::path($worktree)->run(['git', 'switch', '--orphan', 'scratch'])->throw();
        file_put_contents($worktree.'/scratch.txt', "draft\n");
        Process::path($worktree)->run(['git', 'add', 'scratch.txt'])->throw();
        Process::path($worktree)->run(['git', 'checkout', '--', 'scratch.txt'])->throw();

        expect(adoptCalls($repo))->toHaveCount(2);

        Process::path($worktree)->run(['git', 'rm', '-q', '--cached', 'scratch.txt'])->throw();
        unlink($worktree.'/scratch.txt');
        Process::path($worktree)->run(['git', 'switch', 'feature/tool-two'])->throw();

        expect(adoptCalls($repo))->toHaveCount(3)
            ->and(adoptCalls($repo)[2]['arguments'])->toContain('--unless-set-up');

        // A rerun writes the same hook.
        $this->artisan('worktree:install-agent', ['agent' => 'git', '--clone' => true])->assertSuccessful();

        expect((string) file_get_contents(gitHookFile($repo)))->toBe($hook);
    } finally {
        removeRepo($repo);
    }
});

it('leaves the worktrees setup and Claude Code make to them when the git hook is installed', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    adoptArtisan($repo);

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'git'])->assertSuccessful();

        $this->artisan('worktree:setup', ['branch' => 'feature/login', '--no-install' => true])->assertSuccessful();

        $run = runHook('create', ['name' => 'agent-one'], ['--no-install' => true]);

        // Adopt would have waited on the lock setup holds around its add.
        expect($run['code'])->toBe(0)
            ->and(adoptCalls($repo))->toBe([]);
    } finally {
        removeRepo($repo);
    }
});

it('refuses to install the git hook over one it did not write', function (string $setup, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    adoptArtisan($repo);

    $existing = "#!/bin/sh\ngit lfs post-checkout \"\$@\"\n";

    if ($setup === 'hook') {
        file_put_contents(gitHookFile($repo), $existing);
    } else {
        Process::path($repo)->run(['git', 'config', 'core.hooksPath', '.husky/_'])->throw();
    }

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'git'])
            ->expectsOutputToContain($message)
            // The guards come with it: the adopt line alone would set a
            // worktree up again on every branch switch in it.
            ->expectsOutputToContain('case "$1" in *[!0]*) exit 0 ;; esac')
            ->expectsOutputToContain('worktree:adopt --tool=git --unless-set-up --no-secure')
            ->assertFailed();

        if ($setup === 'hook') {
            expect((string) file_get_contents(gitHookFile($repo)))->toBe($existing);
        }

        expect(is_dir($repo.'/.husky'))->toBeFalse();
    } finally {
        removeRepo($repo);
    }
})->with([
    'another post-checkout hook' => ['hook', 'is a post-checkout hook of its own'],
    'hooks run from elsewhere' => ['hooks path', 'core.hooksPath'],
]);

it('writes Conductor\'s local settings to run the adopt shim it publishes', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);
    file_put_contents($repo.'/conductor.json', '{"scripts": {"setup": "pnpm install"}}');

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'conductor', '--clone' => true])
            ->expectsOutputToContain('Published [worktree-adopt.php]')
            ->expectsOutputToContain('Commit [worktree-adopt.php]')
            ->expectsOutputToContain('is not gitignored')
            ->expectsOutputToContain("in place of the repository's own setup script")
            ->expectsOutputToContain("Leave .env out of Conductor's files to copy")
            ->assertSuccessful();

        $file = $repo.'/.conductor/settings.local.toml';
        $written = (string) file_get_contents($file);

        expect((string) file_get_contents($repo.'/worktree-adopt.php'))->toBe((string) file_get_contents(__DIR__.'/../resources/stubs/worktree-adopt.php'))
            ->and($written)->toStartWith('# Written by php artisan worktree:install-agent conductor')
            ->and($written)->toContain("[scripts]\nsetup = \"php worktree-adopt.php --tool=conductor --no-secure --clone\"\n");

        // A rerun replaces what it wrote.
        $this->artisan('worktree:install-agent', ['agent' => 'conductor', '--secure' => true])->assertSuccessful();

        expect((string) file_get_contents($file))->toContain('setup = "php worktree-adopt.php --tool=conductor"');

        // Settings of the developer's own are left alone.
        $own = "[scripts]\nsetup = \"./bin/setup\"\n";
        file_put_contents($file, $own);

        $this->artisan('worktree:install-agent', ['agent' => 'conductor'])
            ->expectsOutputToContain('has settings of its own')
            ->expectsOutputToContain('setup = "php worktree-adopt.php --tool=conductor --no-secure"')
            ->assertFailed();

        expect((string) file_get_contents($file))->toBe($own);
    } finally {
        removeRepo($repo);
    }
});

it('runs the adopt shim after Paradev\'s own setup commands', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);
    file_put_contents($repo.'/worktree-adopt.php', "<?php\n");
    Process::path($repo)->run(['git', 'add', 'worktree-adopt.php'])->throw();
    Process::path($repo)->run(['git', 'commit', '-m', 'shim'])->throw();

    mkdir($repo.'/.superset');
    file_put_contents($repo.'/.superset/config.local.json', '{"setup": {"before": ["bun install"]}, "run": ["bun dev"], "env": {}}');

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'paradev'])
            ->doesntExpectOutputToContain('Published [worktree-adopt.php]')
            ->doesntExpectOutputToContain('Commit [worktree-adopt.php]')
            ->expectsOutputToContain('Wait for workspace setup before starting agents')
            ->assertSuccessful();

        $file = $repo.'/.superset/config.local.json';
        $config = json_decode((string) file_get_contents($file), true);

        expect($config['setup'])->toBe(['before' => ['bun install'], 'after' => ['php worktree-adopt.php --tool=paradev --no-secure']])
            ->and($config['run'])->toBe(['bun dev'])
            ->and((string) file_get_contents($file))->toContain('"env": {}')
            ->and((string) file_get_contents($repo.'/worktree-adopt.php'))->toBe("<?php\n");

        // A rerun replaces its own command rather than adding a second.
        $this->artisan('worktree:install-agent', ['agent' => 'paradev', '--no-install' => true])->assertSuccessful();

        expect(json_decode((string) file_get_contents($file), true)['setup']['after'])->toBe(['php worktree-adopt.php --tool=paradev --no-secure --no-install']);
    } finally {
        removeRepo($repo);
    }
});

it('leaves Paradev\'s config alone when it cannot use it', function (string $file, string $contents, string $message) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    mkdir($repo.'/.superset');
    file_put_contents($repo.'/'.$file, $contents);

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'paradev'])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect((string) file_get_contents($repo.'/'.$file))->toBe($contents)
            ->and(glob($repo.'/.superset/*') ?: [])->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
})->with([
    'a TOML config, which turns JSON off' => ['.superset/config.toml', "[setup]\nrun = [\"bun install\"]\n", 'php worktree-adopt.php --tool=paradev --no-secure'],
    'a config it cannot read' => ['.superset/config.local.json', '{"setup": "bun install"}', 'left as it is'],
]);

it('prints where to run the adopt shim for a tool whose setup it does not write', function (string $tool, string $recipe) {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    try {
        $this->artisan('worktree:install-agent', ['agent' => $tool])
            ->expectsOutputToContain('Published [worktree-adopt.php]')
            ->expectsOutputToContain($recipe)
            ->assertSuccessful();

        expect(is_file($repo.'/worktree-adopt.php'))->toBeTrue()
            ->and(array_map(basename(...), glob($repo.'/.*', GLOB_ONLYDIR) ?: []))->toBe(['.', '..', '.git']);
    } finally {
        removeRepo($repo);
    }
})->with([
    'Cursor' => ['cursor', '"setup-worktree": ['],
    'Codex' => ['codex', 'php worktree-adopt.php --tool=codex --no-secure'],
    'Zed' => ['zed', '"create_worktree"'],
    'Devin Desktop' => ['devin', '[.devin/hooks.json]'],
]);

it('points Devin Desktop at the hooks file the project already uses', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);
    mkdir($repo.'/.windsurf');
    file_put_contents($repo.'/.windsurf/hooks.json', '{"hooks": {}}');

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'devin'])
            ->expectsOutputToContain('Add this to [.windsurf/hooks.json]')
            ->expectsOutputToContain('"post_setup_worktree"')
            ->expectsOutputToContain('Merge it into what')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('warns that the git hook would adopt a tool\'s worktrees a second time', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    hookArtisan($repo);

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'git'])->assertSuccessful();

        $this->artisan('worktree:install-agent', ['agent' => 'zed'])
            ->expectsOutputToContain('would be set up twice')
            ->assertSuccessful();

        // Cursor turns git's hooks off for the worktrees it makes.
        $this->artisan('worktree:install-agent', ['agent' => 'cursor'])
            ->doesntExpectOutputToContain('would be set up twice')
            ->assertSuccessful();
    } finally {
        removeRepo($repo);
    }
});

it('keeps a git worktree add working when the lines it prints are added to another hook', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    adoptArtisan($repo);
    file_put_contents(gitHookFile($repo), "#!/bin/sh\necho lfs > /dev/null\n");
    // Git runs only an executable hook outside Windows, as the hook another
    // tool installed would be.
    chmod(gitHookFile($repo), 0755);

    try {
        expect(Artisan::call('worktree:install-agent', ['agent' => 'git']))->toBe(1);

        $output = Artisan::output();
        $block = substr($output, (int) strpos($output, "# Only a new worktree's checkout"));

        file_put_contents(gitHookFile($repo), $block, FILE_APPEND);

        // The adopt fails, and the add still succeeds.
        $added = Process::path($repo)->run(['git', 'worktree', 'add', '--detach', dirname($repo).'/'.basename($repo).'-tool-checkout']);

        expect($added->successful())->toBeTrue()
            ->and(adoptCalls($repo))->toHaveCount(1);
    } finally {
        removeRepo($repo);
    }
});

it('leaves a worktree it set up as it is when asked to, and sets up one it did not', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--unless-set-up' => true, '--no-install' => true])->assertSuccessful();

        file_put_contents($path.'/.env', "KEPT=1\n", FILE_APPEND);

        $this->artisan('worktree:adopt', ['path' => $path, '--unless-set-up' => true, '--no-install' => true])
            ->expectsOutputToContain('is set up already')
            ->assertSuccessful();

        expect((string) file_get_contents($path.'/.env'))->toContain('KEPT=1');

        // Without its .env, nothing says it is still set up.
        unlink($path.'/.env');

        $this->artisan('worktree:adopt', ['path' => $path, '--unless-set-up' => true, '--no-install' => true])
            ->doesntExpectOutputToContain('is set up already')
            ->assertSuccessful();

        expect(is_file($path.'/.env'))->toBeTrue();
    } finally {
        removeRepo($repo);
    }
});

it('serves an adopted worktree over http when told to', function () {
    $repo = tempRepo();
    $this->app->setBasePath($repo);
    $path = toolWorktree($repo, 'tool-checkout');
    $name = basename($repo).'-agent';

    config()->set('worktree.site.driver', 'herd');
    config()->set('worktree.site.secure', true);
    config()->set('worktree.site.drivers.herd.binary', fakeSiteTool('herd', [
        'links' => ['output' => herdLinksJson(basename($repo), $name)],
    ]));

    try {
        $this->artisan('worktree:adopt', ['path' => $path, '--name' => 'agent', '--no-secure' => true, '--no-install' => true])->assertSuccessful();

        expect(fakeSiteToolCalls('herd'))->toBe(["link {$name} in ".basename($path), 'links --json in '.basename($repo)])
            ->and(registryEntries($repo)[0]['secure'])->toBeFalse()
            ->and((string) file_get_contents($path.'/.env'))->toContain("APP_URL=http://{$name}.test");
    } finally {
        removeRepo($repo);
    }
});

it('adopts nothing for another repository that runs the same hook', function () {
    $repo = tempRepo();
    $other = tempRepo();
    $this->app->setBasePath($repo);
    adoptArtisan($repo);

    try {
        $this->artisan('worktree:install-agent', ['agent' => 'git'])->assertSuccessful();

        // As a hooks directory shared through core.hooksPath would.
        copy(gitHookFile($repo), gitHookFile($other));
        Process::path($other)->run(['git', 'worktree', 'add', '--detach', dirname($other).'/'.basename($other).'-tool-checkout'])->throw();

        expect(adoptCalls($repo))->toBe([]);
    } finally {
        removeRepo($repo);
        removeRepo($other);
    }
});
