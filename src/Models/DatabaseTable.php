<?php

namespace Codenzia\FilamentSystemTools\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DatabaseTable extends Model
{
    protected $primaryKey = 'name';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['name', 'type', 'sql'];

    public function getTable(): string
    {
        return match (config('database.default')) {
            'mysql', 'mariadb' => 'information_schema.tables',
            default => 'sqlite_master',
        };
    }

    public function getConnectionName(): string
    {
        return config('database.default');
    }

    public function newQuery(): Builder
    {
        $driver = config('database.default');
        $excludedTables = config('filament-system-tools.excluded_tables', []);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $database = config("database.connections.{$driver}.database");

            return parent::newQuery()
                ->select(DB::raw('TABLE_NAME as name'))
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_TYPE', 'BASE TABLE')
                ->whereNotIn('TABLE_NAME', $excludedTables);
        }

        return parent::newQuery()
            ->where('type', 'table')
            ->whereNotIn('name', $excludedTables);
    }

    public function getRowCount(): int
    {
        try {
            return DB::table($this->name)->count();
        } catch (\Exception) {
            return 0;
        }
    }
}
