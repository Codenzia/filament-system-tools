<?php

declare(strict_types=1);

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
        return match ((string) config('database.default')) {
            'mysql', 'mariadb' => 'information_schema.tables',
            default => 'sqlite_master',
        };
    }

    public function getConnectionName(): string
    {
        return (string) config('database.default');
    }

    public function newQuery(): Builder
    {
        $driver = config('database.default');
        $excludedTables = config('filament-system-tools.excluded_tables', []);

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $database = config("database.connections.{$driver}.database");

            // Pull approximate row counts and on-disk size in the same
            // information_schema query, avoiding a SELECT COUNT(*) and a
            // separate size query per table (N+1) when listing tables.
            return parent::newQuery()
                ->select(DB::raw('TABLE_NAME as name'))
                ->addSelect(DB::raw('TABLE_ROWS as table_rows'))
                ->addSelect(DB::raw('(DATA_LENGTH + INDEX_LENGTH) as table_size'))
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
        // MySQL/MariaDB: use the approximate count preloaded from
        // information_schema (see newQuery()).
        if (in_array(config('database.default'), ['mysql', 'mariadb'], true)
            && $this->getAttribute('table_rows') !== null) {
            return (int) $this->getAttribute('table_rows');
        }

        try {
            return DB::table($this->name)->count();
        } catch (\Exception) {
            return 0;
        }
    }

    /** On-disk size in bytes, preloaded for MySQL/MariaDB; null otherwise. */
    public function getPreloadedSize(): ?int
    {
        $size = $this->getAttribute('table_size');

        return $size === null ? null : (int) $size;
    }
}
