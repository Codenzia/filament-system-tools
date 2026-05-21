<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

/**
 * Tracks old → new primary key mappings during a Smart Import so that
 * foreign-key columns referencing a table can be rewritten to point at
 * the new IDs assigned by auto-increment inserts.
 */
class IdRemapper
{
    /**
     * @var array<string, array<int, int>> table => [old_id => new_id]
     */
    private array $mappings = [];

    public function record(string $table, int $oldId, int $newId): void
    {
        $this->mappings[$table][$oldId] = $newId;
    }

    public function resolve(string $table, int $oldId): ?int
    {
        return $this->mappings[$table][$oldId] ?? null;
    }

    public function has(string $table, int $oldId): bool
    {
        return isset($this->mappings[$table][$oldId]);
    }

    /**
     * @return array<int, int>
     */
    public function getMappingsForTable(string $table): array
    {
        return $this->mappings[$table] ?? [];
    }

    public function getTotalMappings(): int
    {
        $count = 0;
        foreach ($this->mappings as $tableMappings) {
            $count += count($tableMappings);
        }

        return $count;
    }

    public function reset(): void
    {
        $this->mappings = [];
    }
}
