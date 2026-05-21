<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaDiffer;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaIntrospector;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartExporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartImporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\TableSorter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SmartDataMigration extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 102;

    protected static ?string $slug = 'system/smart-migration';

    protected string $view = 'filament-system-tools::pages.smart-data-migration';

    /** Wizard step: upload → analysis → configure → importing → complete */
    public string $step = 'upload';

    /** Path to the temp file holding the parsed export payload. */
    public ?string $tempFilePath = null;

    /** @var array{total_tables?: int, matched?: int, partial?: int, skipped?: int} */
    public array $diffSummary = [];

    /** @var array<string, array{status: string, matched: int, dropped: list<string>, added: list<string>, type_mismatches: array<string, array{exported: string, current: string}>, suggested_renames: array<string, string>}> */
    public array $diffTables = [];

    /** @var list<string> */
    public array $skippedTables = [];

    /** @var array<string, array<string, string>> table => [old_col => new_col] */
    public array $columnMappings = [];

    /** @var list<string> */
    public array $selectedTables = [];

    public bool $preserveTimestamps = true;

    public bool $applyScope = true;

    /** @var 'skip'|'update' */
    public string $onDuplicate = 'skip';

    /** @var array<string, array{status: string, imported: int, skipped: int, errors: list<string>}> */
    public array $importProgress = [];

    /** @var array{total_imported?: int, total_skipped?: int, tables_imported?: int, errors?: int, warnings?: int} */
    public array $importSummary = [];

    /** @var array<string, int> */
    public array $importDetails = [];

    /** @var list<string> */
    public array $importErrors = [];

    /** @var list<string> */
    public array $importWarnings = [];

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Smart Data Migration');
    }

    public function getTitle(): string
    {
        return __('Smart Data Migration');
    }

    public function handleUpload(string $fileContent, string $fileName): void
    {
        try {
            $content = base64_decode($fileContent, true);
            if ($content === false) {
                throw new \RuntimeException(__('Could not decode the uploaded file.'));
            }

            $data = json_decode($content, true);

            if (! is_array($data)) {
                throw new \RuntimeException(__('Invalid JSON file.'));
            }

            if (! isset($data['_meta'])) {
                Notification::make()
                    ->title(__('Legacy format detected'))
                    ->body(__('This file is not a Smart Export. Use the standard import on the Database & Backups page, or re-export with the Smart Export button.'))
                    ->warning()
                    ->send();

                return;
            }

            if ((int) ($data['_meta']['version'] ?? 0) < 2) {
                throw new \RuntimeException(__('Unsupported export version. Please re-export with the Smart Export feature.'));
            }

            $tempPath = storage_path('app/smart_migration_'.now()->format('YmdHis').'_'.bin2hex(random_bytes(4)).'.json');
            file_put_contents($tempPath, $content);
            $this->tempFilePath = $tempPath;

            $this->analyzeSchema($data);

            $this->step = 'analysis';
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Upload failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $exportData
     */
    private function analyzeSchema(array $exportData): void
    {
        $introspector = new SchemaIntrospector;
        $differ = new SchemaDiffer;

        $currentSchema = $introspector->getTablesSchema();

        $normalizedCurrentSchema = [];
        foreach ($currentSchema as $table => $info) {
            $stripped = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            $normalizedCurrentSchema[$stripped] = $info;
        }

        $diff = $differ->diff($exportData['_schema'] ?? [], $normalizedCurrentSchema);

        $this->diffSummary = $diff->getSummary();
        $this->skippedTables = $diff->skippedTables;

        $tables = [];
        foreach ($diff->tables as $table => $info) {
            $tables[$table] = [
                'status' => $diff->getTableStatus($table),
                'matched' => count($info['matched']),
                'dropped' => $info['dropped'],
                'added' => $info['added'],
                'type_mismatches' => $info['type_mismatches'],
                'suggested_renames' => $info['suggested_renames'],
            ];
        }
        $this->diffTables = $tables;

        $this->selectedTables = $diff->getImportableTables();

        $mappings = [];
        foreach ($diff->tables as $table => $info) {
            if (! empty($info['suggested_renames'])) {
                $mappings[$table] = $info['suggested_renames'];
            }
        }
        $this->columnMappings = $mappings;
    }

    public function proceedToConfigure(): void
    {
        $this->step = 'configure';
    }

    public function backToAnalysis(): void
    {
        $this->step = 'analysis';
    }

    public function acceptRename(string $table, string $oldColumn, string $newColumn): void
    {
        $this->columnMappings[$table][$oldColumn] = $newColumn;
    }

    public function rejectRename(string $table, string $oldColumn): void
    {
        unset($this->columnMappings[$table][$oldColumn]);

        if (empty($this->columnMappings[$table])) {
            unset($this->columnMappings[$table]);
        }
    }

    public function toggleTable(string $table): void
    {
        if (in_array($table, $this->selectedTables, true)) {
            $this->selectedTables = array_values(array_diff($this->selectedTables, [$table]));
        } else {
            $this->selectedTables[] = $table;
        }
    }

    public function selectAllTables(): void
    {
        $this->selectedTables = array_keys($this->diffTables);
    }

    public function deselectAllTables(): void
    {
        $this->selectedTables = [];
    }

    public function canRunImport(): bool
    {
        return filament()->auth()->user()?->can('run_data_import') ?? false;
    }

    public function canSmartExport(): bool
    {
        return filament()->auth()->user()?->can('download_database_backup') ?? false;
    }

    public function runImport(): void
    {
        if (! $this->canRunImport()) {
            Notification::make()
                ->title(__('Unauthorized'))
                ->danger()
                ->send();

            return;
        }

        try {
            if (! $this->tempFilePath || ! file_exists($this->tempFilePath)) {
                throw new \RuntimeException(__('Export file not found. Please re-upload.'));
            }

            $introspector = new SchemaIntrospector;
            $tableSorter = new TableSorter;

            $currentSchema = $introspector->getTablesSchema();
            $normalizedSchema = [];
            foreach ($currentSchema as $table => $info) {
                $stripped = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
                $normalizedSchema[$stripped] = $info;
            }

            $sortedTables = $tableSorter->sort($normalizedSchema, $this->selectedTables);

            $this->importProgress = [];
            foreach ($sortedTables as $table) {
                $this->importProgress[$table] = [
                    'status' => 'pending',
                    'imported' => 0,
                    'skipped' => 0,
                    'errors' => [],
                ];
            }

            $this->importSummary = [];
            $this->importDetails = [];
            $this->importErrors = [];
            $this->importWarnings = [];

            $this->step = 'importing';

            $this->processImport();
        } catch (\Throwable $e) {
            $this->importErrors = [$e->getMessage()];
            $this->step = 'complete';
            Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->send();
        }
    }

    private function processImport(): void
    {
        try {
            $exportData = json_decode((string) file_get_contents($this->tempFilePath), true);
            if (! is_array($exportData)) {
                throw new \RuntimeException(__('Could not parse the temporary export file.'));
            }

            $importer = new SmartImporter(new SchemaIntrospector, new TableSorter);

            $result = $importer->import(
                $exportData,
                $this->columnMappings,
                [
                    'scope' => $this->applyScope ? $this->resolveScope() : null,
                    'preserve_timestamps' => $this->preserveTimestamps,
                    'on_duplicate' => $this->onDuplicate,
                    'tables' => $this->selectedTables,
                ],
                function (string $table, string $status, int $imported, int $skipped, array $errors): void {
                    $this->importProgress[$table] = [
                        'status' => $status,
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'errors' => $errors,
                    ];
                },
            );

            $this->importSummary = $result->getSummary();
            $this->importDetails = $result->importedCounts;
            $this->importErrors = $result->errors;
            $this->importWarnings = $result->warnings;

            $this->cleanupTempFile();
            $this->step = 'complete';

            $message = $result->hasErrors()
                ? __(':count records imported, :errors errors', [
                    'count' => $result->getTotalImported(),
                    'errors' => count($result->errors),
                ])
                : __(':count records imported across :tables tables', [
                    'count' => $result->getTotalImported(),
                    'tables' => $result->getTablesImported(),
                ]);

            Notification::make()
                ->title($result->hasErrors() ? __('Import completed with errors') : __('Import completed successfully'))
                ->body($message)
                ->{$result->hasErrors() ? 'warning' : 'success'}()
                ->send();
        } catch (\Throwable $e) {
            $this->importErrors = [$e->getMessage()];
            $this->step = 'complete';
            Notification::make()->title(__('Import failed'))->body($e->getMessage())->danger()->send();
        }
    }

    public function smartExport(): StreamedResponse
    {
        abort_unless($this->canSmartExport(), 403);

        $exporter = new SmartExporter(new SchemaIntrospector);

        $data = $exporter->export($this->resolveScope());
        $timestamp = now()->format('Y-m-d_His');

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            "smart_export_{$timestamp}.json",
            ['Content-Type' => 'application/json'],
        );
    }

    public function isStepCompleted(string $checkStep): bool
    {
        $stepOrder = ['upload', 'analysis', 'configure', 'importing', 'complete'];
        $currentIndex = array_search($this->step, $stepOrder, true);
        $checkIndex = array_search($checkStep, $stepOrder, true);

        if ($currentIndex === false || $checkIndex === false) {
            return false;
        }

        return $checkIndex < $currentIndex;
    }

    public function resetWizard(): void
    {
        $this->cleanupTempFile();

        $this->step = 'upload';
        $this->diffSummary = [];
        $this->diffTables = [];
        $this->skippedTables = [];
        $this->columnMappings = [];
        $this->selectedTables = [];
        $this->onDuplicate = 'skip';
        $this->importProgress = [];
        $this->importSummary = [];
        $this->importDetails = [];
        $this->importErrors = [];
        $this->importWarnings = [];
    }

    /**
     * Resolve the row-level scope for export/import. Reads
     * `filament-system-tools.smart_migration.scope_resolver` — a callable that
     * returns `['column' => 'team_id', 'value' => 1]` or null. When unset,
     * Smart Migration operates on the full database without scoping.
     *
     * @return array{column: string, value: int|string}|null
     */
    private function resolveScope(): ?array
    {
        $resolver = config('filament-system-tools.smart_migration.scope_resolver');

        if (is_callable($resolver)) {
            $scope = $resolver();

            if (is_array($scope) && isset($scope['column'], $scope['value'])) {
                return [
                    'column' => (string) $scope['column'],
                    'value' => $scope['value'],
                ];
            }
        }

        return null;
    }

    private function cleanupTempFile(): void
    {
        if ($this->tempFilePath && file_exists($this->tempFilePath)) {
            @unlink($this->tempFilePath);
        }

        $this->tempFilePath = null;
    }
}
