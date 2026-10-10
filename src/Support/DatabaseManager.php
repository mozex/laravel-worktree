<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Mozex\Worktree\Exceptions\WorktreeException;
use PDO;
use PDOException;

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

    /**
     * The table prefix Laravel puts in front of every table on this connection.
     */
    public function prefix(): string
    {
        return (string) ($this->config['prefix'] ?? '');
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

    public function exists(string $name): bool
    {
        $this->guardDriver();

        $pdo = $this->connect();

        if ($this->driver() === 'pgsql') {
            return $this->postgresDatabaseExists($pdo, $name);
        }

        $statement = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$name]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Creates a Postgres database as a copy of another, which is the fastest
     * clone there is: the server copies the files without parsing a row.
     * Postgres refuses while any other session is connected to the source (a
     * queue worker, a database GUI), and false reports exactly that refusal so
     * the caller can copy through pg_dump instead. Any other error is thrown.
     */
    public function createFromTemplate(string $source, string $target): bool
    {
        try {
            $this->connect()->exec($this->templateStatement($source, $target));
        } catch (PDOException $exception) {
            if ($exception->getCode() === '55006') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    public function templateStatement(string $source, string $target): string
    {
        return 'CREATE DATABASE '.$this->quoteIdentifier($target).' TEMPLATE '.$this->quoteIdentifier($source);
    }

    /**
     * The pg_dump command that writes a database to a file in the custom
     * format, without ownership or grants, so a restore works under whichever
     * role the connection uses.
     *
     * @return list<string>
     */
    public function dumpCommand(string $database, string $file): array
    {
        return ['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$file, ...$this->toolConnection(), '--dbname='.$database];
    }

    /**
     * @return list<string>
     */
    public function restoreCommand(string $database, string $file): array
    {
        return ['pg_restore', '--no-owner', '--no-privileges', ...$this->toolConnection(), '--dbname='.$database, $file];
    }

    /**
     * The password reaches pg_dump and pg_restore through the environment,
     * which libpq reads, rather than the command line, where any process
     * listing would show it.
     *
     * @return array<string, string>
     */
    public function toolEnvironment(): array
    {
        $password = (string) ($this->config['password'] ?? '');

        return $password === '' ? [] : ['PGPASSWORD' => $password];
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

    /**
     * Without a database, the DSN reaches the server alone (Postgres through
     * its maintenance database), which is what creating and dropping need and
     * what identifies the server. With one, it opens that database, and a
     * MySQL connection then also names its charset so the DDL read off it for
     * a clone keeps any non-ASCII comments and defaults intact.
     */
    public function dsn(?string $database = null): string
    {
        $host = (string) ($this->config['host'] ?? '127.0.0.1');
        $port = $this->config['port'] ?? null;

        if ($this->driver() === 'pgsql') {
            return 'pgsql:host='.$host.$this->port($port, 5432).';dbname='.($database ?? 'postgres');
        }

        $dsn = 'mysql:host='.$host.$this->port($port, 3306);

        if ($database === null) {
            return $dsn;
        }

        return $dsn.';dbname='.$database.';charset='.(string) ($this->config['charset'] ?? 'utf8mb4');
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

    /**
     * A connection to the server, or to one database on it when named.
     */
    public function connect(?string $database = null): PDO
    {
        $pdo = new PDO(
            $this->dsn($database),
            $this->config['username'] ?? null,
            $this->config['password'] ?? null,
        );

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function quoteIdentifier(string $name): string
    {
        if ($this->driver() === 'pgsql') {
            return '"'.str_replace('"', '""', $name).'"';
        }

        return '`'.str_replace('`', '``', $name).'`';
    }

    protected function postgresDatabaseExists(PDO $pdo, string $name): bool
    {
        return $pdo->query('SELECT 1 FROM pg_database WHERE datname = '.$pdo->quote($name))->fetchColumn() !== false;
    }

    protected function port(int|string|null $port, int $default): string
    {
        return ';port='.($port === null || $port === '' ? $default : $port);
    }

    /**
     * @return list<string>
     */
    protected function toolConnection(): array
    {
        $port = $this->config['port'] ?? null;

        return [
            '--host='.(string) ($this->config['host'] ?? '127.0.0.1'),
            '--port='.($port === null || $port === '' ? 5432 : $port),
            '--username='.(string) ($this->config['username'] ?? 'postgres'),
        ];
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
