<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

class SchemaDiffResult
{
    /**
     * @param  array<string, array{matched: list<string>, dropped: list<string>, added: list<string>, type_mismatches: array<string, array{exported: string, current: string}>, suggested_renames: array<string, string>}>  $tables
     * @param  list<string>  $skippedTables  Tables in export but not in current DB
     * @param  list<string>  $newTables  Tables in current DB but not in export
     */
    public function __construct(
        public readonly array $tables,
        public readonly array $skippedTables,
        public readonly array $newTables,
    ) {}

    /**
     * Status for a single table: 'match', 'partial', or 'skipped'.
     */
    public function getTableStatus(string $table): string
    {
        if (in_array($table, $this->skippedTables, true)) {
            return 'skipped';
        }

        if (! isset($this->tables[$table])) {
            return 'skipped';
        }

        $info = $this->tables[$table];

        if (
            empty($info['dropped'])
            && empty($info['added'])
            && empty($info['type_mismatches'])
            && empty($info['suggested_renames'])
        ) {
            return 'match';
        }

        return 'partial';
    }

    /**
     * @return list<string>
     */
    public function getImportableTables(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @return array{total_tables: int, matched: int, partial: int, skipped: int}
     */
    public function getSummary(): array
    {
        $matched = 0;
        $partial = 0;

        foreach (array_keys($this->tables) as $table) {
            if ($this->getTableStatus($table) === 'match') {
                $matched++;
            } else {
                $partial++;
            }
        }

        return [
            'total_tables' => count($this->tables) + count($this->skippedTables),
            'matched' => $matched,
            'partial' => $partial,
            'skipped' => count($this->skippedTables),
        ];
    }
}
