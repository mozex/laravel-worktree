<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Mozex\Worktree\Exceptions\WorktreeException;
use PDO;

/**
 * Creates and drops databases directly on the server, without selecting a
 * database first, so it works when the target does not exist yet.
 *
 * Drivers fall into two groups. A server database (MySQL, MariaDB, PostgreSQL)
 * is shared between every worktree, so each one needs a database of its own,
 * created here. A file database (SQLite) lives inside the worktree, so it is
 * already isolated and there is nothing to create on a server or drop
 * afterwards; callers only have to make sure the file exists.
 */
class DatabaseManager
{
    /**
     * @param  array<string, mixed>  $config  A Laravel database connection config array.
     */
    public function __construct(protected array $config) {}

    public function supported(): bool
    {
        return $this->isServer() || $this->isFile();
    }

    /**
     * A database shared between worktrees, which therefore needs a name per worktree.
     */
    public function isServer(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * A database that is a file, and so is isolated by the worktree itself.
     */
    public function isFile(): bool
    {
        return $this->driver() === 'sqlite';
    }

    /**
     * The configured database: a name for a server driver, a file path for SQLite.
     */
    public function database(): string
    {
        return (string) ($this->config['database'] ?? '');
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

    /**
     * Databases Laravel's parallel testing derived from this one, named
     * "{name}_test_{token}". The token is a paratest worker index (digits),
     * or a lane from mozex/laravel-test-lanes ("lane" plus digits). They are
     * created by test runs rather than by worktree:setup, so teardown
     * discovers them by name; without this they would outlive the worktree
     * forever.
     *
     * The suffix check is load-bearing: slugs collapse every separator to
     * "_", so a sibling worktree on a branch like "feature/login-test-helpers"
     * lives at "{name}_test_helpers" and matches the LIKE prefix. Only a
     * token-shaped suffix marks a database as a test derivative; anything
     * else belongs to someone and survives.
     *
     * @return list<string>
     */
    public function parallelDerivatives(string $name): array
    {
        $this->guardDriver();

        $prefix = $name.'_test_';
        $pattern = str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $prefix).'%';

        $statement = $this->connect()->prepare(
            $this->driver() === 'pgsql'
                ? 'SELECT datname FROM pg_database WHERE datname LIKE ? ORDER BY datname'
                : 'SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE ? ORDER BY schema_name',
        );
        $statement->execute([$pattern]);

        $derivatives = [];

        /** @var string $database */
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $database) {
            if (preg_match('/^(?:lane)?\d+$/', substr($database, strlen($prefix))) === 1) {
                $derivatives[] = $database;
            }
        }

        return $derivatives;
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

    /**
     * An empty config (an unknown connection name resolves to one) has no
     * driver, and must classify as unsupported rather than defaulting to a
     * server that would then be connected to blindly.
     */
    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? '');
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

    /**
     * create() and drop() only mean anything for a server database; a file
     * database is created and removed with the worktree itself.
     */
    protected function guardDriver(): void
    {
        if ($this->isServer()) {
            return;
        }

        throw WorktreeException::unsupportedDriver($this->driver());
    }
}
