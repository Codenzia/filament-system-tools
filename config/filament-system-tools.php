<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | Used in database export filenames and SQL comments.
    | Defaults to your APP_NAME if not set.
    |
    */
    'app_name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Release Information
    |--------------------------------------------------------------------------
    |
    | Displayed on the About page. Set these in your .env or override in config.
    |
    */
    'release' => [
        'version' => env('APP_VERSION', '1.0.0'),
        'name' => env('APP_RELEASE_NAME', ''),
        'date' => env('APP_RELEASE_DATE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation Group
    |--------------------------------------------------------------------------
    |
    | The navigation group under which all system tool pages appear.
    |
    */
    'navigation_group' => 'System',

    /*
    |--------------------------------------------------------------------------
    | Navigation Sort
    |--------------------------------------------------------------------------
    |
    | Per-page sidebar order, so a host app can interleave these pages with its
    | own. Override per app via config or the plugin's ->navigationSort([...]).
    |
    */
    'navigation_sort' => [
        'health' => 99,
        'database_backup' => 101,
        'smart_migration' => 102,
        'queue_monitor' => 103,
        'logs' => 103,
        'about' => 104,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Path
    |--------------------------------------------------------------------------
    |
    | Directory where database backups are stored.
    |
    */
    'backup_path' => storage_path('app/backups'),

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    |
    | Tables excluded from the database table listing and exports.
    |
    */
    'excluded_tables' => [
        'migrations',
        'personal_access_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sqlite_sequence',
        'password_reset_tokens',
        'pulse_aggregates',
        'pulse_entries',
        'pulse_values',
        'telescope_entries',
        'telescope_entries_tags',
        'telescope_monitoring',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Dump Binaries
    |--------------------------------------------------------------------------
    |
    | Paths to the CLI binaries used by the DatabaseSqlTool service for
    | export/import. Override via env when the binaries are not on PATH —
    | common on shared hosting and inside PHP-FPM with restricted PATH.
    |
    */
    'dump' => [
        'sqlite' => [
            'sqlite3' => env('DB_DUMP_SQLITE3', 'sqlite3'),
        ],
        'mysql' => [
            'mysqldump' => env('DB_DUMP_MYSQLDUMP', 'mysqldump'),
            'mysql' => env('DB_DUMP_MYSQL', 'mysql'),
        ],
        'pgsql' => [
            'pg_dump' => env('DB_DUMP_PG_DUMP', 'pg_dump'),
            'psql' => env('DB_DUMP_PSQL', 'psql'),
        ],
        'compression' => [
            'gzip' => env('DB_DUMP_GZIP', 'gzip'),
            'gunzip' => env('DB_DUMP_GUNZIP', 'gunzip'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Health Page
    |--------------------------------------------------------------------------
    |
    | Configuration for the SystemHealth page. The "Run Migrations" button is
    | gated behind a config flag because applying migrations from a UI is a
    | production-affecting operation.
    |
    */
    'health' => [
        'allow_run_migrations' => env('FILAMENT_SYSTEM_TOOLS_ALLOW_MIGRATE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Smart Data Migration
    |--------------------------------------------------------------------------
    |
    | The Smart Data Migration page exports/imports JSON snapshots that include
    | schema metadata. If your application is multi-tenant and you only want
    | the current tenant's data exported (and the target tenant's id stamped on
    | imported rows), set `scope_resolver` to a callable returning a
    | `['column' => 'team_id', 'value' => 1]` array — or leave it null to
    | operate on the entire database.
    |
    | Example:
    |     'scope_resolver' => fn () => Filament::getTenant()
    |         ? ['column' => 'team_id', 'value' => Filament::getTenant()->id]
    |         : null,
    |
    | When a resolver is configured the scope is enforced: it cannot be turned
    | off from the UI, tables without the scope column are excluded unless they
    | are listed in `global_tables`, and a resolver that cannot produce a scope
    | refuses the export/import instead of falling back to the whole database.
    |
    | `identity_keys` declares which columns identify a record across databases
    | (`'users' => ['external_id']`). Without a declared identity — or a unique
    | index, or a non-auto-increment primary key — an import inserts new rows
    | rather than matching on an auto-increment id that means nothing here.
    |
    */
    'smart_migration' => [
        'scope_resolver' => null,
        'global_tables' => [],
        'identity_keys' => [],
        'max_upload_bytes' => 64 * 1024 * 1024,
        'import_lock_seconds' => 1800,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Runner
    |--------------------------------------------------------------------------
    |
    | Maximum number of rows a query result renders. Larger results are
    | truncated so a stray SELECT cannot exhaust memory in the browser tab.
    |
    */
    'sql' => [
        'max_rows' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Workers (Scheduler + Queue)
    |--------------------------------------------------------------------------
    |
    | The Queue & Scheduler page detects whether the host has cron configured
    | for `schedule:run` and `queue:work`. Detection works via heartbeat files
    | this package touches every minute (scheduler) and every queue poll
    | (queue). Disable `heartbeats_enabled` if you don't want the package
    | to register its own scheduled tick + Queue::looping listener.
    |
    */
    'background_workers' => [
        'heartbeats_enabled' => env('FILAMENT_SYSTEM_TOOLS_HEARTBEATS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The Queue & Scheduler page can drain the queue with an in-request worker
    | ("Process now"). Running a worker inside a web request can block the
    | PHP-FPM process, so it is opt-in. Enable only on hosts without a
    | long-running worker (typically local/dev).
    |
    */
    'queue' => [
        'allow_inline_worker' => env('FILAMENT_SYSTEM_TOOLS_INLINE_WORKER', false),
    ],

];
