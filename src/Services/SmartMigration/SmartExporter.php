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
                array_flip(array_map([$this, 'stripSchemaPrefix'], $tableNames)),
            );

            if (empty($schema)) {
                $schema = array_filter(
                    $this->introspector->getTablesSchema(),
                    fn (string $key): bool => in_array($this->stripSchemaPrefix($key), $tableNames, true),
                    ARRAY_FILTER_USE_KEY,
                );
            }
        }

        $data = [];
        $normalizedSchema = [];
        foreach ($schema as $table => $tableSchema) {
            $stripped = $this->stripSchemaPrefix($table);
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
            ],
            '_schema' => $normalizedSchema,
            '_data' => $data,
        ];
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

    private function stripSchemaPrefix(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }
}
