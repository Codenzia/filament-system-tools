# Changelog

All notable changes to `codenzia/filament-system-tools` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] - 2026-09-13

### Fixed
- `TableDataViewer::buildRowForm()` documented its return as `array<\Filament\Forms\Components\Component>`, a class removed in Filament v4. It now names `Filament\Schemas\Components\Component`.

### Added
- `tests/BVT/FilamentImportsResolveTest` resolves every `use Filament\...;` import in `src/` against the installed Filament.

## [0.3.1] - 2026-09-08

### Fixed
- Table data viewer no longer throws when resolving records; trait methods are aliased instead of called through parent.

## [0.3.0] - 2026-09-08

### Fixed
- Smart Import no longer treats a coincident auto-increment id as proof that two
  rows are the same record. Existing rows are matched by a declared identity
  (`smart_migration.identity_keys`), a unique index, or a non-auto-increment
  primary key (UUID/natural key); everything else is inserted as a new row.
- Smart Import identity lookups and duplicate updates run inside the active
  scope, so a scoped import can no longer match or overwrite another tenant's row.
- A scoped export/import now excludes tables without the scope column unless they
  are listed in `smart_migration.global_tables`, the wizard can no longer turn the
  scope off, and an unresolvable scope refuses the operation instead of falling
  back to the whole database.
- An empty table selection imports nothing instead of being widened to every table.
- A required deferred foreign key that cannot be resolved aborts the import
  instead of committing a dangling reference; a rolled-back import reports zero
  records imported.
- Database dumps are written to a `.part` work file and verified (non-empty; valid,
  non-empty gzip stream) before being renamed into place, so a failed dump behind a
  successful compressor cannot appear as a usable backup. Imports refuse an empty or
  corrupt archive, `psql` runs with `ON_ERROR_STOP=1`, and `sqlite3` with `-bail`.
- SQLite backup and restore file copies are size-checked instead of assumed.
- The legacy `.sql`/`.json` importer reads the connection's driver instead of
  comparing the connection *name* with `sqlite`, sets SQLite's `foreign_keys`
  pragma outside the transaction where it has effect, supports PostgreSQL, and
  restores constraint state on every exit path.
- Restoring a backup requires an explicitly chosen target connection and a typed
  confirmation; the filename is only a pre-selection, never a silent fallback.
- Row editing uses the table's full primary or unique key, including composite
  keys, so editing or deleting one pivot row can no longer affect its siblings.
  Tables with no primary or unique key are read-only.
- SQL read-only mode now refuses `PRAGMA` assignments and write-carrying CTEs,
  audits refused statements, and redacts quoted literals from the audit entry.
  Results are capped by `sql.max_rows` (default 500).
- `SystemLogs::downloadLog()` returned a `BinaryFileResponse` while declaring
  `StreamedResponse`, raising a `TypeError` on every real log download.
- Queue metrics read the configured queue connection's database connection and
  table (plus `queue.failed`/`queue.batching` configuration) instead of the
  default `jobs`, `failed_jobs` and `job_batches` tables, and report when a
  driver keeps jobs out of reach. "Clear pending" deletes only unreserved jobs,
  leaving work a worker has already picked up alone.
- Queue and cache commands report a failing exit status instead of a success
  notification.
- "Clear all caches" (`optimize:clear`) requires every capability it clears —
  application, config, route, view, event and compiled — not just
  `clear_application_cache`.
- Smart Migration cleans up a previous upload before staging a new one, enforces
  an upload size cap, and bounds the request to the same budget as its lock.

### Added
- `smart_migration.global_tables`, `smart_migration.identity_keys`,
  `smart_migration.max_upload_bytes`, `smart_migration.import_lock_seconds` and
  `sql.max_rows` configuration keys.

## [0.2.1] - 2026-07-13

### Fixed
- SQL query runner view failed to compile: the write-mode `wire:confirm` was
  wrapped in `@unless`/`@endunless` inside the `<x-filament::button>` tag, which
  Blade's component-tag compiler cannot parse (broken compiled PHP, page 500).
  The confirm prompt is now a bound attribute that is omitted in read-only mode.

## [0.2.0] - 2026-07-13

### Security
- SYSTOOLS-SEC-01: the raw SQL runner now defaults to read-only, enforces single-statement execution, and writes an audit log entry per run.
- SYSTOOLS-SEC-02: identifiers are escaped and the operation type is validated in DDL and SQL export paths.
- SYSTOOLS-SEC-03: backup file paths are validated against the directory listing to block path traversal.
- SYSTOOLS-SEC-04: `canAccess()` gates were added to all pages, log reads are gated, and access now fails closed when Filament is unavailable.
- SYSTOOLS-SEC-05: documented app-wide debug logging and the page/SQL gates in the README.
- SYSTOOLS-SEC-06: the inline queue worker is now gated behind an opt-in config flag.
- **Table inspector is now authorization-gated.** The table data/schema viewers
  (`TableDataViewer`, `TableSchemaViewer`) previously executed destructive
  DDL/DML (drop column, delete rows, etc.) with no permission check. They now
  require two **new permissions** — `manage_table_data` and
  `manage_table_schema` — enforced both on the View Data / View Schema actions
  and server-side inside every mutating method. **Existing deployments must
  grant these permissions** or the table inspector actions will be hidden.
- Fixed SQL injection in `TableSchemaViewer`: the client-hydrated table name and
  column identifiers are now whitelist-validated against the live schema before
  any `ALTER TABLE` runs.
- `SmartDataMigration::$tempFilePath` is now `#[Locked]` and every read/unlink is
  constrained to managed `smart_migration_*` files under `storage/app`,
  preventing arbitrary file deletion via a tampered property.

### Fixed
- SQL bulk import now uses a quote/comment-aware statement splitter instead of a
  naive `explode(';')`, so dumps whose values contain semicolons import
  correctly. FK checks are disabled around the import for consistency with JSON.
- JSON bulk export now streams tables row-by-row, avoiding OOM on large tables.
- Removed an N+1 when listing tables on MySQL/MariaDB by pulling approximate row
  counts and on-disk size from a single `information_schema` query.
- SYSTOOLS-QUAL-01: JSON import now reports skipped rows and errors instead of failing silently.
- SYSTOOLS-QUAL-02: log file reads use a bounded tail read to avoid loading large files into memory.
- SYSTOOLS-QUAL-03: smart import lifts the execution time limit and takes a concurrency lock.
- SYSTOOLS-PERF-01: dropped the fake SQLite table-size estimate and its associated N+1.
- SYSTOOLS-FIL-01: actions use `schema()` instead of the deprecated `form()`.
- SYSTOOLS-STD-01: replaced an interpolated color class with a Filament badge for log levels.
- SYSTOOLS-DUP-01/02: extracted shared `Bytes::format` and `SmartMigration\TableName::strip` helpers.
- SYSTOOLS-QUAL-04/06: added `declare(strict_types=1)` to remaining files and cast `database.default` config to string.

### Added
- **Per-page navigation order is now configurable.** New `navigation_sort` config map (keys: `health`, `database_backup`, `smart_migration`, `queue_monitor`, `logs`, `about`) and a fluent `FilamentSystemToolsPlugin::make()->navigationSort([...])`, so a host app can interleave these pages with its own in the sidebar instead of being stuck on the package defaults. `->navigationGroup()` now also writes the config value so group + order share one mechanism.
- **Background-worker cron detection** on the Queue & Scheduler page. The
  page now shows two status cards at the top — one for the Laravel scheduler,
  one for the queue worker — that report:
  - whether the host app actually *needs* each worker (any `Schedule::`
    events registered, any classes implementing `ShouldQueue` found under
    `app/Jobs`, `app/Events`, `app/Notifications`),
  - whether cron is currently running on the host (detected via heartbeat
    files the package touches via a one-line scheduled callback and a
    `Queue::looping()` listener), and
  - the exact cron line to paste into hPanel / cPanel when missing.
- New service `Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector`
  with a public `summary()` API so consumers can wire the same data into
  their own widgets / dashboards.
- New config block `background_workers.heartbeats_enabled` (default true,
  env `FILAMENT_SYSTEM_TOOLS_HEARTBEATS`) to opt out of the heartbeat
  registration if you prefer to run your own.
- **Queue draining controls** on the Queue & Scheduler page (gated by the
  `manage_queue_jobs` permission):
  - a **stalled-queue alert** banner when jobs are pending but no worker is
    processing them (pending > 0, none reserved, no heartbeat),
  - **Process now** — drains the queue in-process (bounded by time/job caps)
    for local/dev or a one-off catch-up when no long-running worker is set up,
  - **Process queue now** + a one-click **Copy** for the cron line on the
    "queue worker — cron not detected" card, and
  - **Clear pending** — drops the pending backlog without running it.
  Covered by tests against an in-memory `jobs` table.

## [0.1.0] - 2026-05-20

### Added
- First tracked release. Early beta. Earlier history not recorded in this changelog — see git log for changes prior to release-tracker adoption.
