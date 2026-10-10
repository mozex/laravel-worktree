<?php

declare(strict_types=1);

return [
    /*
     * Directory where new worktrees are created, relative to the main
     * repository root. The default puts each worktree next to the repo so
     * Laravel Herd's parked directory serves it automatically. Use a nested
     * directory such as ".worktrees" to keep them inside the repo instead,
     * and add it to your .gitignore so the worktrees stay out of git status.
     */
    'path' => env('WORKTREE_PATH', '..'),

    /*
     * The branch a new worktree is based on when the target branch does not
     * exist yet. Existing branches are checked out as-is and ignore this.
     */
    'base_branch' => env('WORKTREE_BASE_BRANCH', 'main'),

    /*
     * How the worktree site is served through Laravel Herd.
     * "secure": HTTPS via "herd secure". "link": HTTP via "herd link".
     * "none": skip Herd (you serve the site some other way).
     * Each Herd command gets a minute: one stuck on the Herd app (a dialog, an
     * elevation prompt on Windows) is stopped with a warning instead of
     * holding setup or teardown forever. After linking, setup also checks that
     * Herd lists the new site, since "herd link" can report success without it.
     */
    'herd' => env('WORKTREE_HERD', 'secure'),

    /*
     * How the worktree hostname is built. Tokens: {repo} (the source repo
     * directory name) and {branch} (slashes become dashes). The TLD is
     * appended, so "{repo}-{branch}" with tld "test" gives "blog-feature-x.test".
     * A name long enough to push the host past 63 characters (the most a TLS
     * certificate takes) is cut and given a short hash, and the worktree's
     * directory and database names follow it.
     */
    'host' => [
        'template' => env('WORKTREE_HOST_TEMPLATE', '{repo}-{branch}'),

        'tld' => env('WORKTREE_TLD', 'test'),

        /*
         * When enabled, every occurrence of the source host ("{repo}.{tld}")
         * in the copied .env is rewritten to the worktree host. This keeps
         * APP_URL, extra domain keys, and mail addresses pointing at the
         * worktree instead of the main site.
         */
        'remap_source_host' => (bool) env('WORKTREE_REMAP_HOST', true),
    ],

    /*
     * Environment file handling for the new worktree.
     */
    'env' => [
        /*
         * The env file copied from the main repository into the worktree.
         */
        'source' => '.env',

        /*
         * Extra env files copied into the worktree when they exist, since
         * gitignored ones never arrive through git. They are copied unchanged
         * apart from the host rewrite and the "replace" rewrites below; files
         * git already placed are left alone, and a file that is not gitignored
         * is skipped with a warning. A stock Laravel .gitignore does not cover
         * .env.testing, so add it there if you keep one.
         */
        'copy' => ['.env.testing'],

        /*
         * The key holding the application URL, rewritten to the worktree host.
         */
        'app_url_key' => 'APP_URL',

        /*
         * Per-worktree value rewrites for env keys this package does not handle
         * on its own, such as a Redis prefix, a cache prefix, or a queue name.
         *
         * Each entry is KEY => template. The template is expanded with the
         * worktree tokens {repo}, {branch}, {name}, {slug}, {host}, and {tld},
         * plus {value} for the key's current value in the file, so a prefix
         * the file sets can be extended without restating it. A listed key the
         * env file does not define is added. These apply to the copied ".env"
         * and to every file in "copy".
         *
         * Example: keep each worktree's Redis keys and cache entries apart. A
         * stock Laravel .env leaves both keys out (config builds them from
         * APP_NAME), so {value} would be empty and the whole value is written.
         *
         *     'replace' => [
         *         'REDIS_PREFIX' => '{slug}-database-',
         *         'CACHE_PREFIX' => '{slug}-cache-',
         *     ],
         */
        'replace' => [],
    ],

    /*
     * Database provisioning.
     *
     * Each worktree gets its own database on every connection listed under
     * "connections" below. On MySQL, MariaDB, and PostgreSQL the server is
     * shared, so a named database is created per worktree and dropped on
     * teardown. On SQLite the database is a file inside the worktree and is
     * already isolated, so nothing is named or dropped: the file is created if
     * it is missing, and an absolute path pointing back at the source is
     * redirected. A SQLite file kept outside the repository (an absolute path
     * elsewhere, or a relative one climbing out with "../") is treated like a
     * server instead: the worktree gets a file of its own next to it, named by
     * the connection's "name" template, and teardown deletes it.
     *
     * The server is reached through the connection's host, port, username, and
     * password values. A connection configured through a single DB_URL or a
     * unix_socket is not parsed; give the connection explicit host values if
     * you use one of those.
     */
    'database' => [
        /*
         * Set to false to skip all database work: creation, migration,
         * PHPUnit rewriting, and the teardown drops.
         */
        'enabled' => (bool) env('WORKTREE_DATABASE', true),

        /*
         * How the application database is migrated after creation.
         * "fresh": migrate:fresh (a clean schema every time, even on a reused
         * database that still holds data). "migrate": migrate. "none": skip.
         *
         * Only the default connection is migrated. A second connection is
         * migrated by your own migrations pinning their connection, or a step.
         */
        'migrate' => env('WORKTREE_MIGRATE', 'fresh'),

        /*
         * Seed the application database after migrating. A cloned database is
         * not seeded by this setting, since it already holds data that seeding
         * again would duplicate; pass --seed to seed one anyway.
         */
        'seed' => (bool) env('WORKTREE_SEED', false),

        /*
         * Start each worktree with a copy of the main repository's data instead
         * of an empty database. Turn it on here, or per run with --clone (and
         * off again with --no-clone).
         *
         * MySQL and MariaDB copy on the server, table by table, with no client
         * tools needed. PostgreSQL copies the database as a template, and falls
         * back to pg_dump and pg_restore while other sessions (a queue worker, a
         * database GUI) hold the main database open. SQLite copies the file.
         *
         * With a clone, the "fresh" migrate mode runs a plain "migrate" instead,
         * so the copied data survives and only the branch's new migrations run
         * on top of it. Test databases are never cloned; they start empty.
         *
         * The tables in "structure_only" are created without their rows. Copying
         * the queue's pending jobs would have a worktree worker run them a second
         * time, and caches, sessions, Telescope, and Pulse data are only bulk.
         * The names accept "*" wildcards and match with or without the
         * connection's table prefix. If you renamed the queue tables, list the
         * new names. This list replaces the default when you set it, so keep the
         * entries you still want.
         */
        'clone' => [
            'enabled' => (bool) env('WORKTREE_CLONE', false),

            'structure_only' => [
                'jobs',
                'job_batches',
                'failed_jobs',
                'cache',
                'cache_locks',
                'sessions',
                'telescope_*',
                'pulse_*',
            ],
        ],

        /*
         * PHPUnit config files patched with the test database names. Every
         * listed file that exists is updated, and each one is read for the
         * connection its own suite runs on, so list every config your project
         * runs a suite with. A second suite with a second config (browser tests
         * beside the main suite) has to be here: an unpatched file still names
         * the main repository's test database, and that is the database its
         * suite would run against from inside the worktree.
         */
        'phpunit_files' => ['phpunit.xml', 'phpunit.xml.dist'],

        /*
         * The database connections to isolate per worktree. Each entry:
         *
         *   connection  The Laravel connection name from config/database.php.
         *               Use null for the application's default connection, so a
         *               stock single-connection app needs no changes here.
         *   env         The .env key holding this connection's database name,
         *               rewritten in the worktree's .env. The {slug} token is
         *               the worktree name lowercased with each run of
         *               non-alphanumerics turned into one underscore, so it
         *               stays valid on both MySQL and PostgreSQL.
         *   name        The worktree database name. Give each connection a
         *               distinct name so two never collide on one server.
         *   test        An optional test database. Omit it (or set it false) to
         *               skip one. "env" is the PHPUnit <env> key to rewrite and
         *               defaults to the connection's "env"; "name" is the test
         *               database name.
         *
         * For the default connection (null), the test database is created on
         * whichever connection your PHPUnit file runs the suite on, so a suite
         * pinned to MySQL gets its test database there with no extra config. A
         * named connection keeps its own name in tests too.
         */
        'connections' => [
            [
                'connection' => null,
                'env' => 'DB_DATABASE',
                'name' => '{slug}',
                'test' => [
                    'env' => 'DB_DATABASE',
                    'name' => '{slug}_testing',
                ],
            ],

            // A second connection, named as it appears in config/database.php:
            // [
            //     'connection' => 'analytics',
            //     'env' => 'ANALYTICS_DB_DATABASE',
            //     'name' => '{slug}_analytics',
            //     'test' => [
            //         'env' => 'ANALYTICS_DB_DATABASE',
            //         'name' => '{slug}_analytics_testing',
            //     ],
            // ],
        ],
    ],

    /*
     * Dependency directories provisioned before the steps below.
     *
     * Each entry installs its dependencies with "install", unless "copy" is on
     * and the worktree's lock file matches the main repository's, in which case
     * the directory is copied from the main repository and the install is
     * skipped. Copying is much faster than a fresh install (a warm robocopy of
     * vendor beat "composer install" by roughly 5x in testing), and the lock
     * check keeps it correct: a branch that changes its dependencies gets a real
     * install instead of a stale copy. An entry whose "manifest" is missing from
     * the worktree is skipped entirely, so an app with no package.json never runs
     * npm. Passing --no-install skips this whole block.
     *
     * The entries are yours: delete one (say "node_modules") and it is gone,
     * add your own and it is provisioned the same way. An entry you keep still
     * picks up options a later version of the package adds to it.
     *
     * "npm ci" is used instead of "npm install" on purpose. Laravel's package.json
     * has no "name", so "npm install" writes the worktree's directory name into the
     * tracked package-lock.json and leaves it looking modified. "npm ci" installs
     * from the lockfile without ever rewriting it. It needs a committed lockfile,
     * so switch to "npm install" if your project does not have one.
     */
    'dependencies' => [
        'vendor' => [
            'copy' => (bool) env('WORKTREE_COPY_VENDOR', false),
            'path' => 'vendor',
            'manifest' => 'composer.json',
            'lock' => 'composer.lock',
            'install' => 'composer install',
        ],

        'node_modules' => [
            'copy' => (bool) env('WORKTREE_COPY_NODE_MODULES', false),
            'path' => 'node_modules',
            'manifest' => 'package.json',
            'lock' => 'package-lock.json',
            'install' => 'npm ci',
        ],
    ],

    /*
     * Extra shell commands run inside the worktree after its dependencies are
     * provisioned. Passing --no-install to worktree:setup skips these too, since
     * they usually need the dependencies. Add or remove steps to match your stack.
     */
    'steps' => [
        'npm run build --if-present',
        'php artisan storage:link',
    ],
];
