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

it('rejects unsupported drivers', function () {
    $manager = new DatabaseManager(['driver' => 'sqlite']);

    expect($manager->supported())->toBeFalse();

    $manager->create('blog');
})->throws(WorktreeException::class, 'sqlite');
