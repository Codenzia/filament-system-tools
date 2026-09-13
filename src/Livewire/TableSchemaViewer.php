<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Livewire;

use Codenzia\FilamentSystemTools\Services\SmartMigration\TableName;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class TableSchemaViewer extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public string $tableName = '';

    public function mount(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    public function canManageSchema(): bool
    {
        return filament()->auth()->user()?->can('manage_table_schema') ?? false;
    }

    /**
     * Whitelist-validate the (client-hydrated) table name against the live
     * table listing. Prevents SQL injection via the public $tableName property.
     */
    private function assertValidTable(): void
    {
        if (! $this->canManageSchema()) {
            throw new \RuntimeException(__('Unauthorized'));
        }

        $tables = array_map(
            fn (string $t) => TableName::strip($t),
            Schema::getTableListing()
        );

        if (! in_array($this->tableName, $tables, true)) {
            throw new \RuntimeException(__('Invalid table.'));
        }
    }

    private function assertValidColumn(string $columnName): void
    {
        $columns = array_column($this->getColumns(), 'name');

        if (! in_array($columnName, $columns, true)) {
            throw new \RuntimeException(__('Invalid column.'));
        }
    }

    private function assertValidIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \RuntimeException(__('Invalid identifier.'));
        }
    }

    /**
     * Reject any column type that is not one of the fixed Select options,
     * guarding against free-text injected via Livewire property hydration.
     */
    private function assertValidType(array $data): void
    {
        $type = strtoupper((string) ($data['type'] ?? ''));

        if ($type !== '' && ! array_key_exists($type, $this->getTypeOptions())) {
            throw new \RuntimeException(__('Invalid column type.'));
        }
    }

    /**
     * Quote a validated identifier, doubling any embedded quote char so a table
     * or column name cannot break out of the quoted context in raw DDL.
     */
    private function quoteIdentifier(string $name): string
    {
        $quote = $this->isMysql() ? '`' : '"';

        return $quote.str_replace($quote, $quote.$quote, $name).$quote;
    }

    /** @return array<array<string, mixed>> */
    public function getColumns(): array
    {
        return Schema::getColumns($this->tableName);
    }

    /** @return array<array<string, mixed>> */
    public function getIndexes(): array
    {
        try {
            return Schema::getIndexes($this->tableName);
        } catch (\Throwable) {
            return [];
        }
    }

    // ──────────────────────────────────────────────
    // Actions
    // ──────────────────────────────────────────────

    public function addColumnAction(): Actions\Action
    {
        return Actions\Action::make('addColumn')
            ->label(__('Add Column'))
            ->icon('heroicon-o-plus')
            ->color('primary')
            ->visible(fn (): bool => $this->canManageSchema())
            ->schema($this->getColumnForm())
            ->action(function (array $data): void {
                $this->addColumn($data);
            });
    }

    public function editColumnAction(): Actions\Action
    {
        return Actions\Action::make('editColumn')
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil-square')
            ->iconButton()
            ->size('sm')
            ->color('gray')
            ->visible(fn (): bool => $this->canManageSchema())
            ->schema(fn (array $arguments): array => $this->getEditColumnForm())
            ->fillForm(function (array $arguments): array {
                $columns = $this->getColumns();
                $col = collect($columns)->firstWhere('name', $arguments['column'] ?? '');

                if (! $col) {
                    return [];
                }

                return [
                    'name' => $col['name'],
                    'type' => strtoupper($col['type_name'] ?? ''),
                    'length' => $this->extractLength($col['type'] ?? ''),
                    'nullable' => (bool) ($col['nullable'] ?? false),
                    'default' => $col['default'] ?? null,
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $this->editColumn($arguments['column'], $data);
            });
    }

    public function deleteColumnAction(): Actions\Action
    {
        return Actions\Action::make('deleteColumn')
            ->label(__('Delete'))
            ->icon('heroicon-o-trash')
            ->iconButton()
            ->size('sm')
            ->color('danger')
            ->visible(fn (): bool => $this->canManageSchema())
            ->requiresConfirmation()
            ->modalHeading(__('Delete Column'))
            ->modalDescription(fn (array $arguments): string => __('Are you sure you want to delete column ":column"? This cannot be undone.', ['column' => $arguments['column'] ?? '']))
            ->action(function (array $arguments): void {
                $this->deleteColumn($arguments['column']);
            });
    }

    // ──────────────────────────────────────────────
    // Column Operations
    // ──────────────────────────────────────────────

    private function addColumn(array $data): void
    {
        try {
            $this->assertValidTable();
            $this->assertValidIdentifier((string) ($data['name'] ?? ''));
            $this->assertValidType($data);
            $definition = $this->buildColumnDefinition($data);
            $table = $this->quoteIdentifier($this->tableName);
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$definition}");

            Notification::make()
                ->title(__('Column added'))
                ->body(__('Column ":name" added to :table.', ['name' => $data['name'], 'table' => $this->tableName]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to add column'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function editColumn(string $oldName, array $data): void
    {
        try {
            $this->assertValidTable();
            $this->assertValidColumn($oldName);
            $this->assertValidIdentifier((string) ($data['name'] ?? ''));
            $this->assertValidType($data);

            $table = $this->quoteIdentifier($this->tableName);

            if ($this->isMysql()) {
                $definition = $this->buildColumnDefinition($data);

                if ($oldName !== $data['name']) {
                    DB::statement("ALTER TABLE {$table} CHANGE {$this->quoteIdentifier($oldName)} {$definition}");
                } else {
                    DB::statement("ALTER TABLE {$table} MODIFY {$definition}");
                }
            } else {
                // SQLite: only renaming is supported
                if ($oldName !== $data['name']) {
                    DB::statement("ALTER TABLE {$table} RENAME COLUMN {$this->quoteIdentifier($oldName)} TO {$this->quoteIdentifier((string) $data['name'])}");
                } else {
                    Notification::make()
                        ->title(__('No changes'))
                        ->body(__('SQLite only supports renaming columns. Type and constraint changes require recreating the table.'))
                        ->warning()
                        ->send();

                    return;
                }
            }

            Notification::make()
                ->title(__('Column updated'))
                ->body(__('Column ":name" has been updated.', ['name' => $data['name']]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to update column'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function deleteColumn(string $columnName): void
    {
        try {
            $this->assertValidTable();
            $this->assertValidColumn($columnName);
            DB::statement("ALTER TABLE {$this->quoteIdentifier($this->tableName)} DROP COLUMN {$this->quoteIdentifier($columnName)}");

            Notification::make()
                ->title(__('Column deleted'))
                ->body(__('Column ":name" removed from :table.', ['name' => $columnName, 'table' => $this->tableName]))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to delete column'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function getEditColumnForm(): array
    {
        $isMysql = $this->isMysql();

        return [
            TextInput::make('name')
                ->label(__('Column Name'))
                ->required()
                ->alphaDash(),
            Select::make('type')
                ->label(__('Type'))
                ->required($isMysql)
                ->disabled(! $isMysql)
                ->helperText(! $isMysql ? __('SQLite does not support changing column types.') : null)
                ->searchable()
                ->options($this->getTypeOptions()),
            TextInput::make('length')
                ->label(__('Length'))
                ->numeric()
                ->disabled(! $isMysql)
                ->placeholder('255'),
            Checkbox::make('nullable')
                ->label(__('Nullable'))
                ->disabled(! $isMysql),
            TextInput::make('default')
                ->label(__('Default Value'))
                ->disabled(! $isMysql)
                ->placeholder(__('None')),
        ];
    }

    private function getColumnForm(): array
    {
        return [
            TextInput::make('name')
                ->label(__('Column Name'))
                ->required()
                ->alphaDash(),
            Select::make('type')
                ->label(__('Type'))
                ->required()
                ->searchable()
                ->options($this->getTypeOptions()),
            TextInput::make('length')
                ->label(__('Length'))
                ->numeric()
                ->placeholder('255'),
            Checkbox::make('nullable')
                ->label(__('Nullable'))
                ->default(true),
            TextInput::make('default')
                ->label(__('Default Value'))
                ->placeholder(__('None')),
        ];
    }

    /** @return array<string, string> */
    private function getTypeOptions(): array
    {
        return [
            'VARCHAR' => 'VARCHAR',
            'CHAR' => 'CHAR',
            'TEXT' => 'TEXT',
            'TINYINT' => 'TINYINT',
            'SMALLINT' => 'SMALLINT',
            'INTEGER' => 'INTEGER',
            'BIGINT' => 'BIGINT',
            'BOOLEAN' => 'BOOLEAN',
            'DATE' => 'DATE',
            'DATETIME' => 'DATETIME',
            'TIMESTAMP' => 'TIMESTAMP',
            'TIME' => 'TIME',
            'DECIMAL' => 'DECIMAL',
            'FLOAT' => 'FLOAT',
            'DOUBLE' => 'DOUBLE',
            'JSON' => 'JSON',
            'BLOB' => 'BLOB',
        ];
    }

    private function buildColumnDefinition(array $data): string
    {
        $name = $this->quoteIdentifier((string) $data['name']);
        $type = $data['type'];

        if (! empty($data['length']) && in_array($type, ['VARCHAR', 'CHAR', 'DECIMAL'], true)) {
            $type .= '('.(int) $data['length'].')';
        }

        $nullable = ! empty($data['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';

        if ($data['default'] !== null && $data['default'] !== '') {
            $default = 'DEFAULT '.DB::getPdo()->quote($data['default']);
        }

        return trim("{$name} {$type} {$nullable} {$default}");
    }

    private function isMysql(): bool
    {
        return in_array(config('database.default'), ['mysql', 'mariadb'], true);
    }

    public function supportsDropColumn(): bool
    {
        if ($this->isMysql()) {
            return true;
        }

        // SQLite 3.35.0+ supports DROP COLUMN
        try {
            $version = DB::selectOne('SELECT sqlite_version() as version');

            return version_compare($version->version, '3.35.0', '>=');
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractLength(string $fullType): ?string
    {
        if (preg_match('/\((\d+)\)/', $fullType, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function render(): View
    {
        return view('filament-system-tools::livewire.table-schema-viewer');
    }
}
