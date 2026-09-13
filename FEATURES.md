# filament-system-tools — Feature Surfaces

Inventory of the surfaces this package ships and where each is covered. The
**BVT** column points at the thin existence net under `tests/BVT/`; the **Deep**
column points at the behaviour suites.

## Filament pages

| Surface | Class | BVT | Deep |
| --- | --- | --- | --- |
| About | `Pages\About` | roll-call + view | `tests/PluginTest` |
| Database backup | `Pages\DatabaseBackup` | roll-call + view | `tests/DatabaseBackupTest` |
| Queue monitor | `Pages\QueueMonitor` | roll-call + view | `tests/QueueMonitorTest`, `BackgroundWorkerInspectorTest` |
| Smart data migration | `Pages\SmartDataMigration` | roll-call + view | `tests/SmartMigrationServicesTest`, `SmartImporterIntegrationTest` |
| System health | `Pages\SystemHealth` | roll-call + view | `tests/SystemHealthTest` |
| System logs | `Pages\SystemLogs` | roll-call + view | `tests/SystemLogsTest` |

## Livewire components (DB explorer)

| Surface | Class | BVT | Deep |
| --- | --- | --- | --- |
| SQL query runner | `Livewire\SqlQueryRunner` | roll-call + view | `tests/DatabaseSqlToolTest`, `DatabaseCommandsTest` |
| Table data viewer | `Livewire\TableDataViewer` | roll-call + view | `tests/DatabaseCommandsTest` |
| Table schema viewer | `Livewire\TableSchemaViewer` | roll-call + view | `tests/DatabaseCommandsTest` |

## BVT scope

The BVT layer (`tests/BVT/`) is the thin **build-verification** net: every
shipped page and Livewire component must load (no autoload/type fatal) and
expose a resolvable package view. The table/schema viewers implement Filament
`HasTable`/`HasForms`/`HasActions` and only fully render inside a booted panel,
so BVT covers them by class/view roll-call — their behaviour lives in the
feature suites above.
