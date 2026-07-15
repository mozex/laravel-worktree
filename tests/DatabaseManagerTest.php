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
