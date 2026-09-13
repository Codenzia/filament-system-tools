<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Generic Eloquent model that can target any database table at runtime.
 * Used for dynamic table inspection and data management.
 */
class DynamicTableModel extends Model
{
    public $timestamps = false;

    /**
     * Mass-assignment protection is intentionally disabled because this model
     * targets arbitrary, schema-driven tables. It MUST NEVER be filled with
     * untrusted request input via create()/fill(); all writes go through the
     * DB::table() query builder. Do not use this model with end-user data.
     */
    protected $guarded = [];

    public static function forTable(string $tableName): static
    {
        $instance = new static;
        $instance->setTable($tableName);

        // Detect primary key from schema
        $columns = Schema::getColumns($tableName);
        foreach ($columns as $column) {
            if (($column['auto_increment'] ?? false) === true) {
                $instance->primaryKey = $column['name'];
                $instance->incrementing = true;
                $instance->keyType = 'int';

                return $instance;
            }
        }

        $keyColumns = static::keyColumnsFor($tableName);
        $columnNames = array_column($columns, 'name');

        // A non-incrementing key (UUID, natural key, composite) is a string as
        // far as Eloquent is concerned; the first column is only a listing
        // placeholder when the table declares no key at all — mutation of such
        // tables is refused elsewhere rather than guessed at here.
        $instance->primaryKey = $keyColumns[0] ?? ($columnNames[0] ?? 'id');
        $instance->incrementing = false;
        $instance->keyType = 'string';

        return $instance;
    }

    /**
     * The columns that identify a row: the declared primary key, else the first
     * unique index. An empty result means the table has no stable identity and
     * individual rows cannot be safely updated or deleted.
     *
     * @return list<string>
     */
    public static function keyColumnsFor(string $tableName): array
    {
        $unique = [];

        foreach (Schema::getIndexes($tableName) as $index) {
            $columns = array_values(array_map('strval', $index['columns'] ?? []));

            if ($columns === []) {
                continue;
            }

            if ($index['primary'] ?? false) {
                return $columns;
            }

            if (($index['unique'] ?? false) && $unique === []) {
                $unique = $columns;
            }
        }

        if ($unique !== []) {
            return $unique;
        }

        foreach (Schema::getColumns($tableName) as $column) {
            if (($column['auto_increment'] ?? false) === true) {
                return [(string) $column['name']];
            }
        }

        return [];
    }
}
