# CLAUDE.md — filament-system-tools

Codenzia global standards apply (see `GitHub/CLAUDE.md`). Package-specific policy below.

## BVT policy (Build Verification Tests)

`tests/BVT/` is the package's thin **existence net** — one shallow check per
shipped surface. Its job is to fail loudly when a Filament page, Livewire
component, or shipped view is renamed, deleted, or starts fataling on load. It
is **not** a place for behaviour coverage.

Rules when touching this package:

- **Every new page / Livewire component / shipped view** gets a matching entry
  in the `tests/BVT/` roll-call (class + base type + view resolution).
- **Keep BVT shallow.** Deep behaviour lives in the feature suites
  (`SystemHealthTest`, `DatabaseBackupTest`, `QueueMonitorTest`,
  `SystemLogsTest`, `DatabaseSqlToolTest`, `SmartImporterIntegrationTest`, …).
- Surfaces that only fully render inside a booted Filament panel (the
  `HasTable`/`HasForms` DB viewers) are covered by class/view roll-call only.
- Update `FEATURES.md` when the surface inventory changes.

## Review docs

`fable-*` / `*-full-review*` files are internal review scratch — gitignored,
never commit them.
