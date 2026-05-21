# Filament System Tools — Logs, cache, backups, health, queue & scheduler

[![Latest Version](https://img.shields.io/packagist/v/codenzia/filament-system-tools.svg?style=flat-square)](https://packagist.org/packages/codenzia/filament-system-tools)
[![PHP Version](https://img.shields.io/packagist/php-v/codenzia/filament-system-tools.svg?style=flat-square)](https://packagist.org/packages/codenzia/filament-system-tools)
[![Filament](https://img.shields.io/badge/Filament-v4%20%7C%20v5-f59e0b?style=flat-square)](https://filamentphp.com)
[![Tests](https://img.shields.io/badge/tests-Pest%20v3-8b5cf6?style=flat-square)](https://pestphp.com)
[![License](https://img.shields.io/badge/license-MIT%20%7C%20Proprietary-blue?style=flat-square)](LICENSE.md)

A **complete system administration toolkit for [Filament v4 and v5](https://filamentphp.com)** — health dashboard, cache management, log viewer, database backup/restore, queue + scheduler monitoring, and a schema-aware data-migration wizard. Replaces Adminer, custom artisan scripts, and a stack of one-off SSH helpers with first-class Filament pages.

> **Why this exists.** Most Laravel admin panels stop at CRUD. Once your app is in production, you spend half your time outside the panel — `tail`ing logs over SSH, clearing caches via artisan, restoring backups by hand, and copy-pasting `php artisan about` into support tickets. This package brings all of that into Filament where you (and your team) already live.

> **Try it live:** A working integration is included in the [Codenzia plugins demo](https://github.com/Codenzia/plugins-demo) at `/admin/demo/system-tools`.

---

## Features

- **System Health** — live checks (DB / cache / queue / mail / HTTPS / storage symlink), production-readiness checklist, environment table, **cache management** (clear application / config / route / view / event caches, `optimize`, `clear-compiled`, on-disk footprint), one-click `storage:link` with native `ln -s` / `mklink` fallback for hosts where PHP `symlink()` is disabled.
- **System Logs** — real-time log viewer with level filtering, auto-refresh, clear, and download.
- **Database & Backups** — full table browser, SQL runner, and multi-driver backup / restore (SQLite / MySQL / MariaDB / PostgreSQL) with optional gzip and table filtering.
- **Smart Data Migration** — schema-aware export/import wizard. Bundles your data with schema metadata, diffs against the current DB, suggests column renames, runs FK-ordered batch imports with automatic ID remapping, deferred self-references, and circular-FK handling. Survives renamed / added / dropped columns between source and target.
- **Queue & Scheduler** — live counts (pending / processing / failed), per-queue breakdown, recent failed jobs with per-job retry / delete, recent batches with progress, list of scheduled tasks with next-run time, one-click `queue:restart` / `schedule:run`. **Background-worker cron detection**: at the top of the page, two cards flag whether the app *needs* a scheduler / queue worker (by inspecting registered `Schedule::` events and `ShouldQueue` jobs) and whether cron is *actually running* on the host (heartbeat-file detection); when missing, the exact cron line to paste into hPanel / cPanel is shown.
- **About** — release info, comprehensive system snapshot (environment, server, database, drivers, disk), and a "Copy support snapshot" button that exports a markdown summary ready to paste into a support ticket.

Ships with `db:export` and `db:import` Artisan commands so the same migration logic is available from the CLI.

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Filament | `^4.0 \|\| ^5.0` |

---

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

If your panel uses a custom Filament theme with `->viteTheme()`, add `@source` directives so Tailwind compiles the classes used by this package:

```css
/* resources/css/filament/admin/theme.css */
@source '../../../../vendor/codenzia/filament-system-tools/src/**/*.php';
@source '../../../../vendor/codenzia/filament-system-tools/resources/views/**/*.blade.php';
```

Then rebuild assets with `npm run build`.

## Pages

### System Health

The operational dashboard. Five panels in one page:

- **Health Checks** — DB / cache / storage / queue / mail / environment / HTTPS / storage symlink / scheduler. Each check is green / yellow / red with a one-line detail.
- **Production Readiness** — checklist of items to fix before going live (`APP_ENV=production`, `APP_DEBUG=false`, real queue / mail driver, HTTPS, storage symlink, real DB driver).
- **Quick Actions** — one-click `storage:link` (with fallback), `optimize`, clear-all-caches, and an optional `migrate --force` button.
- **Cache Management** — current on-disk cache footprint plus per-cache buttons: clear application / config / route / view / event caches and remove the compiled class file. The `Quick Actions › Clear all caches` button runs `optimize:clear` for everything in one shot.
- **Environment** — comprehensive table with PHP / Laravel / server / drivers / memory / disk / etc.

The **storage symlink** action first tries `php artisan storage:link`. When PHP's `symlink()` is in `disable_functions` (common on shared PHP-FPM hosting) it falls back to `ln -s` on Linux/macOS and `mklink /D` on Windows. If both fail it surfaces a clear "create the link manually" message.

The "Run Migrations" button is **off by default** because applying migrations from a UI is a production-affecting operation. Enable it explicitly:

```php
// config/filament-system-tools.php
'health' => [
    'allow_run_migrations' => env('FILAMENT_SYSTEM_TOOLS_ALLOW_MIGRATE', true),
],
```

### System Logs

Real-time log viewer with level filtering, auto-refresh, clear, and download capabilities. Parses Laravel daily log files into structured entries with timestamps, levels, messages, and stack traces.

**Permission:** Clearing logs requires the `clear_system_logs` permission.

### Database & Backups

Full database table browser with row counts and sizes. Supports:

- **Table Schema Viewer** — inspect column definitions
- **Table Data Viewer** — browse table rows with pagination
- **SQL Query Runner** — execute raw `SELECT` queries against any table
- **Bulk Export** — download selected tables as `.sql` or `.json`
- **Bulk Import** — upload a `.sql` or `.json` file and restore data
- **Full Backup / Restore** — create, download, restore, and delete full database backups via the backup-creation modal:
  - Pick the source connection (SQLite / MySQL / MariaDB / PostgreSQL)
  - Optional gzip compression (`.sql.gz`)
  - Optional per-table filtering (SQLite & MySQL only)

Backups for SQLite without gzip use a fast file-copy path (no `sqlite3` binary required); everything else routes through the `DatabaseSqlTool` service which shells out to the appropriate native CLI tool (`sqlite3`, `mysqldump`/`mysql`, `pg_dump`/`psql`). **Database passwords are passed via environment variables** (`MYSQL_PWD`, `PGPASSWORD`) — never on the command line.

Restore auto-detects the source connection from the backup filename, falls back to `database.default` for legacy backups, and uses file-copy when the backup is a raw SQLite database file.

### Smart Data Migration

A schema-aware export / import wizard for moving data between databases that may have **diverged schemas** (renamed columns, added columns, restructured tables). Five steps:

1. **Upload** — pick a Smart Export `.json` file produced from another instance, or click **Smart Export** to generate one from this instance.
2. **Schema Analysis** — the page diffs the exported schema against the live DB and shows per-table:
   - Matched / dropped / added columns
   - Type-mismatch warnings
   - Suggested column renames (Levenshtein distance ≤ 3 + compatible types)
   - Tables that exist in the export but not in this DB (will be skipped)
3. **Configure** — review and accept / reject rename suggestions, pick which tables to import, choose **Skip** vs. **Update** on duplicates, toggle timestamp preservation, optionally apply a configured row-level scope.
4. **Importing** — runs inside a transaction with FK checks temporarily disabled. Tables are sorted topologically by FK dependency. Self-references and circular FKs are deferred and patched after the main pass. Auto-increment IDs are remapped so foreign keys land on the correct new IDs. Per-row errors stop after 100 to keep big imports moving. Per-table progress is streamed to the UI.
5. **Complete** — summary cards (records imported, tables, skipped, errors) and an expandable list of warnings and errors.

**Tenant scoping (optional).** If your app is multi-tenant, point the importer at the active tenant by setting a scope resolver in config — Smart Migration will filter exports to that tenant and stamp imported rows with the target tenant's id:

```php
// config/filament-system-tools.php
'smart_migration' => [
    'scope_resolver' => fn () => Filament::getTenant()
        ? ['column' => 'team_id', 'value' => Filament::getTenant()->id]
        : null,
],
```

Leave it as `null` to operate on the entire database.

The `Codenzia\FilamentSystemTools\Services\SmartMigration\` namespace exposes `SmartExporter`, `SmartImporter`, `SchemaIntrospector`, `SchemaDiffer`, `TableSorter`, `IdRemapper`, `SchemaDiffResult`, and `ImportResult` for direct use in code, jobs, or custom commands.

### Queue & Scheduler

A real-time view of your job queue and scheduler:

- **Summary cards** — driver, pending, processing, failed.
- **Per-queue breakdown** — total / waiting / processing for each queue name.
- **Recent pending jobs** — last 10 with display name, queue, and attempts.
- **Failed jobs** — last 20 with one-click **Retry** and **Delete** per UUID, plus bulk **Retry all failed** and **Flush failed jobs**.
- **Job batches** — last 10 with progress percent.
- **Scheduled tasks** — every event registered via the Laravel Scheduler with its cron expression and the **next run time** (computed via `Cron\CronExpression`).
- **Worker controls** — `queue:restart` and `schedule:run` buttons.

The page reads from `jobs`, `failed_jobs`, and `job_batches` directly via `DB`, so it works regardless of queue driver as long as those tables exist. When they don't, the page degrades gracefully and shows zeros.

### About

A comprehensive system snapshot for support tickets and audit logs:

- **Release info** — version, name, and date pulled from `config/filament-system-tools.php` or `APP_VERSION` / `APP_RELEASE_NAME` / `APP_RELEASE_DATE` env vars.
- **Environment** — PHP, Laravel, Filament, app env, debug, URL, timezone, locale.
- **Server** — OS, architecture, SAPI, memory limit, max upload / post / execution time.
- **Database** — driver, host, name, version, table count, size.
- **Drivers** — cache / session / queue / mail / filesystem / broadcasting / log channel.
- **Storage** — disk total / used / free / usage percent + logs directory size.

A **"Copy support snapshot"** button at the top of the page generates a markdown-formatted summary of all of the above and copies it to the clipboard with one click — paste it into a Slack thread, GitHub issue, or support ticket.

System Health is for "is anything broken right now"; About is for "give me a comprehensive snapshot to send to support."

## Artisan Commands

The same export/import logic is available from the CLI for cron jobs, deploys, and CI:

```bash
# Export the default connection to storage/app/backups
php artisan db:export

# Export a specific connection, gzipped, to a custom path
php artisan db:export --connection=mysql --path=/srv/dumps/app.sql --gzip

# Export only specific tables (SQLite / MySQL only)
php artisan db:export --connection=mysql --table=users --table=orders

# Import a SQL file (refuses to run without --force)
php artisan db:import /srv/dumps/app.sql.gz --connection=mysql --force
```

Both commands return non-zero exit codes on failure with a human-readable error message.

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

    // Directory where database backups and CLI exports are written
    'backup_path' => storage_path('app/backups'),

    // Tables excluded from the database browser and exports
    'excluded_tables' => [
        'migrations', 'personal_access_tokens', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'password_reset_tokens',
        // ... see config file for the full list
    ],

    // Override binary paths when they're not on PATH (common on shared hosting
    // and inside PHP-FPM with restricted PATH).
    'dump' => [
        'sqlite'      => ['sqlite3'   => env('DB_DUMP_SQLITE3',   'sqlite3')],
        'mysql'       => [
            'mysqldump' => env('DB_DUMP_MYSQLDUMP', 'mysqldump'),
            'mysql'     => env('DB_DUMP_MYSQL',     'mysql'),
        ],
        'pgsql'       => [
            'pg_dump' => env('DB_DUMP_PG_DUMP', 'pg_dump'),
            'psql'    => env('DB_DUMP_PSQL',    'psql'),
        ],
        'compression' => [
            'gzip'   => env('DB_DUMP_GZIP',   'gzip'),
            'gunzip' => env('DB_DUMP_GUNZIP', 'gunzip'),
        ],
    ],
];
```

### Binary Path Overrides

When the CLI tools aren't on `PATH` (typical on macOS with Homebrew and on shared hosting where PHP-FPM's `PATH` is minimal), set absolute paths in `.env`:

```ini
DB_DUMP_MYSQLDUMP="/opt/homebrew/opt/mysql-client/bin/mysqldump"
DB_DUMP_MYSQL="/opt/homebrew/opt/mysql-client/bin/mysql"
DB_DUMP_PG_DUMP="/opt/homebrew/opt/postgresql@16/bin/pg_dump"
DB_DUMP_PSQL="/opt/homebrew/opt/postgresql@16/bin/psql"
```

When a binary is missing, the plugin surfaces an installation hint with the original error, instead of silently failing.

## Plugin API

Toggle individual pages on or off:

```php
FilamentSystemToolsPlugin::make()
    ->enableHealth(true)
    ->enableLogs(true)
    ->enableBackups(true)
    ->enableSmartMigration(true)
    ->enableQueueMonitor(true)
    ->enableAbout(true)
    ->navigationGroup('System')
```

All pages are enabled by default — pass `false` to any of the toggles to hide that page.

### Breaking change: `enableCache()` removed

The standalone **Cache Management** page has been folded into **System Health** as a panel. The `->enableCache(true|false)` toggle no longer exists — calling it will throw `BadMethodCallException`. Remove the call from your panel provider:

```diff
 FilamentSystemToolsPlugin::make()
     ->enableHealth(true)
-    ->enableCache(true)
     ->enableBackups(true)
```

If you were toggling the cache page off, use `->enableHealth(false)` instead. All cache-clearing actions now live on the System Health page.

## Migrating from `codenzia/filament-db-flow`

`filament-db-flow` is **deprecated** in favor of this package — `filament-system-tools` now contains everything `filament-db-flow` offered (multi-driver dumps, gzip, table filtering, `db:export` / `db:import`) plus the table browser, SQL runner, log viewer, and About page.

To migrate:

1. Replace the dependency:
   ```bash
   composer remove codenzia/filament-db-flow
   composer require codenzia/filament-system-tools
   ```
2. Swap the plugin registration in your panel provider:
   ```php
   // Before
   FilamentDbFlowPlugin::make()
   // After
   FilamentSystemToolsPlugin::make()
   ```
3. The `DB_DUMP_*` environment variables work identically — no changes needed.
4. The `db:export` and `db:import` Artisan commands have the same signatures.

Existing `filament-db-flow` JSON-tracked backup metadata is **not** migrated automatically; the new page lists files directly from disk under `filament-system-tools.backup_path`. Move any existing backup files into that directory and they will appear in the UI.

## Service: `DatabaseSqlTool`

If you want to use the export/import logic outside the UI/CLI:

```php
use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;

$path = app(DatabaseSqlTool::class)->export(
    connection: 'mysql',
    path: '/srv/dumps/snapshot.sql.gz',
    gzip: true,
    tables: ['users', 'orders'],
);

app(DatabaseSqlTool::class)->import(
    path: '/srv/dumps/snapshot.sql.gz',
    connection: 'mysql',
);
```

Throws `RuntimeException` with a helpful, driver-specific message when binaries are missing or the underlying process fails.

## Requirements

- PHP 8.3+
- Laravel 12+
- Filament v4 or v5
- For MySQL/MariaDB backups: `mysqldump` and `mysql` CLI tools (ship with the MySQL Server / `mysql-client` package)
- For PostgreSQL backups: `pg_dump` and `psql` CLI tools
- For SQLite backups with gzip or table filtering: `sqlite3` CLI (file-copy fast path doesn't require it)

## License

This package is dual-licensed:

- **MIT License** — Free for open source projects under an OSI-approved license.
- **Commercial License** — Required for proprietary/commercial projects. Visit [codenzia.com](https://codenzia.com) for details.

See [LICENSE.md](LICENSE.md) for full terms.
