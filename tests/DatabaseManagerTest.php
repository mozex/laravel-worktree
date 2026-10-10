<?php

declare(strict_types=1);

use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;

it('builds a mysql dsn and statements', function () {
    $manager = new DatabaseManager([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
    ]);

    expect($manager->dsn())->toBe('mysql:host=127.0.0.1;port=3306')
        ->and($manager->createStatement('blog'))->toBe('CREATE DATABASE IF NOT EXISTS `blog`')
        ->and($manager->dropStatement('blog'))->toBe('DROP DATABASE IF EXISTS `blog`')
        ->and($manager->supported())->toBeTrue();
});

it('builds a postgres dsn and statements', function () {
    $manager = new DatabaseManager([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
    ]);

    expect($manager->dsn())->toBe('pgsql:host=localhost;port=5432;dbname=postgres')
        ->and($manager->createStatement('blog'))->toBe('CREATE DATABASE "blog"')
        ->and($manager->dropStatement('blog'))->toBe('DROP DATABASE IF EXISTS "blog" WITH (FORCE)');
});

it('opens one database when it is named', function () {
    $mysql = new DatabaseManager(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306]);
    $latin = new DatabaseManager(['driver' => 'mariadb', 'host' => 'db', 'charset' => 'latin1']);
    $pgsql = new DatabaseManager(['driver' => 'pgsql', 'host' => 'localhost', 'port' => 5432]);

    // The charset rides along so DDL read for a clone keeps non-ASCII text.
    expect($mysql->dsn('blog'))->toBe('mysql:host=127.0.0.1;port=3306;dbname=blog;charset=utf8mb4')
        ->and($latin->dsn('blog'))->toBe('mysql:host=db;port=3306;dbname=blog;charset=latin1')
        ->and($pgsql->dsn('blog'))->toBe('pgsql:host=localhost;port=5432;dbname=blog');
});

it('builds the postgres clone statement and tool commands', function () {
    $manager = new DatabaseManager([
        'driver' => 'pgsql',
        'host' => 'db',
        'port' => 5433,
        'username' => 'forge',
        'password' => 'secret',
    ]);

    expect($manager->templateStatement('blog', 'blog_feature'))->toBe('CREATE DATABASE "blog_feature" TEMPLATE "blog"')
        ->and($manager->dumpCommand('blog', '/tmp/dump'))->toBe([
            'pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file=/tmp/dump',
            '--host=db', '--port=5433', '--username=forge', '--dbname=blog',
        ])
        ->and($manager->restoreCommand('blog_feature', '/tmp/dump'))->toBe([
            'pg_restore', '--no-owner', '--no-privileges',
            '--host=db', '--port=5433', '--username=forge', '--dbname=blog_feature', '/tmp/dump',
        ])
        // The password travels in the environment, never on the command line.
        ->and($manager->toolEnvironment())->toBe(['PGPASSWORD' => 'secret'])
        ->and((new DatabaseManager(['driver' => 'pgsql']))->toolEnvironment())->toBe([]);
});

it('tells whether a database exists', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    $manager = new DatabaseManager(serverConnections()[$driver]);
    $name = 'wt_exists_'.bin2hex(random_bytes(3));

    try {
        expect($manager->exists($name))->toBeFalse();

        $manager->create($name);

        expect($manager->exists($name))->toBeTrue();
    } finally {
        $manager->drop($name);
    }
})->with(['mysql', 'pgsql']);

it('treats mariadb like mysql', function () {
    $manager = new DatabaseManager(['driver' => 'mariadb', 'host' => 'db']);

    expect($manager->supported())->toBeTrue()
        ->and($manager->dsn())->toBe('mysql:host=db;port=3306');
});

it('falls back to the default port', function () {
    expect((new DatabaseManager(['driver' => 'pgsql', 'host' => 'db']))->dsn())
        ->toBe('pgsql:host=db;port=5432;dbname=postgres');
});

it('treats sqlite as a supported file database', function () {
    $manager = new DatabaseManager(['driver' => 'sqlite', 'database' => '/sites/blog/database/database.sqlite']);

    expect($manager->supported())->toBeTrue()
        ->and($manager->isFile())->toBeTrue()
        ->and($manager->isServer())->toBeFalse()
        ->and($manager->database())->toBe('/sites/blog/database/database.sqlite');
});

it('classifies server drivers', function (string $driver) {
    $manager = new DatabaseManager(['driver' => $driver]);

    expect($manager->isServer())->toBeTrue()
        ->and($manager->isFile())->toBeFalse();
})->with(['mysql', 'mariadb', 'pgsql']);

it('refuses to create a file database on a server', function () {
    // SQLite has no server to create anything on: the file rides with the worktree.
    (new DatabaseManager(['driver' => 'sqlite']))->create('blog');
})->throws(WorktreeException::class, 'sqlite');

it('rejects a driver it does not know', function () {
    $manager = new DatabaseManager(['driver' => 'mongodb']);

    expect($manager->supported())->toBeFalse()
        ->and($manager->isServer())->toBeFalse()
        ->and($manager->isFile())->toBeFalse();
});

it('treats an empty connection config as unsupported', function () {
    // An unknown connection name resolves to an empty config; defaulting the
    // driver would mean blindly connecting to a server nobody configured.
    $manager = new DatabaseManager([]);

    expect($manager->supported())->toBeFalse()
        ->and($manager->isServer())->toBeFalse()
        ->and($manager->isFile())->toBeFalse();
});

it('lists parallel-test derivative databases', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    $manager = new DatabaseManager(serverConnections()[$driver]);

    $base = 'wt_derivatives';

    try {
        $manager->create($base);
        $manager->create($base.'_test_lane1');
        $manager->create($base.'_test_2');
        $manager->create($base.'_testing');
        // A sibling worktree's database: matches the LIKE prefix, but its
        // suffix is not a parallel token, so it must never be listed.
        $manager->create($base.'_test_helpers');

        expect($manager->parallelDerivatives($base))->toBe([$base.'_test_2', $base.'_test_lane1'])
            ->and($manager->parallelDerivatives($base.'_testing'))->toBe([]);
    } finally {
        foreach ([$base, $base.'_test_lane1', $base.'_test_2', $base.'_testing', $base.'_test_helpers'] as $name) {
            $manager->drop($name);
        }
    }
})->with(['mysql', 'pgsql']);

it('refuses to list derivatives for a file database', function () {
    (new DatabaseManager(['driver' => 'sqlite']))->parallelDerivatives('blog');
})->throws(WorktreeException::class, 'sqlite');
