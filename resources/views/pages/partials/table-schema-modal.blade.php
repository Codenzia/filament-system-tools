@livewire('filament-system-tools::table-schema-viewer', ['tableName' => $tableName], key('schema-' . $tableName . '-' . now()->timestamp))
