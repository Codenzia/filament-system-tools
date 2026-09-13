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

### Page-level access

Every page **fails closed**: it is hidden from navigation and returns 403 unless the user holds its view permission. Grant these to the roles that should reach each page (e.g. via filament-shield):

| Page | View permission |
| --- | --- |
| System Health | `view_system_health` |
| System Logs | `view_system_logs` |
| Database & Backups | `view_database_backups` |
| Queue & Scheduler | `view_queue_monitor` |
| Smart Data Migration | `view_smart_migration` |

The **About** page stays open (it redacts sensitive server/disk info behind `view_full_system_info`). System Logs additionally suppresses log content unless `view_system_logs` is granted, so parsed stack traces never leak to unauthorised users.

> **Upgrade note:** these page-level gates are new. After upgrading, assign the `view_*` permissions above or the pages will be hidden from every non-super-admin user.

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

> **Debug logging is application-wide.** The "Debug logging (30 min)" action (gated by `set_log_level`) lifts the default log channel to `debug` for **every request and every user** for 30 minutes, then reverts automatically. On a busy production app this can write large volumes of verbose data (including bound SQL parameters and request payloads) to disk — enable it only while actively troubleshooting and disable it as soon as you are done.

### Database & Backups

Full database table browser with row counts and sizes. Supports:

- **Table Schema Viewer** — inspect column definitions
- **Table Data Viewer** — browse table rows with pagination
- **SQL Query Runner** — runs in **read-only mode by default**: a single `SELECT`/`WITH`(read)/`SHOW`/`DESCRIBE`/`EXPLAIN` statement, or an introspection `PRAGMA` (assignment forms such as `PRAGMA foreign_keys = OFF` are refused and audited). Turning read-only off enables INSERT/UPDATE/DELETE and DDL; multi-statement input is always rejected and every write/DDL statement is audit-logged (`system-tools.sql`) with the acting user id and quoted literals redacted. Results render up to `sql.max_rows` (500 by default) rows.
- **Bulk Export** — download selected tables as `.sql` or `.json`
- **Bulk Import** — upload a `.sql` or `.json` file and restore data
- **Full Backup / Restore** — create, download, restore, and delete full database backups via the backup-creation modal:
  - Pick the source connection (SQLite / MySQL / MariaDB / PostgreSQL)
  - Optional gzip compression (`.sql.gz`)
  - Optional per-table filtering (SQLite & MySQL only)

Backups for SQLite without gzip use a fast file-copy path (no `sqlite3` binary required, and the copy's size is verified); everything else routes through the `DatabaseSqlTool` service which shells out to the appropriate native CLI tool (`sqlite3`, `mysqldump`/`mysql`, `pg_dump`/`psql`). **Database passwords are passed via environment variables** (`MYSQL_PWD`, `PGPASSWORD`) — never on the command line.

Dumps are written to a `.part` work file and only renamed into place after they are verified (non-empty, and a valid non-empty gzip stream when compressed), so a failed producer behind a successful compressor cannot leave a usable-looking backup. Restores refuse an empty or corrupt archive before invoking the client, `psql` runs with `ON_ERROR_STOP=1`, and `sqlite3` with `-bail`.

**Permissions.** The Database & Backups actions are individually gated and must be granted to the relevant roles (e.g. via filament-shield):

| Permission | Grants |
| --- | --- |
| `manage_table_schema` | View Schema action + adding, editing, and dropping columns (`TableSchemaViewer`) |
| `manage_table_data` | View Data action + inserting, updating, and deleting rows (`TableDataViewer`) |
| `execute_sql_queries` | Run SQL action |
| `create_database_backup` | Create a full backup |
| `download_database_backup` | Download a backup and use Bulk Export / Smart Export |
| `restore_database_backup` | Restore a backup and use Bulk Import |
| `delete_database_backup` | Delete a backup |
| `run_data_import` | Run a Smart Data Migration import |

> **Upgrade note:** `manage_table_schema` and `manage_table_data` are new gates for the table inspector. Existing deployments must assign them, otherwise the View Schema / View Data actions and their mutating operations will be hidden/blocked until the permissions are granted.

Restore never guesses its target: choosing **Restore** opens an inline confirmation where the operator picks the destination connection (pre-selected from the backup filename when it still exists) and retypes its name. Raw SQLite database files are restored by file copy, and the copied file is size-checked.

### Smart Data Migration

A schema-aware export / import wizard for moving data between databases that may have **diverged schemas** (renamed columns, added columns, restructured tables). Five steps:

1. **Upload** — pick a Smart Export `.json` file produced from another instance, or click **Smart Export** to generate one from this instance.
2. **Schema Analysis** — the page diffs the exported schema against the live DB and shows per-table:
   - Matched / dropped / added columns
   - Type-mismatch warnings
   - Suggested column renames (Levenshtein distance ≤ 3 + compatible types)
   - Tables that exist in the export but not in this DB (will be skipped)
3. **Configure** — review and accept / reject rename suggestions, pick which tables to import, choose **Skip** vs. **Update** on duplicates, toggle timestamp preservation. A configured scope is always enforced and cannot be switched off here.
4. **Importing** — runs inside a transaction with FK checks temporarily disabled. Tables are sorted topologically by FK dependency. Self-references and circular FKs are deferred and patched after the main pass; a required reference that cannot be resolved aborts the import instead of committing a dangling link. Auto-increment IDs are remapped so foreign keys land on the correct new IDs. Existing records are matched by a declared identity, a unique index, or a non-auto-increment primary key (UUID/natural key) — never by a coincident auto-increment id, so an unrelated destination row is never overwritten. Per-row errors stop after 100 to keep big imports moving. Per-table progress is streamed to the UI. A rolled-back import reports zero records imported.
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

When a resolver is configured the scope is **enforced**: it cannot be turned off from the wizard, tables without the scope column are excluded from both export and import unless they are listed in `smart_migration.global_tables`, identity lookups and updates are constrained to the scope, and a resolver that cannot produce a scope refuses the operation instead of falling back to the whole database.

```php
'smart_migration' => [
    'scope_resolver' => null,
    // Tables to include in a scoped export/import even though they carry no scope column.
    'global_tables' => [],
    // Columns that identify a record across databases, e.g. 'users' => ['external_id'].
    'identity_keys' => [],
    'max_upload_bytes' => 64 * 1024 * 1024,
    'import_lock_seconds' => 1800,
],
```

The `Codenzia\FilamentSystemTools\Services\SmartMigration\` namespace exposes `SmartExporter`, `SmartImporter`, `SchemaIntrospector`, `SchemaDiffer`, `TableSorter`, `IdRemapper`, `SchemaDiffResult`, and `ImportResult` for direct use in code, jobs, or custom commands.

### Queue & Scheduler

A real-time view of your job queue and scheduler:

- **Summary cards** — connection + driver, pending, processing, failed. Counts are read from the configured queue connection's own database connection and table; drivers that keep jobs elsewhere (Redis, SQS) say so instead of showing zeros.
- **Per-queue breakdown** — total / waiting / processing for each queue name.
- **Recent pending jobs** — last 10 with display name, queue, and attempts.
- **Failed jobs** — last 20 with one-click **Retry** and **Delete** per UUID, plus bulk **Retry all failed** and **Flush failed jobs**.
- **Job batches** — last 10 with progress percent.
- **Scheduled tasks** — every event registered via the Laravel Scheduler with its cron expression and the **next run time** (computed via `Cron\CronExpression`).
- **Worker controls** — `queue:restart` and `schedule:run` buttons; a failing command exit status is reported instead of a success message.
- **Clear waiting jobs** — deletes only jobs no worker has reserved, so work already in flight is untouched (database queue driver only).

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

    // Per-page sidebar order, so a host app can interleave these pages with its
    // own. Override per app via config or the plugin's ->navigationSort([...]).
    'navigation_sort' => [
        'health'           => 99,
        'database_backup'  => 101,
        'smart_migration'  => 102,
        'queue_monitor'    => 103,
        'logs'             => 103,
        'about'            => 104,
    ],

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
    ->navigationSort([          // interleave these pages with your own
        'database_backup' => 30,
        'queue_monitor'   => 50,
        'health'          => 60,
        'logs'            => 70,
        'about'           => 999,
    ])
```

All pages are enabled by default — pass `false` to any of the toggles to hide that page.

### Navigation group & order

`->navigationGroup('Tools')` re-homes every page to a different sidebar group, and
`->navigationSort([...])` (keys: `health`, `database_backup`, `smart_migration`,
`queue_monitor`, `logs`, `about`) reorders them — both merge over the config
defaults, so a host app can slot these pages between its own. Either method writes
the corresponding `config('filament-system-tools.navigation_group' | 'navigation_sort')`
value, so you can also set them directly in the published config instead.

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
