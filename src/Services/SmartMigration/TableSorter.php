<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

/**
 * Topologically sort tables so that dependencies are imported before
 * dependents. Cycles are broken by preferring tables whose cycle-FKs
 * are nullable (deferrable) and falling back to most-referenced first.
 */
class TableSorter
{
    /**
     * @param  array<string, array{columns: array<string, mixed>, foreign_keys: array<string, array{references: string, on: string}>}>  $schema
     * @param  list<string>  $tablesToImport  Subset to sort (all schema tables if empty)
     * @return list<string>
     */
    public function sort(array $schema, array $tablesToImport = []): array
    {
        $tables = ! empty($tablesToImport) ? $tablesToImport : array_keys($schema);
        $tableSet = array_flip($tables);

        $graph = [];
        $inDegree = [];

        foreach ($tables as $table) {
            $graph[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($tables as $table) {
            if (! isset($schema[$table]['foreign_keys'])) {
                continue;
            }

            foreach ($schema[$table]['foreign_keys'] as $fk) {
                $referencedTable = TableName::strip($fk['on']);

                if ($referencedTable === TableName::strip($table)) {
                    continue;
                }

                if (! isset($tableSet[$referencedTable])) {
                    continue;
                }

                $graph[$referencedTable][] = $table;
                $inDegree[$table]++;
            }
        }

        $queue = [];
        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $queue[] = $table;
            }
        }

        $sorted = [];
        while (! empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;

            foreach ($graph[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        $remaining = array_values(array_diff($tables, $sorted));

        if (! empty($remaining)) {
            $remainingSet = array_flip($remaining);

            $refCount = array_fill_keys($remaining, 0);
            foreach ($remaining as $table) {
                foreach ($graph[$table] as $dependent) {
                    if (isset($remainingSet[$dependent])) {
                        $refCount[$table]++;
                    }
                }
            }

            $nullableScore = array_fill_keys($remaining, 0);
            foreach ($remaining as $table) {
                foreach ($schema[$table]['foreign_keys'] ?? [] as $column => $fk) {
                    $ref = TableName::strip($fk['on']);
                    if (! isset($remainingSet[$ref]) || $ref === TableName::strip($table)) {
                        continue;
                    }
                    $nullable = $schema[$table]['columns'][$column]['nullable'] ?? false;
                    $nullableScore[$table] += $nullable ? 1 : -1;
                }
            }

            usort($remaining, fn (string $a, string $b): int => $nullableScore[$b] <=> $nullableScore[$a]
                ?: $refCount[$b] <=> $refCount[$a]);

            foreach ($remaining as $table) {
                $sorted[] = $table;
            }
        }

        return $sorted;
    }

    /**
     * @param  array<string, array{references: string, on: string}>  $foreignKeys
     * @return list<string>
     */
    public function getSelfReferences(string $table, array $foreignKeys): array
    {
        $selfRefs = [];
        $normalizedTable = TableName::strip($table);

        foreach ($foreignKeys as $column => $fk) {
            if (TableName::strip($fk['on']) === $normalizedTable) {
                $selfRefs[] = $column;
            }
        }

        return $selfRefs;
    }
}
