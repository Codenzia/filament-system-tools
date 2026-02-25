<?php

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
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class TableDataViewer extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public string $tableName = '';

    public function mount(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    public function table(Table $table): Table
    {
        $model = DynamicTableModel::forTable($this->tableName);
        $columnNames = Schema::getColumnListing($this->tableName);
        $columnInfo = collect(Schema::getColumns($this->tableName))->keyBy('name');

        // Build dynamic table columns
        $columns = collect($columnNames)->map(function (string $name) use ($columnInfo): TextColumn {
            $col = $columnInfo->get($name, []);
            $column = TextColumn::make($name)
                ->sortable()
                ->searchable()
                ->size('sm')
                ->fontFamily('mono')
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
                    ->slideOver()
                    ->modalWidth(Width::FourExtraLarge)
                    ->form($this->buildRowForm())
                    ->action(function (array $data): void {
                        $this->insertRow($data);
                    }),
            ])
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('editRow')
                        ->label(__('Edit'))
                        ->icon('heroicon-o-pencil-square')
                        ->slideOver()
                        ->modalWidth(Width::FourExtraLarge)
                        ->form($this->buildRowForm())
                        ->fillForm(fn ($record): array => $record->toArray())
                        ->action(function (array $data, $record): void {
                            $this->updateRow($record, $data);
                        }),
                    Actions\Action::make('deleteRow')
                        ->label(__('Delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($record): void {
                            $this->deleteRow($record);
                        }),
                ]),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('No data'))
            ->emptyStateDescription(__('This table has no rows.'));
    }

    // ──────────────────────────────────────────────
    // Row Operations
    // ──────────────────────────────────────────────

    private function insertRow(array $data): void
    {
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
        try {
            $model = DynamicTableModel::forTable($this->tableName);
            $primaryKey = $model->getKeyName();
            $keyValue = $record->{$primaryKey};

            $updateData = collect($data)->map(fn ($value) => $value === '' ? null : $value)->toArray();

            DB::table($this->tableName)
                ->where($primaryKey, $keyValue)
                ->update($updateData);

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
        try {
            $model = DynamicTableModel::forTable($this->tableName);
            $primaryKey = $model->getKeyName();
            $keyValue = $record->{$primaryKey};

            DB::table($this->tableName)->where($primaryKey, $keyValue)->delete();

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

    // ──────────────────────────────────────────────
    // Form Builder
    // ──────────────────────────────────────────────

    /** @return array<\Filament\Forms\Components\Component> */
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
