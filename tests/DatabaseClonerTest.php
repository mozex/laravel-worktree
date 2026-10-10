<?php

declare(strict_types=1);

use Mozex\Worktree\Support\DatabaseCloner;
use Mozex\Worktree\Support\DatabaseManager;

/**
 * A source database holding everything a clone has to carry: a foreign key, a
 * generated column, a view built on another view, a trigger, a stored function,
 * and two structure-only tables with rows in them. The trigger is created after
 * the rows, so a copy that fired it would show uppercase names.
 */
function cloneFixture(string $driver, string $database): void
{
    serverPdo($driver)->exec((new DatabaseManager(serverConnections()[$driver]))->createStatement($database));

    $pdo = serverPdo($driver, $database);

    if ($driver === 'pgsql') {
        $pdo->exec('CREATE TABLE users (id bigserial PRIMARY KEY, name text NOT NULL, shout text GENERATED ALWAYS AS (name || \'!\') STORED)');
        $pdo->exec('CREATE TABLE posts (id bigserial PRIMARY KEY, user_id bigint NOT NULL REFERENCES users (id))');
        $pdo->exec('CREATE TABLE jobs (id bigserial PRIMARY KEY, payload text)');
        $pdo->exec('CREATE TABLE telescope_entries (id bigserial PRIMARY KEY, content text)');
        $pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('bob')");
        $pdo->exec('CREATE FUNCTION shout(value text) RETURNS text LANGUAGE sql IMMUTABLE AS $$ SELECT value || \'!\' $$');
        $pdo->exec('CREATE FUNCTION upper_name() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.name := upper(NEW.name); RETURN NEW; END $$');
        $pdo->exec('CREATE TRIGGER users_upper BEFORE INSERT ON users FOR EACH ROW EXECUTE FUNCTION upper_name()');
    } else {
        // A row whose id is 0 must keep it, which only NO_AUTO_VALUE_ON_ZERO allows.
        $pdo->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $pdo->exec("CREATE TABLE users (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, name varchar(50) NOT NULL, shout varchar(60) AS (CONCAT(name, '!')) STORED, created_at timestamp DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec('CREATE TABLE posts (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, user_id bigint unsigned NOT NULL, CONSTRAINT posts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id))');
        $pdo->exec('CREATE TABLE jobs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, payload text)');
        $pdo->exec('CREATE TABLE telescope_entries (id bigint unsigned AUTO_INCREMENT PRIMARY KEY, content text)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (0, 'zero')");
        $pdo->exec("INSERT INTO users (name) VALUES ('ada'), ('bob')");
        $pdo->exec("CREATE FUNCTION shout(value varchar(50)) RETURNS varchar(60) DETERMINISTIC RETURN CONCAT(value, '!')");
        $pdo->exec('CREATE TRIGGER users_upper BEFORE INSERT ON users FOR EACH ROW SET NEW.name = UPPER(NEW.name)');
    }

    $ada = (int) $pdo->query("SELECT id FROM users WHERE name = 'ada'")->fetchColumn();

    $pdo->exec("INSERT INTO posts (user_id) VALUES ({$ada})");
    $pdo->exec("INSERT INTO jobs (payload) VALUES ('send-invoice')");
    $pdo->exec("INSERT INTO telescope_entries (content) VALUES ('{}')");

    // Sorted by name, the view on a view comes first, so it fails on the first pass.
    $pdo->exec('CREATE VIEW user_names AS SELECT id, name FROM users');
    $pdo->exec("CREATE VIEW first_names AS SELECT name FROM user_names WHERE name = 'ada'");
}

function countRows(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}

/**
 * @return list<string>
 */
function structureOnly(): array
{
    return ['jobs', 'telescope_*'];
}

it('strips the definer from a view, trigger, or routine header', function (string $statement, string $expected) {
    expect(DatabaseCloner::withoutDefiner($statement))->toBe($expected);
})->with([
    'view' => [
        'CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1 AS `1`',
        'CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1 AS `1`',
    ],
    'trigger' => [
        'CREATE DEFINER=`app`@`%` TRIGGER `t` BEFORE INSERT ON `users` FOR EACH ROW SET NEW.name = NEW.name',
        'CREATE TRIGGER `t` BEFORE INSERT ON `users` FOR EACH ROW SET NEW.name = NEW.name',
    ],
    'quoted user with a backtick' => [
        'CREATE DEFINER=`we``ird`@`localhost` PROCEDURE `p`() BEGIN END',
        'CREATE PROCEDURE `p`() BEGIN END',
    ],
    'only the header' => [
        "CREATE DEFINER=`root`@`localhost` FUNCTION `f`() RETURNS text RETURN 'DEFINER=`x`@`y`'",
        "CREATE FUNCTION `f`() RETURNS text RETURN 'DEFINER=`x`@`y`'",
    ],
    'no definer' => [
        'CREATE VIEW `v` AS select 1 AS `1`',
        'CREATE VIEW `v` AS select 1 AS `1`',
    ],
]);

it('matches structure-only tables by name and wildcard', function () {
    $cloner = new DatabaseCloner(new DatabaseManager(['driver' => 'mysql']), ['jobs', 'telescope_*']);

    expect($cloner->isStructureOnly('jobs'))->toBeTrue()
        ->and($cloner->isStructureOnly('telescope_entries_tags'))->toBeTrue()
        ->and($cloner->isStructureOnly('job_batches'))->toBeFalse()
        ->and($cloner->isStructureOnly('users'))->toBeFalse()
        ->and((new DatabaseCloner(new DatabaseManager(['driver' => 'mysql'])))->isStructureOnly('jobs'))->toBeFalse();
});

it('matches structure-only tables behind the connection table prefix', function () {
    $cloner = new DatabaseCloner(new DatabaseManager(['driver' => 'mysql', 'prefix' => 'app_']), ['jobs', 'telescope_*']);

    expect($cloner->isStructureOnly('app_jobs'))->toBeTrue()
        ->and($cloner->isStructureOnly('app_telescope_entries'))->toBeTrue()
        ->and($cloner->isStructureOnly('jobs'))->toBeTrue()
        ->and($cloner->isStructureOnly('app_users'))->toBeFalse();
});

it('clones a server database with its schema, data, and routines', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    $source = 'wt_clone_src_'.bin2hex(random_bytes(3));
    $target = 'wt_clone_dst_'.bin2hex(random_bytes(3));
    $manager = new DatabaseManager(serverConnections()[$driver]);

    try {
        cloneFixture($driver, $source);

        expect((new DatabaseCloner($manager, structureOnly()))->cloneServer($source, $target))->toBeTrue();

        $copy = serverPdo($driver, $target);

        // The rows arrived as they were: the trigger did not fire on them.
        expect($copy->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe($driver === 'pgsql' ? ['ada', 'bob'] : ['zero', 'ada', 'bob'])
            ->and($copy->query("SELECT shout FROM users WHERE name = 'ada'")->fetchColumn())->toBe('ada!')
            ->and(countRows($copy, 'posts'))->toBe(1)
            ->and($copy->query('SELECT name FROM first_names')->fetchColumn())->toBe('ada')
            ->and($copy->query("SELECT shout('hey')")->fetchColumn())->toBe('hey!')
            // Structure only: the tables exist, their rows stayed behind.
            ->and(countRows($copy, 'jobs'))->toBe(0)
            ->and(countRows($copy, 'telescope_entries'))->toBe(0)
            ->and(countRows(serverPdo($driver, $source), 'jobs'))->toBe(1);

        // The views read the copy, not the source they were read from.
        serverPdo($driver, $source)->exec("UPDATE users SET name = 'changed' WHERE name = 'ada'");

        expect($copy->query('SELECT name FROM first_names')->fetchColumn())->toBe('ada');

        // The trigger came along, and the id sequence continues where the source left off.
        $copy->exec("INSERT INTO users (name) VALUES ('cy')");

        expect($copy->query("SELECT id FROM users WHERE name = 'CY'")->fetchColumn())->toEqual(3);

        // The foreign key came along too.
        $rejected = false;

        try {
            $copy->exec('INSERT INTO posts (user_id) VALUES (999)');
        } catch (PDOException) {
            $rejected = true;
        }

        expect($rejected)->toBeTrue();
    } finally {
        unset($copy);
        dropDatabase($driver, $source);
        dropDatabase($driver, $target);
    }
})->with(['mysql', 'pgsql']);

it('copies mysql rows without waiting on a row the main app has locked', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    $source = 'wt_clone_src_'.bin2hex(random_bytes(3));
    $target = 'wt_clone_dst_'.bin2hex(random_bytes(3));

    try {
        cloneFixture('mysql', $source);

        // The main app mid-transaction. A locking read of this row would wait
        // out innodb_lock_wait_timeout and fail the clone.
        $main = serverPdo('mysql', $source);
        $main->beginTransaction();
        $main->exec("UPDATE users SET name = 'busy' WHERE name = 'bob'");

        (new DatabaseCloner(new DatabaseManager(serverConnections()['mysql'])))->cloneServer($source, $target);

        // The copy has the last committed value, not the open transaction's.
        expect(serverPdo('mysql', $target)->query("SELECT COUNT(*) FROM users WHERE name = 'bob'")->fetchColumn())->toEqual(1);

        $main->rollBack();
    } finally {
        unset($main);
        dropDatabase('mysql', $source);
        dropDatabase('mysql', $target);
    }
});

it('reports a postgres source that other sessions hold open', function () {
    if (! serverAvailable('pgsql')) {
        $this->markTestSkipped('needs a pgsql server on 127.0.0.1');
    }

    $source = 'wt_clone_src_'.bin2hex(random_bytes(3));
    $target = 'wt_clone_dst_'.bin2hex(random_bytes(3));

    try {
        cloneFixture('pgsql', $source);
        $held = serverPdo('pgsql', $source);

        $cloner = new DatabaseCloner(new DatabaseManager(serverConnections()['pgsql']), structureOnly());

        expect($cloner->cloneServer($source, $target))->toBeFalse()
            ->and(databaseExists('pgsql', $target))->toBeFalse();
    } finally {
        unset($held);
        dropDatabase('pgsql', $source);
        dropDatabase('pgsql', $target);
    }
});

it('clones a sqlite file, including rows still in its write-ahead log', function () {
    $dir = sys_get_temp_dir().'/wt-clone-'.bin2hex(random_bytes(4));
    mkdir($dir);

    try {
        // Held open in WAL mode, the rows sit in the -wal file rather than the
        // database file, which is what a plain file copy would miss.
        $source = new PDO('sqlite:'.$dir.'/source.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA journal_mode = WAL');
        $source->exec('CREATE TABLE users (id integer PRIMARY KEY, name text)');
        $source->exec('CREATE TABLE jobs (id integer PRIMARY KEY, payload text)');
        $source->exec("INSERT INTO users (name) VALUES ('ada'), ('bob')");
        $source->exec("INSERT INTO jobs (payload) VALUES ('send-invoice')");

        expect(is_file($dir.'/source.sqlite-wal'))->toBeTrue();

        (new DatabaseCloner(new DatabaseManager(['driver' => 'sqlite']), structureOnly()))
            ->cloneFile($dir.'/source.sqlite', $dir.'/copy.sqlite');

        $copy = new PDO('sqlite:'.$dir.'/copy.sqlite');

        expect(countRows($copy, 'users'))->toBe(2)
            ->and(countRows($copy, 'jobs'))->toBe(0)
            ->and(countRows($source, 'jobs'))->toBe(1);
    } finally {
        unset($source, $copy);

        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
});

it('empties a structure-only table that a kept table points at', function (string $driver) {
    if (! serverAvailable($driver)) {
        $this->markTestSkipped("needs a {$driver} server on 127.0.0.1");
    }

    // Postgres refuses to TRUNCATE a table another table has a foreign key
    // into, so the jobs go row by row with foreign keys unenforced.
    $source = 'wt_clone_src_'.bin2hex(random_bytes(3));
    $target = 'wt_clone_dst_'.bin2hex(random_bytes(3));
    $manager = new DatabaseManager(serverConnections()[$driver]);

    try {
        $manager->create($source);
        $pdo = serverPdo($driver, $source);
        $pdo->exec('CREATE TABLE jobs (id integer PRIMARY KEY, payload text)');
        $pdo->exec('CREATE TABLE audits (id integer PRIMARY KEY, job_id integer REFERENCES jobs (id))');
        $pdo->exec("INSERT INTO jobs (id, payload) VALUES (1, 'send-invoice')");
        $pdo->exec('INSERT INTO audits (id, job_id) VALUES (1, 1)');
        unset($pdo);

        expect((new DatabaseCloner($manager, ['jobs']))->cloneServer($source, $target))->toBeTrue();

        $copy = serverPdo($driver, $target);

        expect(countRows($copy, 'jobs'))->toBe(0)
            ->and(countRows($copy, 'audits'))->toBe(1);
    } finally {
        unset($copy);
        dropDatabase($driver, $source);
        dropDatabase($driver, $target);
    }
})->with(['mysql', 'pgsql']);

it('falls back to the locking isolation level when the binary log refuses read committed', function () {
    if (! serverAvailable('mysql')) {
        $this->markTestSkipped('needs a mysql server on 127.0.0.1');
    }

    // A server logging in STATEMENT format answers READ COMMITTED with error
    // 1665. Local servers seldom log that way, so the refusal is staged.
    $cloner = new class(new DatabaseManager(serverConnections()['mysql'])) extends DatabaseCloner
    {
        /** @var list<string> */
        public array $levels = [];

        protected function insertRows(PDO $to, array $statements, string $isolation): void
        {
            $this->levels[] = $isolation;

            if ($isolation === 'READ COMMITTED') {
                $exception = new PDOException('Cannot execute statement: impossible to write to binary log since BINLOG_FORMAT = STATEMENT');
                $exception->errorInfo = ['HY000', 1665, 'Cannot execute statement'];

                throw $exception;
            }

            parent::insertRows($to, $statements, $isolation);
        }
    };

    $source = 'wt_clone_src_'.bin2hex(random_bytes(3));
    $target = 'wt_clone_dst_'.bin2hex(random_bytes(3));

    try {
        cloneFixture('mysql', $source);

        $cloner->cloneServer($source, $target);

        expect($cloner->levels)->toBe(['READ COMMITTED', 'REPEATABLE READ'])
            ->and(countRows(serverPdo('mysql', $target), 'users'))->toBe(3);
    } finally {
        dropDatabase('mysql', $source);
        dropDatabase('mysql', $target);
    }
});
