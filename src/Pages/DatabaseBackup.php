<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Models\DatabaseTable;
use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;
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

    protected static ?string $slug = 'system/backups';

    protected string $view = 'filament-system-tools::pages.database-backup';

    public mixed $importFile = null;

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
                    ->state(fn ($record): string => $this->getTableSize($record->name))
                    ->sortable()
                    ->alignEnd(),
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('viewSchema')
                        ->label(__('View Schema'))
                        ->icon('heroicon-o-table-cells')
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
            $count = DB::table($table)->count();

            return $this->formatBytes($count * 200);
        }

        $database = config("database.connections.{$connection}.database");
        $result = DB::select(
            'SELECT (data_length + index_length) as size FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$database, $table]
        );

        return $this->formatBytes($result[0]->size ?? 0);
    }

    // ──────────────────────────────────────────────
    // Database Stats
    // ──────────────────────────────────────────────

    public function getDatabaseStats(): array
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            $size = File::exists($dbPath) ? $this->formatBytes(File::size($dbPath)) : 'N/A';
            $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        } else {
            $database = config("database.connections.{$connection}.database");
            $result = DB::select(
                'SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = ?',
                [$database]
            );
            $size = $this->formatBytes($result[0]->size ?? 0);
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
                    $result = DB::selectOne("SHOW CREATE TABLE `{$table}`");
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
        $appName = config('filament-system-tools.app_name', config('app.name', 'Laravel'));
        $filename = strtolower(str_replace(' ', '_', $appName))."_export_{$timestamp}.json";

        return response()->streamDownload(function () use ($tables): void {
            $data = [
                '_meta' => [
                    'exported_at' => now()->toIso8601String(),
                    'driver' => config('database.default'),
                    'tables' => $tables,
                ],
            ];

            foreach ($tables as $table) {
                $data[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->toArray();
            }

            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

            Notification::make()
                ->title(__('Import completed!'))
                ->body(__(':tables tables imported, :rows total rows.', [
                    'tables' => $result['tables'],
                    'rows' => $result['rows'],
                ]))
                ->success()
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

    /** @return array{tables: int, rows: int} */
    private function importJson(string $contents): array
    {
        $data = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(__('Invalid JSON file: :error', ['error' => json_last_error_msg()]));
        }

        $tableCount = 0;
        $rowCount = 0;
        $connection = config('database.default');

        DB::beginTransaction();

        try {
            if ($connection === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            }

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
                        } catch (Throwable) {
                            // Skip duplicate rows
                        }
                    }
                }
            }

            if ($connection === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            } else {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['tables' => $tableCount, 'rows' => $rowCount];
    }

    /** @return array{tables: int, rows: int} */
    private function importSql(string $contents): array
    {
        $tableCount = 0;
        $rowCount = 0;

        $lines = explode("\n", $contents);
        $cleanLines = array_filter($lines, fn (string $line): bool => ! str_starts_with(trim($line), '--'));
        $sql = implode("\n", $cleanLines);

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn (string $s): bool => ! empty($s)
        );

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
        }

        return ['tables' => $tableCount, 'rows' => $rowCount];
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
            $backups[] = [
                'name' => $file->getFilename(),
                'size' => $this->formatBytes($file->getSize()),
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
                File::copy($dbPath, $filepath);
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

    public function downloadBackup(string $filename): BinaryFileResponse
    {
        abort_unless($this->canDownloadBackup(), 403);

        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath.DIRECTORY_SEPARATOR.basename($filename);

        return response()->download($filepath);
    }

    public function restoreBackup(string $filename): void
    {
        if (! $this->canRestoreBackup()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        $backupPath = (string) config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath.DIRECTORY_SEPARATOR.basename($filename);

        if (! File::exists($filepath)) {
            Notification::make()
                ->title(__('Backup not found'))
                ->danger()
                ->send();

            return;
        }

        $connection = $this->detectConnectionFromFilename($filename) ?? (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $isGzipped = str_ends_with($filename, '.gz');

        try {
            // Fast path: raw SQLite database file (legacy backup format) — file copy.
            if ($driver === 'sqlite' && ! $isGzipped && $this->looksLikeSqliteBinary($filepath)) {
                $dbPath = (string) config("database.connections.{$connection}.database");
                File::copy($filepath, $dbPath);
            } else {
                app(DatabaseSqlTool::class)->import(
                    path: $filepath,
                    connection: $connection,
                );
            }

            Notification::make()
                ->title(__('Backup restored'))
                ->body(__('Database has been restored from :file', ['file' => basename($filename)]))
                ->success()
                ->send();
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
     * `backup-{connection}-{timestamp}.{ext}`. Recover it so restore picks the
     * right driver even when the user has changed default connection since.
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

        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath.DIRECTORY_SEPARATOR.basename($filename);

        if (File::exists($filepath)) {
            File::delete($filepath);

            Notification::make()
                ->title(__('Backup deleted!'))
                ->success()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->createBackupAction(),
        ];
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        for (; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
