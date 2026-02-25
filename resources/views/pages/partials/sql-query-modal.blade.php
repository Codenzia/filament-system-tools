@livewire('filament-system-tools::sql-query-runner', ['tableName' => $tableName], key('sql-' . $tableName . '-' . now()->timestamp))
