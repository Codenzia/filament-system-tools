<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

use Illuminate\Support\Facades\Schema;

/**
 * Reads the live database schema (columns, foreign keys, unique indexes,
 * auto-increment status) using Laravel's Schema facade. The output feeds
 * SchemaDiffer, SmartExporter, SmartImporter, and TableSorter.
 */
class SchemaIntrospector
{
    /**
     * Tables that are never exported or imported regardless of selection.
     * Sourced from the package config so projects can extend the list.
     *
     * @return list<string>
     */
    public function getExcludedTables(): array
    {
        $excluded = config('filament-system-tools.excluded_tables', []);

        return is_array($excluded) ? array_values(array_filter($excluded, 'is_string')) : [];
    }

    /**
     * @return array<string, array{columns: array<string, array{type: string, nullable: bool, default: mixed}>, foreign_keys: array<string, array{references: string, on: string}>}>
     */
    public function getTablesSchema(): array
    {
        $schema = [];

        foreach ($this->getTableNames() as $table) {
            $schema[$table] = [
                'columns' => $this->getColumnsForTable($table),
                'foreign_keys' => $this->getForeignKeysForTable($table),
            ];
        }

        return $schema;
    }

    /**
     * @return array{columns: array<string, array{type: string, nullable: bool, default: mixed}>, foreign_keys: array<string, array{references: string, on: string}>}
     */
    public function getTableSchema(string $table): array
    {
        return [
            'columns' => $this->getColumnsForTable($table),
            'foreign_keys' => $this->getForeignKeysForTable($table),
        ];
    }

    /**
     * @return list<string>
     */
    public function getTableNames(): array
    {
        $excluded = $this->getExcludedTables();

        return array_values(array_filter(
            Schema::getTableListing(),
            fn (string $table): bool => ! in_array($this->stripSchemaPrefix($table), $excluded, true),
        ));
    }

    /**
     * Unique index column sets (excluding the primary key) used to detect
     * duplicates that share the same domain identity but a different `id`.
     *
     * @return list<list<string>>
     */
    public function getUniqueIndexes(string $table): array
    {
        $indexes = [];

        foreach (Schema::getIndexes($this->stripSchemaPrefix($table)) as $index) {
            if (($index['unique'] ?? false) && ! ($index['primary'] ?? false)) {
                $indexes[] = $index['columns'];
            }
        }

        return $indexes;
    }

    public function isAutoIncrementId(string $table): bool
    {
        $stripped = $this->stripSchemaPrefix($table);

        foreach (Schema::getColumns($stripped) as $column) {
            if ($column['name'] === 'id') {
                return (bool) ($column['auto_increment'] ?? false);
            }
        }

        return false;
    }

    /**
     * @return array<string, array{type: string, nullable: bool, default: mixed}>
     */
    private function getColumnsForTable(string $table): array
    {
        $columns = [];

        foreach (Schema::getColumns($this->stripSchemaPrefix($table)) as $column) {
            $columns[$column['name']] = [
                'type' => $column['type_name'] ?? $column['type'] ?? 'string',
                'nullable' => (bool) ($column['nullable'] ?? false),
                'default' => $column['default'] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * Single-column foreign keys only — composite FKs are skipped because
     * the importer's ID remap logic operates per-column.
     *
     * @return array<string, array{references: string, on: string}>
     */
    private function getForeignKeysForTable(string $table): array
    {
        $foreignKeys = [];

        foreach (Schema::getForeignKeys($this->stripSchemaPrefix($table)) as $fk) {
            if (count($fk['columns']) === 1 && count($fk['foreign_columns']) === 1) {
                $foreignKeys[$fk['columns'][0]] = [
                    'references' => $fk['foreign_columns'][0],
                    'on' => $fk['foreign_table'],
                ];
            }
        }

        return $foreignKeys;
    }

    private function stripSchemaPrefix(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
