<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Mozex\Worktree\Exceptions\WorktreeException;
use PDO;

/**
 * Creates and drops databases directly on the server, without selecting a
 * database first, so it works when the target does not exist yet. MySQL,
 * MariaDB, and PostgreSQL are supported; SQLite has no server to talk to.
 */
class DatabaseManager
{
    /**
     * @param  array<string, mixed>  $config  A Laravel database connection config array.
     */
    public function __construct(protected array $config) {}

    public function supported(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    public function create(string $name): void
    {
        $this->guardDriver();

        $pdo = $this->connect();

        if ($this->driver() === 'pgsql' && $this->postgresDatabaseExists($pdo, $name)) {
            return;
        }

        $pdo->exec($this->createStatement($name));
    }

    public function drop(string $name): void
    {
        $this->guardDriver();

        $this->connect()->exec($this->dropStatement($name));
    }

    public function createStatement(string $name): string
    {
        if ($this->driver() === 'pgsql') {
            return 'CREATE DATABASE '.$this->quoteIdentifier($name);
        }

        return 'CREATE DATABASE IF NOT EXISTS '.$this->quoteIdentifier($name);
    }

    public function dropStatement(string $name): string
    {
        if ($this->driver() === 'pgsql') {
            return 'DROP DATABASE IF EXISTS '.$this->quoteIdentifier($name).' WITH (FORCE)';
        }

        return 'DROP DATABASE IF EXISTS '.$this->quoteIdentifier($name);
    }

    public function dsn(): string
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = $this->config['port'] ?? null;

        if ($this->driver() === 'pgsql') {
            return 'pgsql:host='.$host.$this->port($port, 5432).';dbname=postgres';
        }

        return 'mysql:host='.$host.$this->port($port, 3306);
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    protected function connect(): PDO
    {
        $pdo = new PDO(
            $this->dsn(),
            $this->config['username'] ?? null,
            $this->config['password'] ?? null,
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    protected function postgresDatabaseExists(PDO $pdo, string $name): bool
    {
        return $pdo->query('SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($name))->fetchColumn() !== false;
    }

    protected function quoteIdentifier(string $name): string
    {
        if ($this->driver() === 'pgsql') {
            return '"'.str_replace('"', '""', $name).'"';
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    protected function port(int|string|null $port, int $default): string
    {
        return ';port='.($port === null || $port === '' ? $default : $port);
    }

    protected function guardDriver(): void
    {
        if ($this->supported()) {
            return;
        }

        throw WorktreeException::unsupportedDriver($this->driver());
    }
}
