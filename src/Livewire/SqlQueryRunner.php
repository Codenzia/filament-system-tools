<?php

namespace Codenzia\FilamentSystemTools\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SqlQueryRunner extends Component
{
    public string $tableName = '';

    public string $sql = '';

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    /** @var array<string> */
    public array $columns = [];

    public string $error = '';

    public string $message = '';

    public float $executionTime = 0;

    public int $affectedRows = 0;

    public function mount(string $tableName): void
    {
        $this->tableName = $tableName;
        $this->sql = "SELECT * FROM \"{$tableName}\" LIMIT 100";
    }

    public function execute(): void
    {
        $this->reset(['results', 'columns', 'error', 'message', 'executionTime', 'affectedRows']);

        $sql = trim($this->sql);

        if (empty($sql)) {
            $this->error = __('Please enter a SQL query.');

            return;
        }

        $startTime = microtime(true);

        try {
            $upper = strtoupper($sql);
            $isSelect = str_starts_with($upper, 'SELECT')
                || str_starts_with($upper, 'SHOW')
                || str_starts_with($upper, 'DESCRIBE')
                || str_starts_with($upper, 'EXPLAIN')
                || str_starts_with($upper, 'PRAGMA');

            if ($isSelect) {
                $rows = DB::select($sql);
                $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);

                if (! empty($rows)) {
                    $this->columns = array_keys((array) $rows[0]);
                    $this->results = array_map(fn ($row) => (array) $row, $rows);
                }

                $this->message = __(':count row(s) returned in :time ms', [
                    'count' => count($rows),
                    'time' => $this->executionTime,
                ]);
            } else {
                $isDml = str_starts_with($upper, 'INSERT')
                    || str_starts_with($upper, 'UPDATE')
                    || str_starts_with($upper, 'DELETE');

                if ($isDml) {
                    // DML statements return affected row count
                    $this->affectedRows = DB::affectingStatement($sql);
                } else {
                    // DDL (CREATE, ALTER, DROP) and other statements
                    DB::unprepared($sql);
                }

                $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $this->message = $isDml
                    ? __('Query executed. :count row(s) affected in :time ms.', [
                        'count' => $this->affectedRows,
                        'time' => $this->executionTime,
                    ])
                    : __('Query executed successfully in :time ms.', [
                        'time' => $this->executionTime,
                    ]);
            }
        } catch (\Throwable $e) {
            $this->executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('filament-system-tools::livewire.sql-query-runner');
    }
}
