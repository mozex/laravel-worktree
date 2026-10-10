<?php

declare(strict_types=1);

use Mozex\Worktree\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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

/**
 * A connection to the server, or to one database on it when named.
 */
function serverPdo(string $driver, ?string $database = null): PDO
{
    $config = serverConnections()[$driver];
    $dsn = $driver === 'pgsql'
        ? "pgsql:host={$config['host']};port={$config['port']};dbname=".($database ?? 'postgres')
        : "mysql:host={$config['host']};port={$config['port']}".($database === null ? '' : ";dbname={$database}");

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
