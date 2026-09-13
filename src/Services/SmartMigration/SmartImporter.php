<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

use Illuminate\Database\Query\Builder;
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
            $normalizedCurrentSchema[TableName::strip($table)] = $info;
        }

        $tablesToImport = $options['tables'] ?? array_keys($exportedData);
        $tablesToImport = array_values(array_filter(
            $tablesToImport,
            fn (string $t): bool => isset($exportedData[$t]) && isset($normalizedCurrentSchema[$t]),
        ));

        $importedCounts = [];
        $skippedCounts = [];
        $errors = [];
        $warnings = [];

        /** @var array{column: string, value: int|string}|null $scope */
        $scope = $options['scope'] ?? null;

        if ($scope !== null) {
            $tablesToImport = $this->rejectUnscopedTables(
                $tablesToImport,
                $normalizedCurrentSchema,
                $scope['column'],
                $warnings,
            );
        }

        // An empty selection means nothing to import: the sorter would treat it
        // as "every table", which would silently widen the import.
        $sortedTables = $tablesToImport === []
            ? []
            : $this->tableSorter->sort($normalizedCurrentSchema, $tablesToImport);

        /** @var list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string, required?: bool}> */
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

            // Nothing was committed: counts describing the rolled-back attempt
            // would overstate what the destination database actually holds.
            $importedCounts = [];
            $skippedCounts = [];
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
     * @param  list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string, required?: bool}>  $deferredUpdates
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

        // Every identity lookup runs inside the destination scope, so an import
        // can never match — nor update — a row belonging to another tenant.
        $scopeFilter = $applyScope
            ? fn (Builder $query): Builder => $query->where($scopeColumn, $scopeValue)
            : null;

        $uniqueIndexes = $this->introspector->getUniqueIndexes($table);
        $identityColumns = $this->declaredIdentityColumns($table, $currentColumns);

        /** @var array<string, array<string, mixed>> $rowsByOldId */
        $rowsByOldId = [];
        if ($selfRefColumns !== []) {
            foreach ($rows as $sourceRow) {
                if (isset($sourceRow['id'])) {
                    $rowsByOldId[(string) $sourceRow['id']] = $sourceRow;
                }
            }
        }

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

                    $referencedTable = TableName::strip($fkDef['on']);
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

                $existingId = $this->findExistingByIdentity($table, $row, $identityColumns, $scopeFilter)
                    ?? $this->findExistingByUniqueKey($table, $row, $uniqueIndexes, $scopeFilter)
                    ?? $this->findExistingByStableId($table, $oldId, $hasPrimaryId && ! $isAutoIncrement, $scopeFilter);

                if ($existingId !== null) {
                    if ($oldId !== null && is_numeric($oldId) && is_numeric($existingId)) {
                        $this->idRemapper->record($table, (int) $oldId, (int) $existingId);
                    }

                    if ($onDuplicate === 'update') {
                        $updateData = $row;
                        unset($updateData['id']);
                        $updateQuery = DB::table($table)->where('id', $existingId);

                        if ($scopeFilter !== null) {
                            $scopeFilter($updateQuery);
                        }

                        $updateQuery->update($updateData);
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
                            $originalFkValue = $this->getOriginalFkValue($selfRefCol, $columnMapping, $rowsByOldId, (int) $oldId);
                            if ($originalFkValue !== null) {
                                $deferredUpdates[] = [
                                    'table' => $table,
                                    'new_id' => (int) $newId,
                                    'column' => $selfRefCol,
                                    'old_fk_value' => (int) $originalFkValue,
                                    'required' => false,
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
                                'required' => true,
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
     * Identity columns declared by the host for this table (for example an
     * external key that survives a move between databases). Only columns the
     * destination table actually has are used.
     *
     * @param  list<string>  $currentColumns
     * @return list<string>
     */
    private function declaredIdentityColumns(string $table, array $currentColumns): array
    {
        $declared = config('filament-system-tools.smart_migration.identity_keys.'.$table, []);

        if (! is_array($declared) || $declared === []) {
            return [];
        }

        $columns = array_values(array_filter(
            array_map('strval', $declared),
            fn (string $column): bool => in_array($column, $currentColumns, true),
        ));

        return count($columns) === count($declared) ? $columns : [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $identityColumns
     * @param  (\Closure(Builder): Builder)|null  $scopeFilter
     */
    private function findExistingByIdentity(string $table, array $row, array $identityColumns, ?\Closure $scopeFilter): int|string|null
    {
        if ($identityColumns === []) {
            return null;
        }

        return $this->findExistingByColumns($table, $row, $identityColumns, $scopeFilter);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<list<string>>  $uniqueIndexes
     * @param  (\Closure(Builder): Builder)|null  $scopeFilter
     */
    private function findExistingByUniqueKey(string $table, array $row, array $uniqueIndexes, ?\Closure $scopeFilter): int|string|null
    {
        foreach ($uniqueIndexes as $indexColumns) {
            $existingId = $this->findExistingByColumns($table, $row, $indexColumns, $scopeFilter);

            if ($existingId !== null) {
                return $existingId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @param  (\Closure(Builder): Builder)|null  $scopeFilter
     */
    private function findExistingByColumns(string $table, array $row, array $columns, ?\Closure $scopeFilter): int|string|null
    {
        if ($columns === []) {
            return null;
        }

        $query = DB::table($table);

        foreach ($columns as $col) {
            if (! array_key_exists($col, $row) || $row[$col] === null) {
                return null;
            }

            $query->where($col, $row[$col]);
        }

        if ($scopeFilter !== null) {
            $scopeFilter($query);
        }

        $existing = $query->first();

        return $existing !== null ? ($existing->id ?? null) : null;
    }

    /**
     * Match on the exported primary key only when that key is a stable identity
     * (UUID/ULID/natural key). Auto-increment ids from a different database are
     * coincidences, never proof that two rows are the same record.
     *
     * @param  (\Closure(Builder): Builder)|null  $scopeFilter
     */
    private function findExistingByStableId(string $table, mixed $oldId, bool $hasStableId, ?\Closure $scopeFilter): int|string|null
    {
        if (! $hasStableId || $oldId === null || $oldId === '') {
            return null;
        }

        $query = DB::table($table)->where('id', $oldId);

        if ($scopeFilter !== null) {
            $scopeFilter($query);
        }

        $existing = $query->first();

        return $existing !== null ? ($existing->id ?? null) : null;
    }

    /**
     * Tables without the scope column cannot be filtered by tenant, so a scoped
     * import refuses them unless the host has explicitly declared them global.
     *
     * @param  list<string>  $tables
     * @param  array<string, array{columns?: array<string, mixed>}>  $currentSchema
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function rejectUnscopedTables(array $tables, array $currentSchema, string $scopeColumn, array &$warnings): array
    {
        $globalTables = config('filament-system-tools.smart_migration.global_tables', []);
        $globalTables = is_array($globalTables) ? array_map('strval', $globalTables) : [];

        $allowed = [];

        foreach ($tables as $table) {
            if (isset($currentSchema[$table]['columns'][$scopeColumn]) || in_array($table, $globalTables, true)) {
                $allowed[] = $table;

                continue;
            }

            $warnings[] = "{$table}: skipped — no {$scopeColumn} column and not listed in smart_migration.global_tables";
        }

        return $allowed;
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
     * @param  array<string, array<string, mixed>>  $rowsByOldId  source id => source row
     */
    private function getOriginalFkValue(string $column, array $columnMapping, array $rowsByOldId, int $oldId): ?int
    {
        $originalColumn = $column;
        foreach ($columnMapping as $old => $new) {
            if ($new === $column) {
                $originalColumn = $old;
                break;
            }
        }

        $row = $rowsByOldId[(string) $oldId] ?? null;

        if ($row === null) {
            return null;
        }

        $value = $row[$originalColumn] ?? $row[$column] ?? null;

        return $value !== null ? (int) $value : null;
    }

    /**
     * @param  list<array{table: string, new_id: int, column: string, old_fk_value: int, referenced_table?: string, required?: bool}>  $deferredUpdates
     * @param  list<string>  $warnings
     */
    private function processDeferredUpdates(array $deferredUpdates, array &$warnings): void
    {
        foreach ($deferredUpdates as $update) {
            $resolveFrom = $update['referenced_table'] ?? $update['table'];
            $newFkValue = $this->idRemapper->resolve($resolveFrom, $update['old_fk_value']);

            if ($newFkValue === null) {
                // A required link still holding a raw source id would be committed
                // as a dangling reference while FK checks are off — abort instead.
                if ($update['required'] ?? false) {
                    throw new \RuntimeException(
                        "{$update['table']}[{$update['new_id']}].{$update['column']}: unresolved required reference to {$resolveFrom} (source value: {$update['old_fk_value']})",
                    );
                }

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
}
