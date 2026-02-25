<?php

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

        // Fallback: use 'id' if it exists, otherwise first column
        $columnNames = array_column($columns, 'name');
        if (in_array('id', $columnNames, true)) {
            $instance->primaryKey = 'id';
        } elseif (! empty($columnNames)) {
            $instance->primaryKey = $columnNames[0];
            $instance->incrementing = false;
            $instance->keyType = 'string';
        }

        return $instance;
    }
}
