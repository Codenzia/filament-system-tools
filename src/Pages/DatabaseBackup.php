<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Models\DatabaseTable;
use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;
use Codenzia\FilamentSystemTools\Support\Bytes;
use Codenzia\FilamentSystemTools\Support\SqlStatementSplitter;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DatabaseBackup extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 101;

    public static function getNavigationSort(): ?int
    {
        return config('filament-system-tools.navigation_sort.database_backup', 101);
    }

    protected static ?string $slug = 'system/backups';

    protected string $view = 'filament-system-tools::pages.database-backup';

    public mixed $importFile = null;

    /** Backup staged for restore, set server-side by beginRestore(). */
    #[Locked]
    public ?string $restoreFile = null;

    /** Connection the staged backup will be restored into. */
    public ?string $restoreConnection = null;

    /** The operator must retype the target connection name before restoring. */
    public string $restoreConfirmation = '';

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Database & Backups');
    }

    public function getTitle(): string
    {
        return __('Database & Backups');
    }

    public static function canAccess(): bool
    {
        return app()->bound('filament')
            && (filament()->auth()->user()?->can('view_database_backups') ?? false);
    }

    // ──────────────────────────────────────────────
    // Filament Table
    // ──────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => DatabaseTable::query())
            ->columns([
                TextColumn::make('name')
                    ->label(__('Table Name'))
                    ->searchable()
                    ->sortable()
                    ->fontFamily(FontFamily::Mono)
                    ->size(TextSize::Small),
                TextColumn::make('rows')
                    ->label(__('Rows'))
                    ->state(fn ($record): int => $record->getRowCount())
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('size')
                    ->label(__('Size'))
                    ->state(fn ($record): string => $record->getPreloadedSize() !== null
                        ? Bytes::format($record->getPreloadedSize())
                        : $this->getTableSize($record->name))
                    ->sortable()
                    ->alignEnd(),
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('viewSchema')
                        ->label(__('View Schema'))
                        ->icon('heroicon-o-table-cells')
                        ->visible(fn (): bool => filament()->auth()->user()?->can('manage_table_schema') ?? false)
                        ->slideOver()
                        ->modalWidth(Width::SevenExtraLarge)
                        ->modalHeading(fn ($record): string => __('Schema: :table', ['table' => $record->name]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn ($record) => view('filament-system-tools::pages.partials.table-schema-modal', [
                            'tableName' => $record->name,
                        ])),
                    Actions\Action::make('viewData')
                        ->label(__('View Data'))
                        ->icon('heroicon-o-rectangle-stack')
                        ->visible(fn (): bool => filament()->auth()->user()?->can('manage_table_data') ?? false)
                        ->slideOver()
                        ->modalWidth(Width::SevenExtraLarge)
                        ->modalHeading(fn ($record): string => __('Data: :table', ['table' => $record->name]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn ($record) => view('filament-system-tools::pages.partials.table-data-modal', [
                            'tableName' => $record->name,
                        ])),
                    Actions\Action::make('runSql')
                        ->label(__('Run SQL'))
                        ->icon('heroicon-o-command-line')
                        ->visible(fn (): bool => filament()->auth()->user()?->can('execute_sql_queries') ?? false)
                        ->slideOver()
                        ->modalWidth(Width::FiveExtraLarge)
                        ->modalHeading(fn ($record): string => __('SQL Query: :table', ['table' => $record->name]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->modalContent(fn ($record) => view('filament-system-tools::pages.partials.sql-query-modal', [
                            'tableName' => $record->name,
                        ])),
                ]),
            ])
            ->toolbarActions([
                Actions\BulkAction::make('exportSql')
                    ->label(__('Export SQL'))
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (): bool => $this->canDownloadBackup())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records): StreamedResponse => $this->exportAsSql(
                        $records->pluck('name')->toArray(),
                        date('Y-m-d_His')
                    )),
                Actions\BulkAction::make('exportJson')
                    ->label(__('Export JSON'))
                    ->icon('heroicon-o-code-bracket')
                    ->visible(fn (): bool => $this->canDownloadBackup())
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records): StreamedResponse => $this->exportAsJson(
                        $records->pluck('name')->toArray(),
                        date('Y-m-d_His')
                    )),
            ])
            ->paginated([5, 10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('No tables found'))
            ->emptyStateDescription(__('No database tables available.'));
    }

    private function getTableSize(string $table): string
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            // SQLite has no cheap per-table byte size (dbstat is optional), and a
            // COUNT(*) per listed row is an N+1. Show a dash rather than a made-up
            // figure; the whole-DB size is available in getDatabaseStats().
            return '—';
        }

        $database = config("database.connections.{$connection}.database");
        $result = DB::select(
            'SELECT (data_length + index_length) as size FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$database, $table]
        );

        return Bytes::format($result[0]->size ?? 0);
    }

    // ──────────────────────────────────────────────
    // Database Stats
    // ──────────────────────────────────────────────

    public function getDatabaseStats(): array
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            $size = File::exists($dbPath) ? Bytes::format(File::size($dbPath)) : 'N/A';
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        } else {
            $database = config("database.connections.{$connection}.database");
            $result = DB::select(
                'SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = ?',
                [$database]
            );
            $size = Bytes::format($result[0]->size ?? 0);
            $tables = DB::select(
                'SELECT table_name as name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
                [$database]
            );
        }

        return [
            'driver' => $connection,
            'size' => $size,
            'tables' => count($tables),
        ];
    }

    // ──────────────────────────────────────────────
    // Table Helpers
    // ──────────────────────────────────────────────

    /** @return list<string> */
    public function getAvailableTables(): array
    {
        $connection = config('database.default');
        $excludedTables = config('filament-system-tools.excluded_tables', []);

        if ($connection === 'sqlite') {
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            $names = array_column($tables, 'name');
        } else {
            $database = config("database.connections.{$connection}.database");
            $tables = DB::select(
                'SELECT table_name as name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
                [$database]
            );
            $names = array_column($tables, 'name');
        }

        return array_values(array_filter($names, fn (string $name): bool => ! in_array($name, $excludedTables, true)));
    }

    /** @return array<string, string> */
    public function getConnectionOptions(): array
    {
        $connections = config('database.connections', []);

        $options = [];

        foreach (array_keys($connections) as $name) {
            $name = (string) $name;
            $driver = (string) config("database.connections.{$name}.driver");

            if (in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
                $options[$name] = "{$name} ({$driver})";
            }
        }

        return $options;
    }

    // ──────────────────────────────────────────────
    // Export
    // ──────────────────────────────────────────────

    private function exportAsSql(array $tables, string $timestamp): StreamedResponse
    {
        $tables = array_values(array_intersect($tables, $this->getAvailableTables()));
        $appName = config('filament-system-tools.app_name', config('app.name', 'Laravel'));
        $filename = strtolower(str_replace(' ', '_', $appName))."_export_{$timestamp}.sql";
        $connection = config('database.default');

        return response()->streamDownload(function () use ($tables, $connection, $appName): void {
            echo "-- {$appName} Database Export\n";
            echo '-- Date: '.date('Y-m-d H:i:s')."\n";
            echo "-- Driver: {$connection}\n";
            echo '-- Tables: '.implode(', ', $tables)."\n\n";

            if ($connection === 'sqlite') {
                echo "PRAGMA foreign_keys = OFF;\n\n";
            } else {
                echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";
            }

            echo "BEGIN TRANSACTION;\n\n";

            foreach ($tables as $table) {
                echo "-- ──────────────────────────────────────\n";
                echo "-- Table: {$table}\n";
                echo "-- ──────────────────────────────────────\n\n";

                if ($connection === 'sqlite') {
                    $ddl = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?", [$table]);
                    if ($ddl && $ddl->sql) {
                        $createSql = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $ddl->sql);
                        echo "{$createSql};\n\n";
                    }
                } else {
                    $escapedTable = str_replace('`', '``', $table);
                    $result = DB::selectOne("SHOW CREATE TABLE `{$escapedTable}`");
                    if ($result) {
                        $createKey = 'Create Table';
                        $createSql = $result->$createKey ?? '';
                        $createSql = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $createSql);
                        echo "{$createSql};\n\n";
                    }
                }

                $offset = 0;
                $chunkSize = 500;
                $pdo = DB::getPdo();

                while (true) {
                    $rows = DB::table($table)->offset($offset)->limit($chunkSize)->get();

                    if ($rows->isEmpty()) {
                        break;
                    }

                    foreach ($rows as $row) {
                        $values = [];
                        foreach ((array) $row as $value) {
                            if (is_null($value)) {
                                $values[] = 'NULL';
                            } elseif (is_numeric($value)) {
                                $values[] = $value;
                            } else {
                                $values[] = $pdo->quote((string) $value);
                            }
                        }
                        $columns = implode(', ', array_map(fn ($col) => "\"{$col}\"", array_keys((array) $row)));
                        echo "INSERT INTO \"{$table}\" ({$columns}) VALUES (".implode(', ', $values).");\n";
                    }

                    $offset += $chunkSize;
                }

                echo "\n";
            }

            echo "COMMIT;\n\n";

            if ($connection === 'sqlite') {
                echo "PRAGMA foreign_keys = ON;\n";
            } else {
                echo "SET FOREIGN_KEY_CHECKS = 1;\n";
            }
        }, $filename, [
            'Content-Type' => 'application/sql',
        ]);
    }

    private function exportAsJson(array $tables, string $timestamp): StreamedResponse
    {
        $tables = array_values(array_intersect($tables, $this->getAvailableTables()));
        $appName = config('filament-system-tools.app_name', config('app.name', 'Laravel'));
        $filename = strtolower(str_replace(' ', '_', $appName))."_export_{$timestamp}.json";

        return response()->streamDownload(function () use ($tables): void {
            $meta = [
                'exported_at' => now()->toIso8601String(),
                'driver' => config('database.default'),
                'tables' => $tables,
            ];

            // Stream row-by-row so large tables do not exhaust memory.
            echo '{"_meta":'.json_encode($meta, JSON_UNESCAPED_UNICODE);

            foreach ($tables as $table) {
                echo ','.json_encode($table, JSON_UNESCAPED_UNICODE).':[';

                $first = true;
                DB::table($table)->lazy(500)->each(function ($row) use (&$first): void {
                    echo ($first ? '' : ',').json_encode((array) $row, JSON_UNESCAPED_UNICODE);
                    $first = false;
                });

                echo ']';
            }

            echo '}';
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    // ──────────────────────────────────────────────
    // Import (uploaded .sql / .json files — not full backups)
    // ──────────────────────────────────────────────

    public function importFromFile(): void
    {
        if (! $this->canRestoreBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        if (! $this->importFile) {
            Notification::make()
                ->title(__('No file selected'))
                ->body(__('Please select a file to import.'))
                ->danger()
                ->send();

            return;
        }

        $extension = strtolower(pathinfo($this->importFile->getClientOriginalName(), PATHINFO_EXTENSION));

        if (! in_array($extension, ['sql', 'json'], true)) {
            Notification::make()
                ->title(__('Invalid file format'))
                ->body(__('Only .sql and .json files are supported.'))
                ->danger()
                ->send();

            $this->importFile = null;

            return;
        }

        $contents = file_get_contents($this->importFile->getRealPath());

        if (empty($contents)) {
            Notification::make()
                ->title(__('Empty file'))
                ->body(__('The uploaded file is empty.'))
                ->danger()
                ->send();

            $this->importFile = null;

            return;
        }

        try {
            if ($extension === 'json') {
                $result = $this->importJson($contents);
            } else {
                $result = $this->importSql($contents);
            }

            $skipped = $result['skipped'] ?? 0;

            $body = __(':tables tables imported, :rows total rows.', [
                'tables' => $result['tables'],
                'rows' => $result['rows'],
            ]);

            if ($skipped > 0) {
                $body .= ' '.__(':skipped row(s) skipped.', ['skipped' => $skipped]);

                if (! empty($result['errors'])) {
                    $body .= ' '.implode(' ', $result['errors']);
                }
            }

            Notification::make()
                ->title(__('Import completed!'))
                ->body($body)
                ->{$skipped > 0 ? 'warning' : 'success'}()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Import failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->importFile = null;
    }

    /** @return array{tables: int, rows: int, skipped: int, errors: list<string>} */
    private function importJson(string $contents): array
    {
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(__('Invalid JSON file: :error', ['error' => json_last_error_msg()]));
        }

        $tableCount = 0;
        $rowCount = 0;
        $skipped = 0;
        $errors = [];
        $maxErrors = 10;
        $driver = DB::connection()->getDriverName();

        // SQLite ignores PRAGMA foreign_keys inside a transaction, so constraint
        // state is set before the transaction opens and restored on every path.
        $this->disableForeignKeyChecks($driver);

        DB::beginTransaction();

        try {
            foreach ($data as $table => $rows) {
                if ($table === '_meta' || ! is_array($rows) || empty($rows)) {
                    continue;
                }

                $availableTables = $this->getAvailableTables();
                if (! in_array($table, $availableTables, true)) {
                    continue;
                }

                $tableCount++;

                foreach (array_chunk($rows, 500) as $chunk) {
                    foreach ($chunk as $row) {
                        try {
                            DB::table($table)->insert($row);
                            $rowCount++;
                        } catch (Throwable $e) {
                            $skipped++;
                            if (count($errors) < $maxErrors) {
                                $errors[] = "{$table}: ".$e->getMessage();
                            }
                        }
                    }
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $this->enableForeignKeyChecks($driver);
        }

        return ['tables' => $tableCount, 'rows' => $rowCount, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @return array{tables: int, rows: int} */
    private function importSql(string $contents): array
    {
        $tableCount = 0;
        $rowCount = 0;
        $driver = DB::connection()->getDriverName();

        $statements = $this->splitSqlStatements($contents);

        $this->disableForeignKeyChecks($driver);

        DB::beginTransaction();

        try {
            foreach ($statements as $statement) {
                $upper = strtoupper(trim($statement));

                if (in_array($upper, ['BEGIN TRANSACTION', 'COMMIT', 'BEGIN'], true)) {
                    continue;
                }

                DB::unprepared($statement.';');

                if (str_starts_with($upper, 'CREATE TABLE')) {
                    $tableCount++;
                } elseif (str_starts_with($upper, 'INSERT')) {
                    $rowCount++;
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            $this->enableForeignKeyChecks($driver);
        }

        return ['tables' => $tableCount, 'rows' => $rowCount];
    }

    /**
     * Constraint handling differs per driver; the connection's driver decides,
     * never the connection *name* (a connection called "primary" is not MySQL).
     */
    private function disableForeignKeyChecks(string $driver): void
    {
        match ($driver) {
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS = 0'),
            'pgsql' => DB::statement("SET session_replication_role = 'replica'"),
            default => null,
        };
    }

    private function enableForeignKeyChecks(string $driver): void
    {
        match ($driver) {
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS = 1'),
            'pgsql' => DB::statement("SET session_replication_role = 'origin'"),
            default => null,
        };
    }

    /**
     * Statement-aware SQL splitter. Tracks single/double-quote and backtick
     * state plus line and block comments so `;` characters inside quoted
     * literals do not split a statement mid-way (the naive explode(';') did,
     * corrupting any INSERT containing a semicolon).
     *
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        return SqlStatementSplitter::split($sql);
    }

    // ──────────────────────────────────────────────
    // Full Backup & Restore
    // ──────────────────────────────────────────────

    /** @return list<array{name: string, size: string, date: string, path: string}> */
    public function getBackupFiles(): array
    {
        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));

        if (! File::isDirectory($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        $files = File::files($backupPath);
        $backups = [];

        foreach ($files as $file) {
            // In-progress dumps are written as .part and only renamed once verified.
            if (str_ends_with($file->getFilename(), '.part')) {
                continue;
            }

            $backups[] = [
                'name' => $file->getFilename(),
                'size' => Bytes::format($file->getSize()),
                'date' => date('Y-m-d H:i:s', $file->getMTime()),
                'path' => $file->getPathname(),
            ];
        }

        usort($backups, fn ($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        return $backups;
    }

    public function canCreateBackup(): bool
    {
        return filament()->auth()->user()?->can('create_database_backup') ?? false;
    }

    public function canDownloadBackup(): bool
    {
        return filament()->auth()->user()?->can('download_database_backup') ?? false;
    }

    public function canRestoreBackup(): bool
    {
        return filament()->auth()->user()?->can('restore_database_backup') ?? false;
    }

    public function canDeleteBackup(): bool
    {
        return filament()->auth()->user()?->can('delete_database_backup') ?? false;
    }

    /**
     * Header action: open a modal to configure a new full backup.
     */
    public function createBackupAction(): Actions\Action
    {
        return Actions\Action::make('createBackup')
            ->label(__('Create Database Backup'))
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->visible(fn (): bool => $this->canCreateBackup())
            ->schema([
                Select::make('connection')
                    ->label(__('Connection'))
                    ->options(fn () => $this->getConnectionOptions())
                    ->default(config('database.default'))
                    ->required()
                    ->searchable()
                    ->live(),
                Toggle::make('gzip')
                    ->label(__('Gzip output (.sql.gz)'))
                    ->default(false)
                    ->helperText(__('Reduces file size — requires the gzip binary on PATH.')),
                Select::make('tables')
                    ->label(__('Tables (optional)'))
                    ->multiple()
                    ->searchable()
                    ->options(fn (callable $get) => $this->getTablesForConnection($get('connection')))
                    ->placeholder(__('Leave empty to back up all tables'))
                    ->helperText(__('Per-table filtering supported for SQLite and MySQL only.')),
            ])
            ->action(function (array $data): void {
                $this->createBackup(
                    connection: (string) ($data['connection'] ?? config('database.default')),
                    gzip: (bool) ($data['gzip'] ?? false),
                    tables: array_values(array_filter((array) ($data['tables'] ?? []))),
                );
            });
    }

    /** @return array<string, string> */
    private function getTablesForConnection(?string $connection): array
    {
        if (! $connection) {
            return [];
        }

        try {
            $driver = (string) config("database.connections.{$connection}.driver");

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $database = config("database.connections.{$connection}.database");
                $rows = DB::connection($connection)
                    ->table('information_schema.tables')
                    ->where('table_schema', $database)
                    ->pluck('TABLE_NAME', 'TABLE_NAME');

                return $rows->all();
            }

            $tables = DB::connection($connection)->getSchemaBuilder()->getTableListing();

            return collect($tables)->mapWithKeys(fn (string $t) => [$t => $t])->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Create a full backup. Defaults to the application's default connection.
     *
     * @param  list<string>  $tables
     */
    public function createBackup(?string $connection = null, bool $gzip = false, array $tables = []): void
    {
        if (! $this->canCreateBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $connection ??= (string) config('database.default');

        $backupPath = (string) config('filament-system-tools.backup_path', storage_path('app/backups'));

        if (! File::isDirectory($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        $driver = (string) config("database.connections.{$connection}.driver");
        $timestamp = date('Y-m-d_His');
        $extension = $gzip ? 'sql.gz' : 'sql';
        $filename = "backup-{$connection}-{$timestamp}.{$extension}";
        $filepath = $backupPath.DIRECTORY_SEPARATOR.$filename;

        try {
            // Fast path: SQLite, no gzip, no table filter — file copy needs no binary.
            if ($driver === 'sqlite' && ! $gzip && $tables === []) {
                $dbPath = (string) config("database.connections.{$connection}.database");

                if (! File::copy($dbPath, $filepath)) {
                    throw new \RuntimeException(__('The database file could not be copied.'));
                }

                clearstatcache(true, $filepath);

                if (! File::exists($filepath) || File::size($filepath) !== File::size($dbPath)) {
                    File::delete($filepath);

                    throw new \RuntimeException(__('The backup copy is incomplete and has been discarded.'));
                }
            } else {
                app(DatabaseSqlTool::class)->export(
                    connection: $connection,
                    path: $filepath,
                    gzip: $gzip,
                    tables: $tables,
                );
            }

            Notification::make()
                ->title(__('Database backup created'))
                ->body($filename)
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Backup failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Resolve a user-supplied backup filename to a real path that is provably
     * inside the backup directory AND present in the current listing. Returns
     * null for anything else — guards against path traversal and dropping in
     * files the app did not create.
     */
    private function resolveBackupPath(string $filename): ?string
    {
        $backupPath = (string) config('filament-system-tools.backup_path', storage_path('app/backups'));
        $baseReal = realpath($backupPath);

        if ($baseReal === false) {
            return null;
        }

        $real = realpath($backupPath.DIRECTORY_SEPARATOR.basename($filename));

        if ($real === false || ! str_starts_with($real, $baseReal.DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! in_array(basename($real), array_column($this->getBackupFiles(), 'name'), true)) {
            return null;
        }

        return $real;
    }

    public function downloadBackup(string $filename): BinaryFileResponse
    {
        abort_unless($this->canDownloadBackup(), 403);

        $filepath = $this->resolveBackupPath($filename);

        abort_if($filepath === null, 404);

        return response()->download($filepath);
    }

    /**
     * Stage a backup for restore. The operator still has to pick the target
     * connection and retype its name — a restore never runs off one click.
     */
    public function beginRestore(string $filename): void
    {
        if (! $this->canRestoreBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        if ($this->resolveBackupPath($filename) === null) {
            Notification::make()
                ->title(__('Backup not found'))
                ->danger()
                ->send();

            return;
        }

        $this->restoreFile = basename($filename);
        $this->restoreConnection = $this->detectConnectionFromFilename($this->restoreFile);
        $this->restoreConfirmation = '';
    }

    public function cancelRestore(): void
    {
        $this->restoreFile = null;
        $this->restoreConnection = null;
        $this->restoreConfirmation = '';
    }

    /**
     * Restore the staged backup into the explicitly chosen target connection.
     * The target is never inferred: an unnamed or unconfirmed target aborts.
     */
    public function restoreBackup(): void
    {
        if (! $this->canRestoreBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $filename = $this->restoreFile;
        $filepath = $filename !== null ? $this->resolveBackupPath($filename) : null;

        if ($filename === null || $filepath === null) {
            Notification::make()
                ->title(__('Backup not found'))
                ->danger()
                ->send();

            return;
        }

        $connection = $this->restoreConnection;

        if ($connection === null || ! array_key_exists($connection, $this->getConnectionOptions())) {
            Notification::make()
                ->title(__('Select a target database'))
                ->body(__('Choose the connection this backup should be restored into.'))
                ->danger()
                ->send();

            return;
        }

        if (trim($this->restoreConfirmation) !== $connection) {
            Notification::make()
                ->title(__('Confirmation does not match'))
                ->body(__('Type :connection to confirm the restore target.', ['connection' => $connection]))
                ->danger()
                ->send();

            return;
        }

        $driver = (string) config("database.connections.{$connection}.driver");
        $isGzipped = str_ends_with($filename, '.gz');

        try {
            // Fast path: raw SQLite database file (legacy backup format) — file copy.
            if ($driver === 'sqlite' && ! $isGzipped && $this->looksLikeSqliteBinary($filepath)) {
                $dbPath = (string) config("database.connections.{$connection}.database");

                if (! File::copy($filepath, $dbPath)) {
                    throw new \RuntimeException(__('The database file could not be replaced.'));
                }

                clearstatcache(true, $dbPath);

                if (! File::exists($dbPath) || File::size($dbPath) !== File::size($filepath)) {
                    throw new \RuntimeException(__('The restored database file does not match the backup.'));
                }
            } else {
                app(DatabaseSqlTool::class)->import(
                    path: $filepath,
                    connection: $connection,
                );
            }

            Notification::make()
                ->title(__('Backup restored'))
                ->body(__('Database :connection has been restored from :file', [
                    'connection' => $connection,
                    'file' => basename($filename),
                ]))
                ->success()
                ->send();

            $this->cancelRestore();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Restore failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Backup filenames produced by createBackup() embed the connection name as
     * `backup-{connection}-{timestamp}.{ext}`. It only pre-selects the target in
     * the restore form — the operator still confirms it.
     */
    private function detectConnectionFromFilename(string $filename): ?string
    {
        if (! preg_match('/^backup-(?<conn>[^-]+(?:-[^-]+)*?)-\d{4}-\d{2}-\d{2}_\d{6}\.(sql|sql\.gz)$/', $filename, $m)) {
            return null;
        }

        $conn = $m['conn'];

        return config("database.connections.{$conn}") ? $conn : null;
    }

    private function looksLikeSqliteBinary(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $header = (string) fread($handle, 16);
        fclose($handle);

        // SQLite 3 file header magic string
        return str_starts_with($header, 'SQLite format 3');
    }

    public function deleteBackup(string $filename): void
    {
        if (! $this->canDeleteBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $filepath = $this->resolveBackupPath($filename);

        if ($filepath === null) {
            Notification::make()
                ->title(__('Backup not found'))
                ->danger()
                ->send();

            return;
        }

        File::delete($filepath);

        Notification::make()
            ->title(__('Backup deleted!'))
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->createBackupAction(),
        ];
    }
}
