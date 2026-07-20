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
     */
    'herd' => env('WORKTREE_HERD', 'secure'),

    /*
     * How the worktree hostname is built. Tokens: {repo} (the source repo
     * directory name) and {branch} (slashes become dashes). The TLD is
     * appended, so "{repo}-{branch}" with tld "test" gives "blog-feature-x.test".
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
         * apart from the host rewrite; files git already placed are left alone.
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
         * plus {value} for the key's current value, so a prefix can be appended
         * without restating it. A listed key the env file does not define is
         * added. These apply to the copied ".env" and to every file in "copy".
         *
         * Example: keep each worktree's Redis keys and cache entries apart.
         *
         *     'replace' => [
         *         'REDIS_PREFIX' => '{value}{slug}_',
         *         'CACHE_PREFIX' => '{slug}_cache_',
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
     * it is missing, and only an absolute path pointing back at the source is
     * redirected.
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
         * Seed the application database after migrating.
         */
        'seed' => (bool) env('WORKTREE_SEED', false),

        /*
         * PHPUnit config files patched with the test database names. The first
         * file that exists is updated, and it is also where each connection's
         * test connection is read from.
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
     * Extra shell commands run inside the worktree after it is provisioned.
     * "composer install" runs before these on its own, so it does not belong
     * here. Passing --no-install to worktree:setup skips both it and these.
     * Add or remove steps to match your stack.
     *
     * "npm ci" is used instead of "npm install" on purpose. Laravel's package.json
     * has no "name", so "npm install" writes the worktree's directory name into the
     * tracked package-lock.json and leaves it looking modified. "npm ci" installs
     * from the lockfile without ever rewriting it. It needs a committed lockfile,
     * so switch back to "npm install" if your project does not have one.
     */
    'steps' => [
        'npm ci',
        'npm run build --if-present',
        'php artisan storage:link',
    ],
];
