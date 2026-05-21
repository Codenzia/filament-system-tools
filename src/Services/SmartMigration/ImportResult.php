<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services\SmartMigration;

class ImportResult
{
    /**
     * @param  array<string, int>  $importedCounts  table => number of records imported (or updated)
     * @param  array<string, int>  $skippedCounts  table => number of records skipped
     * @param  list<string>  $errors  Fatal error messages encountered during import
     * @param  list<string>  $warnings  Non-fatal warnings
     */
    public function __construct(
        public readonly array $importedCounts = [],
        public readonly array $skippedCounts = [],
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function getTotalImported(): int
    {
        return array_sum($this->importedCounts);
    }

    public function getTotalSkipped(): int
    {
        return array_sum($this->skippedCounts);
    }

    public function getTablesImported(): int
    {
        return count(array_filter($this->importedCounts, fn (int $count): bool => $count > 0));
    }

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    /**
     * @return array{total_imported: int, total_skipped: int, tables_imported: int, errors: int, warnings: int}
     */
    public function getSummary(): array
    {
        return [
            'total_imported' => $this->getTotalImported(),
            'total_skipped' => $this->getTotalSkipped(),
            'tables_imported' => $this->getTablesImported(),
            'errors' => count($this->errors),
            'warnings' => count($this->warnings),
        ];
    }
}
