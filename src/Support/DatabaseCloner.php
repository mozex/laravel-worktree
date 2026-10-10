<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Illuminate\Support\Str;
use Mozex\Worktree\Exceptions\WorktreeException;
use PDO;
use PDOException;
use Throwable;

/**
 * Copies a database, schema and data, into a new one, so a worktree can start
 * with the main checkout's data instead of an empty schema.
 *
 * Each driver gets the cheapest copy it allows. MySQL and MariaDB copy server
 * side, table by table, between two databases on the same server: no rows pass
 * through PHP and no client binaries are needed. Postgres copies the whole
 * database as a template. SQLite writes a consistent snapshot of the file with
 * VACUUM INTO, which also takes in anything still sitting in a WAL file.
 *
 * Tables matching a "structure only" pattern keep their schema but lose their
 * rows. That is how a queue's pending jobs stay behind: a worktree worker would
 * otherwise run them a second time, and they can send real email.
 */
class DatabaseCloner
{
    /**
     * @param  list<string>  $structureOnly  Table name patterns ("*" wildcards) copied without rows.
     */
    public function __construct(
        protected DatabaseManager $databases,
        protected array $structureOnly = [],
    ) {}

    /**
     * Clones a server database into a target that must not exist yet. False
     * means Postgres refused the template copy because other sessions are
     * connected to the source; the caller copies through pg_dump then.
     */
    public function cloneServer(string $source, string $target): bool
    {
        if ($this->databases->driver() === 'pgsql') {
            if (! $this->databases->createFromTemplate($source, $target)) {
                return false;
            }

            $this->emptyStructureOnlyTables($target);

            return true;
        }

        $this->copyMysql($source, $target);

        return true;
    }

    /**
     * Writes a snapshot of an existing SQLite file to a path that is missing
     * or empty. VACUUM INTO only reads the source, the main checkout's own
     * database, and writes nothing back to it.
     */
    public function cloneFile(string $source, string $target): void
    {
        $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('VACUUM INTO '.$pdo->quote($target));

        $copy = new PDO('sqlite:'.$target, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        /** @var list<string> $tables */
        $tables = $copy->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if ($this->isStructureOnly($table)) {
                $copy->exec('DELETE FROM "'.str_replace('"', '""', $table).'"');
            }
        }
    }

    /**
     * A Postgres copy (template or restore) takes every row, so the
     * structure-only tables are emptied afterwards, in one statement so that
     * tables referencing one another (Telescope's entries and tags) can go
     * together.
     *
     * TRUNCATE refuses a table that a kept table has a foreign key into
     * (SQLSTATE 0A000), since the kept rows would point at nothing. The rows
     * are deleted with foreign keys unenforced instead, which leaves the copy
     * the way the MySQL copy leaves it. That needs a superuser (or, from
     * Postgres 15, a granted SET on session_replication_role); without one the
     * error names the way out.
     */
    public function emptyStructureOnlyTables(string $database): void
    {
        $pdo = $this->databases->connect($database);

        /** @var list<array{0: string, 1: string}> $tables */
        $tables = $pdo->query("SELECT schemaname, tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog', 'information_schema') ORDER BY schemaname, tablename")->fetchAll(PDO::FETCH_NUM);

        $names = [];

        foreach ($tables as [$schema, $table]) {
            if ($this->isStructureOnly($table)) {
                $names[] = $this->databases->quoteIdentifier($schema).'.'.$this->databases->quoteIdentifier($table);
            }
        }

        if ($names === []) {
            return;
        }

        try {
            $pdo->exec('TRUNCATE TABLE '.implode(', ', $names));
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '0A000') {
                throw $exception;
            }

            $this->deleteUnenforced($pdo, $database, $names);
        }
    }

    /**
     * @param  list<string>  $names
     */
    protected function deleteUnenforced(PDO $pdo, string $database, array $names): void
    {
        try {
            $pdo->exec('SET session_replication_role = replica');
        } catch (PDOException $exception) {
            throw WorktreeException::structureOnlyReferenced($database, $exception->getMessage());
        }

        foreach ($names as $name) {
            $pdo->exec('DELETE FROM '.$name);
        }

        $pdo->exec('SET session_replication_role = DEFAULT');
    }

    /**
     * A table matches with or without the connection's table prefix, so
     * "jobs" still catches the queue table of a connection prefixed "app_".
     */
    public function isStructureOnly(string $table): bool
    {
        if ($this->structureOnly === []) {
            return false;
        }

        $prefix = $this->databases->prefix();

        return Str::is($this->structureOnly, $table)
            || ($prefix !== '' && str_starts_with($table, $prefix) && Str::is($this->structureOnly, substr($table, strlen($prefix))));
    }

    /**
     * A view, trigger, or routine is created with its DEFINER clause dropped,
     * so the current user defines it. Recreating one under another account
     * needs a privilege a local user may not hold, and only the clause in the
     * header is removed, never text further into the body.
     */
    public static function withoutDefiner(string $statement): string
    {
        $user = '(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'|[^\s@]+)';

        return (string) preg_replace('/\s+DEFINER\s*=\s*'.$user.'(?:@'.$user.')?/i', '', $statement, 1);
    }

    /**
     * Schema first, then rows, then everything that reads or fires on rows.
     * Foreign key checks are off, so tables can be created in any order, and
     * NO_AUTO_VALUE_ON_ZERO keeps a row whose id is 0 from being handed a new
     * one (both are what mysqldump does too). The rows are copied in one
     * transaction, separate from the DDL, which would commit it implicitly;
     * at READ COMMITTED each table is read as of its own statement, which is
     * close enough for a development copy and never blocks the main app.
     * Triggers come last so they never fire on the copied rows.
     */
    protected function copyMysql(string $source, string $target): void
    {
        $server = $this->databases->connect();
        $server->exec($this->mysqlCreateStatement($server, $source, $target));

        $from = $this->databases->connect($source);
        $to = $this->databases->connect($target);

        $to->exec('SET FOREIGN_KEY_CHECKS = 0');
        $to->exec('SET UNIQUE_CHECKS = 0');
        $to->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");

        $tables = $this->names($from, 'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ? ORDER BY table_name', [$source, 'BASE TABLE']);

        foreach ($tables as $table) {
            $to->exec((string) $this->showCreate($from, 'TABLE', $table)['Create Table']);
        }

        $this->copyMysqlRows($from, $to, $source, $target, $tables);

        foreach ($this->rows($from, 'SELECT routine_type, routine_name FROM information_schema.routines WHERE routine_schema = ? ORDER BY routine_name', [$source]) as [$type, $name]) {
            $definition = $this->showCreate($from, $type, $name);
            $this->executeWithMode($to, (string) $definition['Create '.ucfirst(strtolower($type))], (string) $definition['sql_mode']);
        }

        $views = $this->names($from, 'SELECT table_name FROM information_schema.views WHERE table_schema = ? ORDER BY table_name', [$source]);

        $this->createViews($to, array_map(
            fn (string $view): string => (string) $this->showCreate($from, 'VIEW', $view)['Create View'],
            $views,
        ));

        foreach ($this->names($from, 'SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = ? ORDER BY trigger_name', [$source]) as $trigger) {
            $definition = $this->showCreate($from, 'TRIGGER', $trigger);
            $this->executeWithMode($to, (string) $definition['SQL Original Statement'], (string) $definition['sql_mode']);
        }
    }

    /**
     * The target takes the source's default charset and collation, so a table
     * created later in the worktree (by a branch migration) matches the rest.
     */
    protected function mysqlCreateStatement(PDO $server, string $source, string $target): string
    {
        $statement = $server->prepare('SELECT default_character_set_name, default_collation_name FROM information_schema.schemata WHERE schema_name = ?');
        $statement->execute([$source]);

        /** @var array{0: string|null, 1: string|null}|false $row */
        $row = $statement->fetch(PDO::FETCH_NUM);

        $create = 'CREATE DATABASE '.$this->databases->quoteIdentifier($target);

        if ($row === false) {
            return $create;
        }

        [$charset, $collation] = $row;

        foreach (['CHARACTER SET' => $charset, 'COLLATE' => $collation] as $clause => $value) {
            if (is_string($value) && preg_match('/^\w+$/', $value) === 1) {
                $create .= ' '.$clause.' '.$value;
            }
        }

        return $create;
    }

    /**
     * Generated columns are left out of the copy because the server computes
     * them and refuses an explicit value. They are told apart by their
     * generation expression: MySQL also flags a plain timestamp defaulting to
     * CURRENT_TIMESTAMP as DEFAULT_GENERATED, and that column must be copied.
     *
     * @param  list<string>  $tables
     */
    protected function copyMysqlRows(PDO $from, PDO $to, string $source, string $target, array $tables): void
    {
        $columns = [];

        foreach ($this->rows($from, "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? AND COALESCE(generation_expression, '') = '' ORDER BY table_name, ordinal_position", [$source]) as [$table, $column]) {
            $columns[$table][] = $this->databases->quoteIdentifier($column);
        }

        $statements = [];

        foreach ($tables as $table) {
            if ($this->isStructureOnly($table) || ! isset($columns[$table])) {
                continue;
            }

            $list = implode(', ', $columns[$table]);
            $quoted = $this->databases->quoteIdentifier($table);

            $statements[] = 'INSERT INTO '.$this->databases->quoteIdentifier($target).'.'.$quoted.' ('.$list.') '
                .'SELECT '.$list.' FROM '.$this->databases->quoteIdentifier($source).'.'.$quoted;
        }

        // A development copy has no business in the binary log, and writing it
        // there only slows the copy down. Turning it off takes a privilege a
        // local root account has and a restricted one may not, so it is a try.
        try {
            $to->exec('SET SESSION sql_log_bin = 0');
        } catch (PDOException) {
            // Logged after all; the isolation fallback below covers the one case that breaks.
        }

        // Under the default REPEATABLE READ, INSERT ... SELECT takes a shared
        // lock on every row it reads, and one transaction would hold those on
        // the main database until the last table was copied, stalling the main
        // app's writes. READ COMMITTED reads the source without locking it.
        // A server logging in STATEMENT format refuses that combination
        // (error 1665), and there the copy falls back to the locking default.
        try {
            $this->insertRows($to, $statements, 'READ COMMITTED');
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1665) {
                throw $exception;
            }

            $this->insertRows($to, $statements, 'REPEATABLE READ');
        }
    }

    /**
     * Runs every row copy in one transaction at the given isolation level.
     *
     * @param  list<string>  $statements
     */
    protected function insertRows(PDO $to, array $statements, string $isolation): void
    {
        $to->exec('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
        $to->beginTransaction();

        try {
            foreach ($statements as $statement) {
                $to->exec($statement);
            }

            $to->commit();
        } catch (Throwable $exception) {
            $to->rollBack();

            throw $exception;
        }
    }

    /**
     * A view built on another view fails until that one exists, so the views
     * are created in passes until a pass makes no progress, which leaves only
     * a real error to report.
     *
     * @param  list<string>  $views
     */
    protected function createViews(PDO $to, array $views): void
    {
        $pending = array_map(self::withoutDefiner(...), $views);

        while ($pending !== []) {
            $failed = [];
            $error = null;

            foreach ($pending as $view) {
                try {
                    $to->exec($view);
                } catch (Throwable $exception) {
                    $failed[] = $view;
                    $error = $exception;
                }
            }

            if ($error !== null && count($failed) === count($pending)) {
                throw $error;
            }

            $pending = $failed;
        }
    }

    /**
     * Routines and triggers remember the sql_mode they were created under, so
     * each is recreated under its own and the copy's mode is restored after.
     */
    protected function executeWithMode(PDO $to, string $statement, string $mode): void
    {
        $to->exec('SET SESSION sql_mode = '.$to->quote($mode));
        $to->exec(self::withoutDefiner($statement));
        $to->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
    }

    /**
     * SHOW CREATE runs on the source's own connection, so references to the
     * source's tables come back unqualified and resolve inside the target when
     * the statement is replayed there.
     *
     * @return array<string, string|null>
     */
    protected function showCreate(PDO $from, string $type, string $name): array
    {
        /** @var array<string, string|null> $row */
        $row = $from->query('SHOW CREATE '.$type.' '.$this->databases->quoteIdentifier($name))->fetch(PDO::FETCH_ASSOC);

        return $row;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<list<string>>
     */
    protected function rows(PDO $pdo, string $query, array $bindings): array
    {
        $statement = $pdo->prepare($query);
        $statement->execute($bindings);

        /** @var list<list<string>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_NUM);

        return $rows;
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string>
     */
    protected function names(PDO $pdo, string $query, array $bindings): array
    {
        return array_map(fn (array $row): string => $row[0], $this->rows($pdo, $query, $bindings));
    }
}
