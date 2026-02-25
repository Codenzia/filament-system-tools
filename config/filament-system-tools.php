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

];
