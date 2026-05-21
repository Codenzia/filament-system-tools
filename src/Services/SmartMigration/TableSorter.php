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
                $referencedTable = $this->normalizeTableName($fk['on']);

                if ($referencedTable === $this->normalizeTableName($table)) {
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
                    $ref = $this->normalizeTableName($fk['on']);
                    if (! isset($remainingSet[$ref]) || $ref === $this->normalizeTableName($table)) {
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
        $normalizedTable = $this->normalizeTableName($table);

        foreach ($foreignKeys as $column => $fk) {
            if ($this->normalizeTableName($fk['on']) === $normalizedTable) {
                $selfRefs[] = $column;
            }
        }

        return $selfRefs;
    }

    private function normalizeTableName(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
