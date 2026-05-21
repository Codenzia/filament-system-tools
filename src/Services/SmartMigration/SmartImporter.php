<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import a v2 Smart Export payload, applying user-supplied column renames,
 * remapping foreign keys to newly-assigned auto-increment IDs, deferring
 * self-references and circular-FK updates, and emitting per-table progress
 * via an optional callback.
 */
class SmartImporter
{
    private const BATCH_SIZE = 500;

    private const MAX_ROW_ERRORS_PER_TABLE = 100;

    private IdRemapper $idRemapper;

    public function __construct(
        private readonly SchemaIntrospector $introspector,
        private readonly TableSorter $tableSorter,
    ) {
        $this->idRemapper = new IdRemapper;
    }

    /**
     * @param  array{_meta: array<string, mixed>, _schema: array<string, mixed>, _data: array<string, list<array<string, mixed>>>}  $exportData
     * @param  array<string, array<string, string>>  $columnMappings  table => [old_column => new_column]
     * @param  array{scope?: array{column: string, value: int|string}|null, preserve_timestamps?: bool, tables?: list<string>, on_duplicate?: string}  $options
     * @param  (\Closure(string $table, string $status, int $imported, int $skipped, list<string> $errors): void)|null  $onProgress
     */
    public function import(
        array $exportData,
        array $columnMappings = [],
        array $options = [],
        ?\Closure $onProgress = null,
    ): ImportResult {
        $this->idRemapper->reset();

        $exportedSchema = $exportData['_schema'] ?? [];
        $exportedData = $exportData['_data'] ?? [];
        $currentSchema = $this->introspector->getTablesSchema();

        $normalizedCurrentSchema = [];
        foreach ($currentSchema as $table => $info) {
            $normalizedCurrentSchema[$this->stripSchemaPrefix($table)] = $info;
        }

        $tablesToImport = $options['tables'] ?? array_keys($exportedData);
        $tablesToImport = array_values(array_filter(
            $tablesToImport,
            fn (string $t): bool => isset($exportedData[$t]) && isset($normalizedCurrentSchema[$t]),
        ));

        $sortedTables = $this->tableSorter->sort($normalizedCurrentSchema, $tablesToImport);

        $importedCounts = [];
        $skippedCounts = [];
        $errors = [];
        $warnings = [];

        /** @var list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string}> */
        $deferredUpdates = [];

        /** @var array<string, true> */
        $importedTables = [];

        $driver = DB::getDriverName();

        $this->disableForeignKeyChecks($driver);
        DB::beginTransaction();

        try {
            foreach ($sortedTables as $table) {
                $rows = $exportedData[$table] ?? [];
                if (empty($rows)) {
                    $importedTables[$table] = true;
                    $importedCounts[$table] = 0;
                    $skippedCounts[$table] = 0;
                    $onProgress?->call($this, $table, 'success', 0, 0, []);

                    continue;
                }

                $onProgress?->call($this, $table, 'importing', 0, 0, []);

                $tableResult = $this->importTable(
                    $table,
                    $rows,
                    $normalizedCurrentSchema[$table] ?? [],
                    $exportedSchema[$table] ?? [],
                    $columnMappings[$table] ?? [],
                    $options,
                    $deferredUpdates,
                    $warnings,
                    $importedTables,
                );

                $importedTables[$table] = true;

                $importedCounts[$table] = $tableResult['imported'];
                $skippedCounts[$table] = $tableResult['skipped'];

                if (! empty($tableResult['errors'])) {
                    array_push($errors, ...$tableResult['errors']);
                }

                $status = empty($tableResult['errors'])
                    ? 'success'
                    : ($tableResult['imported'] > 0 ? 'partial' : 'failed');

                $onProgress?->call(
                    $this,
                    $table,
                    $status,
                    $tableResult['imported'],
                    $tableResult['skipped'],
                    $tableResult['errors'],
                );
            }

            $this->processDeferredUpdates($deferredUpdates, $warnings);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = "Import aborted: {$e->getMessage()}";
            Log::error('SmartImporter failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->enableForeignKeyChecks($driver);
        }

        return new ImportResult($importedCounts, $skippedCounts, $errors, $warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{columns?: array<string, mixed>, foreign_keys?: array<string, array{references: string, on: string}>}  $currentTableSchema
     * @param  array{columns?: array<string, mixed>, foreign_keys?: array<string, array{references: string, on: string}>}  $exportedTableSchema
     * @param  array<string, string>  $columnMapping
     * @param  array<string, mixed>  $options
     * @param  list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string}>  $deferredUpdates
     * @param  list<string>  $warnings
     * @param  array<string, true>  $importedTables
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    private function importTable(
        string $table,
        array $rows,
        array $currentTableSchema,
        array $exportedTableSchema,
        array $columnMapping,
        array $options,
        array &$deferredUpdates,
        array &$warnings,
        array $importedTables = [],
    ): array {
        $imported = 0;
        $skipped = 0;
        $duplicates = 0;
        $updated = 0;
        $errors = [];

        $currentColumns = array_keys($currentTableSchema['columns'] ?? []);
        $foreignKeys = $currentTableSchema['foreign_keys'] ?? [];
        $selfRefColumns = $this->tableSorter->getSelfReferences($table, $foreignKeys);
        $hasPrimaryId = in_array('id', $currentColumns, true);
        $isAutoIncrement = $hasPrimaryId && $this->introspector->isAutoIncrementId($table);
        $isPivot = ! $hasPrimaryId;
        $preserveTimestamps = $options['preserve_timestamps'] ?? true;
        $onDuplicate = $options['on_duplicate'] ?? 'skip';

        /** @var array{column: string, value: int|string}|null $scope */
        $scope = $options['scope'] ?? null;
        $scopeColumn = $scope['column'] ?? null;
        $scopeValue = $scope['value'] ?? null;
        $applyScope = $scopeColumn !== null && in_array($scopeColumn, $currentColumns, true);

        $uniqueIndexes = $this->introspector->getUniqueIndexes($table);

        foreach ($rows as $row) {
            $oldId = $row['id'] ?? null;

            try {
                $row = $this->applyColumnMappings($row, $columnMapping);

                if ($isAutoIncrement) {
                    unset($row['id']);
                }

                if (! $preserveTimestamps) {
                    unset($row['created_at'], $row['updated_at']);
                }

                $row = array_intersect_key($row, array_flip($currentColumns));

                if ($isAutoIncrement) {
                    unset($row['id']);
                }

                if ($applyScope) {
                    $row[$scopeColumn] = $scopeValue;
                }

                $skipRow = false;
                /** @var list<array{column: string, old_value: int, referenced_table: string}> */
                $circularDeferrals = [];

                foreach ($foreignKeys as $fkColumn => $fkDef) {
                    if (! isset($row[$fkColumn]) || $row[$fkColumn] === null) {
                        continue;
                    }

                    if ($applyScope && $fkColumn === $scopeColumn) {
                        continue;
                    }

                    $referencedTable = $this->stripSchemaPrefix($fkDef['on']);
                    $oldFkValue = (int) $row[$fkColumn];

                    if (in_array($fkColumn, $selfRefColumns, true)) {
                        $row[$fkColumn] = null;

                        continue;
                    }

                    $newFkValue = $this->idRemapper->resolve($referencedTable, $oldFkValue);
                    $row[$fkColumn] = $newFkValue;

                    if ($newFkValue !== null) {
                        continue;
                    }

                    $columnInfo = $currentTableSchema['columns'][$fkColumn] ?? null;
                    $isNotNull = $columnInfo && ! ($columnInfo['nullable'] ?? false);
                    $targetNotYetImported = ! isset($importedTables[$referencedTable]);

                    if ($isNotNull && $targetNotYetImported) {
                        $row[$fkColumn] = $oldFkValue;
                        $circularDeferrals[] = [
                            'column' => $fkColumn,
                            'old_value' => $oldFkValue,
                            'referenced_table' => $referencedTable,
                        ];
                    } elseif ($isNotNull) {
                        $skipRow = true;
                        break;
                    }
                }

                if ($skipRow) {
                    $skipped++;

                    continue;
                }

                $existingId = $this->findExistingByUniqueKey($table, $row, $uniqueIndexes)
                    ?? ($oldId !== null && $hasPrimaryId ? $this->findExistingById($table, (int) $oldId) : null);

                if ($existingId !== null) {
                    if ($oldId !== null) {
                        $this->idRemapper->record($table, (int) $oldId, (int) $existingId);
                    }

                    if ($onDuplicate === 'update') {
                        $updateData = $row;
                        unset($updateData['id']);
                        DB::table($table)->where('id', $existingId)->update($updateData);
                        $updated++;
                    } else {
                        $duplicates++;
                    }

                    continue;
                }

                if ($isPivot) {
                    $inserted = DB::table($table)->insertOrIgnore($row);
                    if ($inserted) {
                        $imported++;
                    } else {
                        $duplicates++;
                    }
                } elseif ($isAutoIncrement) {
                    $newId = DB::table($table)->insertGetId($row);

                    if ($oldId !== null) {
                        $this->idRemapper->record($table, (int) $oldId, (int) $newId);

                        foreach ($selfRefColumns as $selfRefCol) {
                            $originalFkValue = $this->getOriginalFkValue($selfRefCol, $columnMapping, $rows, (int) $oldId);
                            if ($originalFkValue !== null) {
                                $deferredUpdates[] = [
                                    'table' => $table,
                                    'new_id' => (int) $newId,
                                    'column' => $selfRefCol,
                                    'old_fk_value' => (int) $originalFkValue,
                                ];
                            }
                        }

                        foreach ($circularDeferrals as $deferral) {
                            $deferredUpdates[] = [
                                'table' => $table,
                                'new_id' => (int) $newId,
                                'column' => $deferral['column'],
                                'old_fk_value' => $deferral['old_value'],
                                'referenced_table' => $deferral['referenced_table'],
                            ];
                        }
                    }

                    $imported++;
                } else {
                    $inserted = DB::table($table)->insertOrIgnore($row);

                    if ($inserted) {
                        $imported++;
                    } else {
                        $duplicates++;
                    }
                }
            } catch (\Throwable $e) {
                $skipped++;
                $identifier = $oldId ?? 'unknown';
                $errors[] = "{$table}[{$identifier}]: {$e->getMessage()}";

                if ($skipped > self::MAX_ROW_ERRORS_PER_TABLE) {
                    $errors[] = "{$table}: Too many errors, stopping import for this table.";
                    break;
                }
            }
        }

        if ($duplicates > 0) {
            $warnings[] = "{$table}: {$duplicates} duplicate records skipped";
        }
        if ($updated > 0) {
            $warnings[] = "{$table}: {$updated} existing records updated";
        }

        return [
            'imported' => $imported + $updated,
            'skipped' => $skipped + $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<list<string>>  $uniqueIndexes
     */
    private function findExistingByUniqueKey(string $table, array $row, array $uniqueIndexes): int|string|null
    {
        foreach ($uniqueIndexes as $indexColumns) {
            $query = DB::table($table);
            $allColumnsPresent = true;

            foreach ($indexColumns as $col) {
                if (! array_key_exists($col, $row) || $row[$col] === null) {
                    $allColumnsPresent = false;
                    break;
                }
                $query->where($col, $row[$col]);
            }

            if (! $allColumnsPresent) {
                continue;
            }

            $existing = $query->first();
            if ($existing !== null) {
                return $existing->id ?? null;
            }
        }

        return null;
    }

    private function findExistingById(string $table, int $oldId): ?int
    {
        $existing = DB::table($table)->where('id', $oldId)->first();

        return $existing !== null ? (int) $existing->id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function applyColumnMappings(array $row, array $mapping): array
    {
        foreach ($mapping as $oldName => $newName) {
            if (array_key_exists($oldName, $row)) {
                $row[$newName] = $row[$oldName];
                unset($row[$oldName]);
            }
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $columnMapping
     * @param  list<array<string, mixed>>  $allRows
     */
    private function getOriginalFkValue(string $column, array $columnMapping, array $allRows, int $oldId): ?int
    {
        $originalColumn = $column;
        foreach ($columnMapping as $old => $new) {
            if ($new === $column) {
                $originalColumn = $old;
                break;
            }
        }

        foreach ($allRows as $row) {
            if (isset($row['id']) && (int) $row['id'] === $oldId) {
                $value = $row[$originalColumn] ?? $row[$column] ?? null;

                return $value !== null ? (int) $value : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string}>  $deferredUpdates
     * @param  list<string>  $warnings
     */
    private function processDeferredUpdates(array $deferredUpdates, array &$warnings): void
    {
        foreach ($deferredUpdates as $update) {
            $resolveFrom = $update['referenced_table'] ?? $update['table'];
            $newFkValue = $this->idRemapper->resolve($resolveFrom, $update['old_fk_value']);

            if ($newFkValue === null) {
                $warnings[] = "{$update['table']}[{$update['new_id']}].{$update['column']}: Could not resolve deferred FK (old value: {$update['old_fk_value']})";

                continue;
            }

            DB::table($update['table'])
                ->where('id', $update['new_id'])
                ->update([$update['column'] => $newFkValue]);
        }
    }

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

    private function stripSchemaPrefix(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
