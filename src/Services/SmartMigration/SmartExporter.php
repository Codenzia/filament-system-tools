<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build a v2 export payload that pairs row data with the live schema
 * metadata. Optional scoping (`['column' => 'team_id', 'value' => 1]`)
 * filters rows on tables that contain the scope column.
 */
class SmartExporter
{
    public function __construct(
        private readonly SchemaIntrospector $introspector,
    ) {}

    /**
     * @param  array{column: string, value: int|string}|null  $scope  Optional row-level filter
     * @param  list<string>  $tableNames  Subset of tables to export (all if empty)
     * @return array{_meta: array<string, mixed>, _schema: array<string, mixed>, _data: array<string, list<array<string, mixed>>>}
     */
    public function export(?array $scope = null, array $tableNames = []): array
    {
        $schema = $this->introspector->getTablesSchema();

        if (! empty($tableNames)) {
            $schema = array_intersect_key(
                $schema,
                array_flip(array_map([TableName::class, 'strip'], $tableNames)),
            );

            if (empty($schema)) {
                $schema = array_filter(
                    $this->introspector->getTablesSchema(),
                    fn (string $key): bool => in_array(TableName::strip($key), $tableNames, true),
                    ARRAY_FILTER_USE_KEY,
                );
            }
        }

        $data = [];
        $normalizedSchema = [];
        $unscopedTables = [];

        foreach ($schema as $table => $tableSchema) {
            $stripped = TableName::strip($table);

            if ($scope !== null && ! $this->isScopable($stripped, $scope['column'])) {
                $unscopedTables[] = $stripped;

                continue;
            }

            $normalizedSchema[$stripped] = $tableSchema;
            $data[$stripped] = $this->exportTableData($stripped, $scope);
        }

        return [
            '_meta' => [
                'version' => 2,
                'exported_at' => now()->toIso8601String(),
                'app_name' => config('app.name'),
                'app_version' => config('filament-system-tools.release.version', config('app.version', '1.0.0')),
                'database_driver' => DB::getDriverName(),
                'scope' => $scope,
                'excluded_unscoped_tables' => $unscopedTables,
            ],
            '_schema' => $normalizedSchema,
            '_data' => $data,
        ];
    }

    /**
     * A scoped export may only include tables the scope can actually filter.
     * Tables without the scope column are left out unless the host declared
     * them global in `smart_migration.global_tables`.
     */
    private function isScopable(string $table, string $scopeColumn): bool
    {
        if (Schema::hasColumn($table, $scopeColumn)) {
            return true;
        }

        $globalTables = config('filament-system-tools.smart_migration.global_tables', []);

        return is_array($globalTables) && in_array($table, array_map('strval', $globalTables), true);
    }

    /**
     * @param  array{column: string, value: int|string}|null  $scope
     * @return list<array<string, mixed>>
     */
    private function exportTableData(string $table, ?array $scope): array
    {
        $query = DB::table($table);

        if ($scope !== null && Schema::hasColumn($table, $scope['column'])) {
            $query->where($scope['column'], $scope['value']);
        }

        return $query->get()->map(fn ($row): array => (array) $row)->values()->all();
    }
}
