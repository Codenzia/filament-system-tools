<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Livewire;

use Codenzia\FilamentSystemTools\Models\DynamicTableModel;
use Filament\Actions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class TableDataViewer extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable {
        getTableRecordKey as protected baseGetTableRecordKey;
        resolveTableRecord as protected baseResolveTableRecord;
    }

    public string $tableName = '';

    /** @var list<string>|null */
    private ?array $keyColumns = null;

    public function mount(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    public function canManageData(): bool
    {
        return (filament()->auth()->user()?->can('manage_table_data') ?? false)
            && $this->keyColumns() !== [];
    }

    /**
     * Columns that identify a single row of this table. Empty when the table
     * declares no primary or unique key — such tables stay read-only, because
     * any write would have to guess which rows it is touching.
     *
     * @return list<string>
     */
    public function keyColumns(): array
    {
        return $this->keyColumns ??= DynamicTableModel::keyColumnsFor($this->tableName);
    }

    /**
     * @param  Model|array<string, mixed>  $record
     */
    public function getTableRecordKey(Model|array $record): string
    {
        $keyColumns = $this->keyColumns();

        if (count($keyColumns) < 2 || is_array($record)) {
            return $this->baseGetTableRecordKey($record);
        }

        $values = [];
        foreach ($keyColumns as $column) {
            $values[$column] = $record->{$column};
        }

        return base64_encode((string) json_encode($values));
    }

    /**
     * @return Model|array<string, mixed>|null
     */
    protected function resolveTableRecord(?string $key): Model|array|null
    {
        $keyColumns = $this->keyColumns();

        if ($key === null || count($keyColumns) < 2) {
            return $this->baseResolveTableRecord($key);
        }

        $values = json_decode((string) base64_decode($key, true), true);

        if (! is_array($values) || array_keys($values) !== $keyColumns) {
            return null;
        }

        $query = DynamicTableModel::forTable($this->tableName)->newQuery();

        foreach ($keyColumns as $column) {
            $query->where($column, $values[$column]);
        }

        return $query->first();
    }

    public function table(Table $table): Table
    {
        $columnNames = Schema::getColumnListing($this->tableName);
        $columnInfo = collect(Schema::getColumns($this->tableName))->keyBy('name');

        // Build dynamic table columns
        $columns = collect($columnNames)->map(function (string $name) use ($columnInfo): TextColumn {
            $col = $columnInfo->get($name, []);
            $column = TextColumn::make($name)
                ->sortable()
                ->searchable()
                ->size(TextSize::Small)
                ->fontFamily(FontFamily::Mono)
                ->limit(50)
                ->tooltip(fn ($state): ?string => is_string($state) && strlen($state) > 50 ? $state : null);

            // Hide columns beyond the 8th by default to prevent overflow
            if (array_search($name, array_keys($columnInfo->toArray())) >= 8) {
                $column->toggleable(isToggledHiddenByDefault: true);
            }

            return $column;
        })->toArray();

        return $table
            ->query(fn () => DynamicTableModel::forTable($this->tableName)->newQuery())
            ->columns($columns)
            ->headerActions([
                Actions\Action::make('addRow')
                    ->label(__('Add Row'))
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->visible(fn (): bool => $this->canManageData())
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge)
                    ->schema($this->buildRowForm())
                    ->action(function (array $data): void {
                        $this->insertRow($data);
                    }),
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('editRow')
                        ->label(__('Edit'))
                        ->icon('heroicon-o-pencil-square')
                        ->visible(fn (): bool => $this->canManageData())
                        ->slideOver()
                        ->modalWidth(Width::FourExtraLarge)
                        ->schema($this->buildRowForm())
                        ->fillForm(fn ($record): array => $record->toArray())
                        ->action(function (array $data, $record): void {
                            $this->updateRow($record, $data);
                        }),
                    Actions\Action::make('deleteRow')
                        ->label(__('Delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (): bool => $this->canManageData())
                        ->requiresConfirmation()
                        ->action(function ($record): void {
                            $this->deleteRow($record);
                        }),
                ]),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->description($this->keyColumns() === []
                ? __('Read-only: this table has no primary or unique key, so a single row cannot be identified.')
                : null)
            ->emptyStateHeading(__('No data'))
            ->emptyStateDescription(__('This table has no rows.'));
    }

    // ──────────────────────────────────────────────
    // Row Operations
    // ──────────────────────────────────────────────

    private function insertRow(array $data): void
    {
        if (! $this->canManageData()) {
            Notification::make()->title(__('Unauthorized'))->danger()->send();

            return;
        }

        try {
            // Filter out empty values for auto-increment columns
            $columnInfo = collect(Schema::getColumns($this->tableName))->keyBy('name');
            $filteredData = collect($data)->filter(function ($value, $key) use ($columnInfo): bool {
                $col = $columnInfo->get($key);

                // Skip auto-increment fields with empty values
                if (($col['auto_increment'] ?? false) && ($value === null || $value === '')) {
                    return false;
                }

                return true;
            })->map(fn ($value) => $value === '' ? null : $value)->toArray();

            DB::table($this->tableName)->insert($filteredData);

            Notification::make()
                ->title(__('Row added'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to add row'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function updateRow($record, array $data): void
    {
        if (! $this->canManageData()) {
            Notification::make()->title(__('Unauthorized'))->danger()->send();

            return;
        }

        try {
            $updateData = collect($data)->map(fn ($value) => $value === '' ? null : $value)->toArray();

            $this->rowQuery($record)->update($updateData);

            Notification::make()
                ->title(__('Row updated'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to update row'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    private function deleteRow($record): void
    {
        if (! $this->canManageData()) {
            Notification::make()->title(__('Unauthorized'))->danger()->send();

            return;
        }

        try {
            $this->rowQuery($record)->delete();

            Notification::make()
                ->title(__('Row deleted'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('Failed to delete row'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Locate exactly one row. Every key column takes part, so a composite key
     * such as (role_id, user_id) can never match its siblings.
     *
     * @param  Model  $record
     */
    private function rowQuery($record): Builder
    {
        $keyColumns = $this->keyColumns();

        if ($keyColumns === []) {
            throw new \RuntimeException(__('This table has no primary or unique key, so rows cannot be identified.'));
        }

        $query = DB::table($this->tableName);

        foreach ($keyColumns as $column) {
            $value = $record->{$column};

            if ($value === null) {
                throw new \RuntimeException(__('This row has no value for :column and cannot be identified.', ['column' => $column]));
            }

            $query->where($column, $value);
        }

        return $query;
    }

    // ──────────────────────────────────────────────
    // Form Builder
    // ──────────────────────────────────────────────

    /** @return array<\Filament\Schemas\Components\Component> */
    private function buildRowForm(): array
    {
        $columns = Schema::getColumns($this->tableName);

        return collect($columns)->map(function (array $col) {
            $name = $col['name'];
            $typeName = strtolower($col['type_name'] ?? '');
            $isAutoIncrement = $col['auto_increment'] ?? false;
            $isNullable = $col['nullable'] ?? false;
            $default = $col['default'] ?? null;

            // Choose component based on column type
            $component = match (true) {
                in_array($typeName, ['text', 'longtext', 'mediumtext', 'tinytext', 'blob']) => Textarea::make($name)
                    ->rows(3),
                in_array($typeName, ['boolean', 'tinyint']) && str_contains($col['type'] ?? '', '(1)') => Toggle::make($name),
                in_array($typeName, ['json', 'jsonb']) => Textarea::make($name)
                    ->rows(4)
                    ->json(),
                default => TextInput::make($name),
            };

            $component->label($name);

            $isToggle = $component instanceof Toggle;

            if ($isAutoIncrement) {
                $component->disabled()->dehydrated(false);
                if (! $isToggle) {
                    $component->placeholder(__('Auto-generated'));
                }
            }

            if (! $isNullable && ! $isAutoIncrement && $default === null) {
                $component->required();
            }

            if ($default !== null && ! $isToggle) {
                $component->placeholder("Default: {$default}");
            }

            if ($default !== null && $isToggle) {
                $component->default((bool) $default);
            }

            return $component;
        })->toArray();
    }

    public function render(): View
    {
        return view('filament-system-tools::livewire.table-data-viewer');
    }
}
