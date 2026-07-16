<?php

declare(strict_types=1);

use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;

return [
    /*
     * Directory where new worktrees are created, relative to the main
     * repository root. The default puts each worktree next to the repo so
     * Laravel Herd's parked directory serves it automatically. Use a nested
     * directory such as ".worktrees" to keep them inside the repo instead.
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
    'herd' => env('WORKTREE_HERD', HerdMode::Secure->value),

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
         * The key holding the application URL, rewritten to the worktree host.
         */
        'app_url_key' => 'APP_URL',
    ],

    /*
     * Database provisioning.
     *
     * On MySQL, MariaDB, and PostgreSQL the server is shared between worktrees,
     * so each one is given a database of its own here. On SQLite the database is
     * a file inside the worktree and is already isolated, so these naming options
     * do not apply: the file is simply created if it is missing.
     */
    'database' => [
        /*
         * Set to false to skip all database work: creation, migration,
         * PHPUnit rewriting, and the teardown drops.
         */
        'enabled' => (bool) env('WORKTREE_DATABASE', true),

        /*
         * The application database name, used for server databases only. The
         * {slug} token is the worktree name lowercased with every non-alphanumeric
         * run turned into a single underscore, so it stays valid on both MySQL and
         * PostgreSQL.
         */
        'name' => env('WORKTREE_DATABASE_NAME', '{slug}'),

        /*
         * The separate database used when running the test suite.
         *
         * This only applies when the suite runs against a database server. The
         * connection is read from the PHPUnit file below, so a stock Laravel app
         * (whose suite runs on in-memory SQLite) is left alone.
         */
        'test' => [
            'enabled' => (bool) env('WORKTREE_TEST_DATABASE', true),

            /*
             * Appended to the application database name to build the test
             * database name.
             */
            'suffix' => env('WORKTREE_TEST_SUFFIX', '_testing'),

            /*
             * PHPUnit config files patched with the test database name. The
             * first file that exists is updated, and it is also where the test
             * connection is read from.
             */
            'phpunit_files' => ['phpunit.xml', 'phpunit.xml.dist'],

            /*
             * The env entry inside the PHPUnit file that holds the test
             * database name.
             */
            'phpunit_key' => 'DB_DATABASE',
        ],

        /*
         * How the application database is migrated after creation.
         * "fresh": migrate:fresh (a clean schema every time, even on a reused
         * database that still holds data). "migrate": migrate. "none": skip.
         */
        'migrate' => env('WORKTREE_MIGRATE', MigrateMode::Fresh->value),

        /*
         * Seed the application database after migrating.
         */
        'seed' => (bool) env('WORKTREE_SEED', false),
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
