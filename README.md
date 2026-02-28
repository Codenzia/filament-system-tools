# Filament System Tools

System administration pages for [Filament v4](https://filamentphp.com): log viewer, cache management, database backup & restore, and system info.

## Installation

```bash
composer require codenzia/filament-system-tools
```

Publish the config file:

```bash
php artisan vendor:publish --tag="filament-system-tools-config"
```

## Setup

Register the plugin in your Filament panel provider:

```php
use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->plugins([
            FilamentSystemToolsPlugin::make()
                ->navigationGroup('System'),
        ]);
}
```

## Custom Theme (Tailwind v4)

If your panel uses a custom Filament theme with `->viteTheme()`, you must add `@source` directives so Tailwind compiles the classes used by this package:

```css
/* resources/css/filament/admin/theme.css */
@source '../../../../vendor/codenzia/filament-system-tools/src/**/*.php';
@source '../../../../vendor/codenzia/filament-system-tools/resources/views/**/*.blade.php';
```

Then rebuild your assets with `npm run build`.

## Pages

### System Logs

Real-time log viewer with level filtering, auto-refresh, clear, and download capabilities. Parses Laravel daily log files into structured entries with timestamps, levels, messages, and stack traces.

**Permission:** Clearing logs requires the `clear_system_logs` permission.

### Cache Management

One-click buttons to clear application cache, config cache, route cache, view cache, and event cache. Also provides an "Optimize" action that caches config, routes, and views. Displays current system info (PHP version, drivers, environment).

### Database & Backups

Full database table browser with row counts and sizes. Supports:

- **Table Schema Viewer** — inspect column definitions
- **Table Data Viewer** — browse table rows with pagination
- **SQL Query Runner** — execute raw SELECT queries against any table
- **Export** — bulk export selected tables as `.sql` or `.json`
- **Import** — upload and restore from `.sql` or `.json` files
- **Full Backup/Restore** — create, download, restore, and delete full database backups (uses `mysqldump` for MySQL, file copy for SQLite)

### About

System information dashboard showing environment details, server info, database stats, configured drivers, disk usage, and release version.

## Configuration

```php
// config/filament-system-tools.php

return [
    // Used in database export filenames and SQL comments
    'app_name' => env('APP_NAME', 'Laravel'),

    // Displayed on the About page
    'release' => [
        'version' => env('APP_VERSION', '1.0.0'),
        'name'    => env('APP_RELEASE_NAME', ''),
        'date'    => env('APP_RELEASE_DATE', ''),
    ],

    // Navigation group for all system tool pages
    'navigation_group' => 'System',

    // Directory where database backups are stored
    'backup_path' => storage_path('app/backups'),

    // Tables excluded from the database browser and exports
    'excluded_tables' => [
        'migrations', 'personal_access_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'password_reset_tokens',
    ],
];
```

## Plugin API

Toggle individual pages on or off:

```php
FilamentSystemToolsPlugin::make()
    ->enableLogs(true)
    ->enableCache(true)
    ->enableBackups(true)
    ->enableAbout(true)
    ->navigationGroup('System')
```

## Requirements

- PHP 8.3+
- Laravel 12+
- Filament v4

## License

This package is dual-licensed:

- **MIT License** — Free for open source projects under an OSI-approved license.
- **Commercial License** — Required for proprietary/commercial projects. Visit [codenzia.com](https://codenzia.com) for details.

See [LICENSE.md](LICENSE.md) for full terms.
