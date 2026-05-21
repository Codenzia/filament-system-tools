<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

/**
 * Compare an exported schema against the live database schema and produce
 * a per-table diff (matched / dropped / added / type-mismatches / suggested
 * renames). Rename suggestions use Levenshtein distance + type compatibility.
 */
class SchemaDiffer
{
    private const MAX_RENAME_DISTANCE = 3;

    /**
     * @param  array<string, array{columns: array<string, array{type: string, nullable: bool, default: mixed}>, foreign_keys: array<string, array{references: string, on: string}>}>  $exportedSchema
     * @param  array<string, array{columns: array<string, array{type: string, nullable: bool, default: mixed}>, foreign_keys: array<string, array{references: string, on: string}>}>  $currentSchema
     */
    public function diff(array $exportedSchema, array $currentSchema): SchemaDiffResult
    {
        $exportedTables = $this->normalizeTableNames(array_keys($exportedSchema));
        $currentTables = $this->normalizeTableNames(array_keys($currentSchema));

        $skippedTables = array_values(array_diff($exportedTables, $currentTables));
        $newTables = array_values(array_diff($currentTables, $exportedTables));
        $commonTables = array_values(array_intersect($exportedTables, $currentTables));

        $tables = [];
        foreach ($commonTables as $table) {
            $exportedKey = $this->findOriginalKey($table, $exportedSchema);
            $currentKey = $this->findOriginalKey($table, $currentSchema);

            $tables[$table] = $this->diffTable(
                $exportedSchema[$exportedKey]['columns'] ?? [],
                $currentSchema[$currentKey]['columns'] ?? [],
            );
        }

        return new SchemaDiffResult($tables, $skippedTables, $newTables);
    }

    /**
     * @param  array<string, array{type: string, nullable: bool, default: mixed}>  $exportedColumns
     * @param  array<string, array{type: string, nullable: bool, default: mixed}>  $currentColumns
     * @return array{matched: list<string>, dropped: list<string>, added: list<string>, type_mismatches: array<string, array{exported: string, current: string}>, suggested_renames: array<string, string>}
     */
    private function diffTable(array $exportedColumns, array $currentColumns): array
    {
        $exportedNames = array_keys($exportedColumns);
        $currentNames = array_keys($currentColumns);

        $matched = [];
        $typeMismatches = [];
        $dropped = array_values(array_diff($exportedNames, $currentNames));
        $added = array_values(array_diff($currentNames, $exportedNames));

        $common = array_intersect($exportedNames, $currentNames);
        foreach ($common as $col) {
            $matched[] = $col;

            if (! $this->areTypesCompatible($exportedColumns[$col]['type'], $currentColumns[$col]['type'])) {
                $typeMismatches[$col] = [
                    'exported' => $exportedColumns[$col]['type'],
                    'current' => $currentColumns[$col]['type'],
                ];
            }
        }

        $suggestedRenames = $this->suggestRenames($dropped, $added, $exportedColumns, $currentColumns);

        return [
            'matched' => $matched,
            'dropped' => $dropped,
            'added' => $added,
            'type_mismatches' => $typeMismatches,
            'suggested_renames' => $suggestedRenames,
        ];
    }

    /**
     * @param  list<string>  $dropped
     * @param  list<string>  $added
     * @param  array<string, array{type: string, nullable: bool, default: mixed}>  $exportedColumns
     * @param  array<string, array{type: string, nullable: bool, default: mixed}>  $currentColumns
     * @return array<string, string> old_column => new_column
     */
    private function suggestRenames(array $dropped, array $added, array $exportedColumns, array $currentColumns): array
    {
        $renames = [];
        $usedAdded = [];

        foreach ($dropped as $oldCol) {
            $bestMatch = null;
            $bestDistance = self::MAX_RENAME_DISTANCE + 1;

            foreach ($added as $newCol) {
                if (isset($usedAdded[$newCol])) {
                    continue;
                }

                $distance = levenshtein($oldCol, $newCol);

                if (
                    $distance <= self::MAX_RENAME_DISTANCE
                    && $distance < $bestDistance
                    && $this->areTypesCompatible($exportedColumns[$oldCol]['type'], $currentColumns[$newCol]['type'])
                ) {
                    $bestMatch = $newCol;
                    $bestDistance = $distance;
                }
            }

            if ($bestMatch !== null) {
                $renames[$oldCol] = $bestMatch;
                $usedAdded[$bestMatch] = true;
            }
        }

        return $renames;
    }

    private function areTypesCompatible(string $typeA, string $typeB): bool
    {
        if ($typeA === $typeB) {
            return true;
        }

        $typeGroups = [
            'integer' => ['integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int'],
            'string' => ['varchar', 'char', 'text', 'mediumtext', 'longtext', 'tinytext', 'string'],
            'decimal' => ['decimal', 'float', 'double', 'real', 'numeric'],
            'datetime' => ['datetime', 'timestamp'],
            'json' => ['json', 'jsonb'],
            'boolean' => ['boolean', 'bool', 'tinyint'],
        ];

        foreach ($typeGroups as $compatible) {
            if (in_array($typeA, $compatible, true) && in_array($typeB, $compatible, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function normalizeTableNames(array $tables): array
    {
        return array_values(array_map(
            fn (string $t): string => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t,
            $tables,
        ));
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function findOriginalKey(string $table, array $schema): string
    {
        if (isset($schema[$table])) {
            return $table;
        }

        foreach (array_keys($schema) as $key) {
            $stripped = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
            if ($stripped === $table) {
                return $key;
            }
        }

        return $table;
    }
}
