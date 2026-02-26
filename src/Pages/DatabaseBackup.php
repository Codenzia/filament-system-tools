<?php

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Models\DatabaseTable;
use Filament\Actions;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackup extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 101;

    protected static ?string $title = 'Database & Backups';

    protected static ?string $slug = 'system/backups';

    protected string $view = 'filament-system-tools::pages.database-backup';

    public $importFile = null;

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
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
                    ->deselectRecordsAfterCompletion()
                    ->action(fn (Collection $records): StreamedResponse => $this->exportAsSql(
                        $records->pluck('name')->toArray(),
                        date('Y-m-d_His')
                    )),
                Actions\BulkAction::make('exportJson')
                    ->label(__('Export JSON'))
                    ->icon('heroicon-o-code-bracket')
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

    // ──────────────────────────────────────────────
    // Export
    // ──────────────────────────────────────────────

    private function exportAsSql(array $tables, string $timestamp): StreamedResponse
    {
        $appName = config('filament-system-tools.app_name', config('app.name', 'Laravel'));
        $filename = strtolower(str_replace(' ', '_', $appName)) . "_export_{$timestamp}.sql";
        $connection = config('database.default');

        return response()->streamDownload(function () use ($tables, $connection, $appName): void {
            echo "-- {$appName} Database Export\n";
            echo '-- Date: ' . date('Y-m-d H:i:s') . "\n";
            echo "-- Driver: {$connection}\n";
            echo '-- Tables: ' . implode(', ', $tables) . "\n\n";

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

                // DDL
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

                // Data — chunked for large tables
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
                        echo "INSERT INTO \"{$table}\" ({$columns}) VALUES (" . implode(', ', $values) . ");\n";
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
        $filename = strtolower(str_replace(' ', '_', $appName)) . "_export_{$timestamp}.json";

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
    // Import
    // ──────────────────────────────────────────────

    public function importFromFile(): void
    {
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
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Import failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->importFile = null;
    }

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

                // Verify the table exists before importing
                $availableTables = $this->getAvailableTables();
                if (! in_array($table, $availableTables, true)) {
                    continue;
                }

                $tableCount++;

                // Insert in chunks
                foreach (array_chunk($rows, 500) as $chunk) {
                    foreach ($chunk as $row) {
                        try {
                            DB::table($table)->insert($row);
                            $rowCount++;
                        } catch (\Throwable) {
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
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['tables' => $tableCount, 'rows' => $rowCount];
    }

    private function importSql(string $contents): array
    {
        $tableCount = 0;
        $rowCount = 0;

        // Remove comment lines
        $lines = explode("\n", $contents);
        $cleanLines = array_filter($lines, fn (string $line): bool => ! str_starts_with(trim($line), '--'));
        $sql = implode("\n", $cleanLines);

        // Split into statements
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn (string $s): bool => ! empty($s)
        );

        DB::beginTransaction();

        try {
            foreach ($statements as $statement) {
                $upper = strtoupper(trim($statement));

                // Skip transaction control — we handle our own
                if (in_array($upper, ['BEGIN TRANSACTION', 'COMMIT', 'BEGIN'], true)) {
                    continue;
                }

                DB::unprepared($statement . ';');

                if (str_starts_with($upper, 'CREATE TABLE')) {
                    $tableCount++;
                } elseif (str_starts_with($upper, 'INSERT')) {
                    $rowCount++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['tables' => $tableCount, 'rows' => $rowCount];
    }

    // ──────────────────────────────────────────────
    // Full Backup & Restore
    // ──────────────────────────────────────────────

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

        // Sort by date descending
        usort($backups, fn ($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        return $backups;
    }

    public function createBackup(): void
    {
        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));

        if (! File::isDirectory($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }

        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backupPath . '/' . $filename;

        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            File::copy($dbPath, $filepath);

            Notification::make()
                ->title(__('Database backup created!'))
                ->body($filename)
                ->success()
                ->send();
        } else {
            // For MySQL, use mysqldump
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port");
            $database = config("database.connections.{$connection}.database");
            $username = config("database.connections.{$connection}.username");
            $password = config("database.connections.{$connection}.password");

            $command = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($password),
                escapeshellarg($database),
                escapeshellarg($filepath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode === 0) {
                Notification::make()
                    ->title(__('Database backup created!'))
                    ->body($filename)
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title(__('Backup failed'))
                    ->body(__('Could not create database backup. Check server configuration.'))
                    ->danger()
                    ->send();
            }
        }
    }

    public function downloadBackup(string $filename): StreamedResponse
    {
        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath . '/' . basename($filename);

        return response()->download($filepath);
    }

    public function restoreBackup(string $filename): void
    {
        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath . '/' . basename($filename);

        if (! File::exists($filepath)) {
            Notification::make()
                ->title(__('Backup not found'))
                ->danger()
                ->send();

            return;
        }

        $connection = config('database.default');

        try {
            if ($connection === 'sqlite') {
                $dbPath = config('database.connections.sqlite.database');
                File::copy($filepath, $dbPath);
            } else {
                $contents = File::get($filepath);
                DB::unprepared($contents);
            }

            Notification::make()
                ->title(__('Backup restored!'))
                ->body(__('Database has been restored from :file', ['file' => basename($filename)]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Restore failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function deleteBackup(string $filename): void
    {
        $backupPath = config('filament-system-tools.backup_path', storage_path('app/backups'));
        $filepath = $backupPath . '/' . basename($filename);

        if (File::exists($filepath)) {
            File::delete($filepath);

            Notification::make()
                ->title(__('Backup deleted!'))
                ->success()
                ->send();
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
