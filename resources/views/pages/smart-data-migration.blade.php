<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Wizard Steps Indicator --}}
        <div class="flex items-center gap-2 rounded-xl bg-gray-100 p-3 dark:bg-gray-800">
            @foreach([
                'upload' => __('Upload'),
                'analysis' => __('Schema Analysis'),
                'configure' => __('Configure'),
                'importing' => __('Importing'),
                'complete' => __('Complete'),
            ] as $stepKey => $stepLabel)
                <div @class([
                    'flex items-center gap-2',
                    'flex-1' => true,
                ])>
                    <div @class([
                        'flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold shrink-0',
                        'bg-primary-500 text-white' => $step === $stepKey,
                        'bg-success-500 text-white' => $this->isStepCompleted($stepKey),
                        'bg-gray-300 text-gray-600 dark:bg-gray-600 dark:text-gray-400' => $step !== $stepKey && !$this->isStepCompleted($stepKey),
                    ])>
                        @if($this->isStepCompleted($stepKey))
                            <x-filament::icon icon="heroicon-o-check" class="w-4 h-4" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </div>
                    <span @class([
                        'text-sm font-medium hidden sm:inline',
                        'text-gray-950 dark:text-white' => $step === $stepKey,
                        'text-success-600 dark:text-success-400' => $this->isStepCompleted($stepKey),
                        'text-gray-400 dark:text-gray-500' => $step !== $stepKey && !$this->isStepCompleted($stepKey),
                    ])>{{ $stepLabel }}</span>
                </div>
                @if(!$loop->last)
                    <div class="flex-shrink-0 w-8 h-px bg-gray-300 dark:bg-gray-600"></div>
                @endif
            @endforeach
        </div>

        {{-- ═══ STEP: Upload ═══ --}}
        @if($step === 'upload')
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-primary-50 dark:bg-primary-500/10">
                        <x-filament::icon icon="heroicon-o-arrow-up-tray" class="w-5 h-5 text-primary-500" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Smart Export') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Export data with schema metadata') }}</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    {{ __('Creates a JSON file containing all your data plus schema information. This format allows smart import into databases with different structures.') }}
                </p>
                @if ($this->canSmartExport())
                    <button wire:click="smartExport" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-4 h-4" />
                        {{ __('Download Smart Export') }}
                    </button>
                @else
                    <p class="text-xs text-gray-500 italic">{{ __('Export is disabled.') }}</p>
                @endif
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg bg-warning-50 dark:bg-warning-500/10">
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-5 h-5 text-warning-500" />
                    </div>
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Smart Import') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Import data from a different database structure') }}</p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    {{ __('Upload a Smart Export JSON file. The system will analyze schema differences, let you review column mappings, and import data with automatic ID remapping.') }}
                </p>
                <div x-data>
                    <button type="button" x-on:click="$refs.smartImportInput.click()"
                        class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-warning bg-warning-600 text-white hover:bg-warning-500 dark:bg-warning-500 dark:hover:bg-warning-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                        <x-filament::icon icon="heroicon-o-document-arrow-up" class="w-4 h-4" />
                        {{ __('Upload Smart Export File') }}
                    </button>
                    <input type="file" x-ref="smartImportInput" accept=".json" class="hidden"
                        x-on:change="
                            const file = $event.target.files[0];
                            if (!file) return;
                            const reader = new FileReader();
                            reader.onload = (e) => {
                                const base64 = btoa(new Uint8Array(e.target.result).reduce((data, byte) => data + String.fromCharCode(byte), ''));
                                $wire.handleUpload(base64, file.name);
                            };
                            reader.readAsArrayBuffer(file);
                            $event.target.value = '';
                        " />
                </div>
            </div>
        </div>

        <div class="rounded-xl bg-info-50 dark:bg-info-500/10 border border-info-200 dark:border-info-500/20 p-4">
            <div class="flex gap-3">
                <x-filament::icon icon="heroicon-o-information-circle" class="w-5 h-5 text-info-500 shrink-0 mt-0.5" />
                <div class="text-sm text-info-700 dark:text-info-400">
                    <p class="font-medium mb-1">{{ __('How Smart Migration Works') }}</p>
                    <ul class="list-disc list-inside space-y-1 text-xs">
                        <li>{{ __('Export bundles your data with schema metadata (column names, types, foreign keys).') }}</li>
                        <li>{{ __('On import the system compares the exported schema with your current database.') }}</li>
                        <li>{{ __('You can review and adjust column mappings for renamed or restructured columns.') }}</li>
                        <li>{{ __('Foreign-key relationships are remapped to the new auto-increment IDs.') }}</li>
                        <li>{{ __('Self-references and circular FKs are handled with deferred updates.') }}</li>
                    </ul>
                </div>
            </div>
        </div>
        @endif

        {{-- ═══ STEP: Schema Analysis ═══ --}}
        @if($step === 'analysis')
        <div class="space-y-4">

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach([
                    ['label' => __('Total Tables'), 'value' => $diffSummary['total_tables'] ?? 0, 'color' => 'gray'],
                    ['label' => __('Full Match'), 'value' => $diffSummary['matched'] ?? 0, 'color' => 'success'],
                    ['label' => __('Partial Match'), 'value' => $diffSummary['partial'] ?? 0, 'color' => 'warning'],
                    ['label' => __('Skipped'), 'value' => $diffSummary['skipped'] ?? 0, 'color' => 'danger'],
                ] as $card)
                    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 text-center">
                        <p class="text-2xl font-bold text-{{ $card['color'] }}-600 dark:text-{{ $card['color'] }}-400">{{ $card['value'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $card['label'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Table Analysis') }}</h3>
                </div>

                <div class="divide-y divide-gray-200 dark:divide-gray-700 max-h-[500px] overflow-y-auto">
                    @foreach($diffTables as $table => $info)
                        <div x-data="{ expanded: {{ $info['status'] === 'partial' ? 'true' : 'false' }} }" class="px-4 py-3">
                            <div class="flex items-center justify-between cursor-pointer" x-on:click="expanded = !expanded">
                                <div class="flex items-center gap-3">
                                    @if($info['status'] === 'match')
                                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-success-500"></span>
                                    @else
                                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-warning-500"></span>
                                    @endif
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $table }}</span>
                                    <span class="text-xs text-gray-400">{{ $info['matched'] }} {{ __('columns matched') }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if(!empty($info['dropped']))
                                        <span class="inline-flex items-center rounded-md bg-danger-50 dark:bg-danger-500/10 px-2 py-0.5 text-xs text-danger-600 dark:text-danger-400">
                                            {{ count($info['dropped']) }} {{ __('dropped') }}
                                        </span>
                                    @endif
                                    @if(!empty($info['added']))
                                        <span class="inline-flex items-center rounded-md bg-info-50 dark:bg-info-500/10 px-2 py-0.5 text-xs text-info-600 dark:text-info-400">
                                            {{ count($info['added']) }} {{ __('new') }}
                                        </span>
                                    @endif
                                    @if(!empty($info['suggested_renames']))
                                        <span class="inline-flex items-center rounded-md bg-warning-50 dark:bg-warning-500/10 px-2 py-0.5 text-xs text-warning-600 dark:text-warning-400">
                                            {{ count($info['suggested_renames']) }} {{ __('renames') }}
                                        </span>
                                    @endif
                                    <x-filament::icon icon="heroicon-o-chevron-down" class="w-4 h-4 text-gray-400 transition-transform" x-bind:class="expanded && 'rotate-180'" />
                                </div>
                            </div>

                            <div x-show="expanded" x-collapse class="mt-3 space-y-2">
                                @if(!empty($info['dropped']))
                                    <div class="text-xs">
                                        <span class="font-medium text-danger-600 dark:text-danger-400">{{ __('Columns in export but not in current DB (will be ignored):') }}</span>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach($info['dropped'] as $col)
                                                <span class="inline-flex items-center rounded bg-danger-50 dark:bg-danger-500/10 px-1.5 py-0.5 text-danger-700 dark:text-danger-300 font-mono">{{ $col }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($info['added']))
                                    <div class="text-xs">
                                        <span class="font-medium text-info-600 dark:text-info-400">{{ __('New columns in current DB (will use defaults):') }}</span>
                                        <div class="flex flex-wrap gap-1 mt-1">
                                            @foreach($info['added'] as $col)
                                                <span class="inline-flex items-center rounded bg-info-50 dark:bg-info-500/10 px-1.5 py-0.5 text-info-700 dark:text-info-300 font-mono">{{ $col }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($info['type_mismatches']))
                                    <div class="text-xs">
                                        <span class="font-medium text-warning-600 dark:text-warning-400">{{ __('Type differences (data will be cast):') }}</span>
                                        <div class="mt-1 space-y-0.5">
                                            @foreach($info['type_mismatches'] as $col => $types)
                                                <div class="flex items-center gap-1 font-mono">
                                                    <span class="text-gray-600 dark:text-gray-400">{{ $col }}:</span>
                                                    <span class="text-danger-500">{{ $types['exported'] }}</span>
                                                    <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 text-gray-400" />
                                                    <span class="text-success-500">{{ $types['current'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if(!empty($info['suggested_renames']))
                                    <div class="text-xs">
                                        <span class="font-medium text-warning-600 dark:text-warning-400">{{ __('Suggested column renames:') }}</span>
                                        <div class="mt-1 space-y-1">
                                            @foreach($info['suggested_renames'] as $oldCol => $newCol)
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-gray-600 dark:text-gray-400">{{ $oldCol }}</span>
                                                    <x-filament::icon icon="heroicon-o-arrow-right" class="w-3 h-3 text-gray-400" />
                                                    <span class="font-mono text-primary-600 dark:text-primary-400">{{ $newCol }}</span>
                                                    @if(isset($columnMappings[$table][$oldCol]))
                                                        <button wire:click="rejectRename('{{ $table }}', '{{ $oldCol }}')" class="text-danger-500 hover:text-danger-700 text-xs underline">{{ __('Reject') }}</button>
                                                        <span class="text-success-500 text-xs">{{ __('Accepted') }}</span>
                                                    @else
                                                        <button wire:click="acceptRename('{{ $table }}', '{{ $oldCol }}', '{{ $newCol }}')" class="text-primary-500 hover:text-primary-700 text-xs underline">{{ __('Accept') }}</button>
                                                        <span class="text-gray-400 text-xs">{{ __('Rejected') }}</span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(!empty($skippedTables))
                    <div class="px-4 py-3 bg-danger-50 dark:bg-danger-500/5 border-t border-gray-200 dark:border-gray-700">
                        <p class="text-xs font-medium text-danger-600 dark:text-danger-400 mb-1">{{ __('Tables in export but not in current database (will be skipped):') }}</p>
                        <div class="flex flex-wrap gap-1">
                            @foreach($skippedTables as $skippedTable)
                                <span class="inline-flex items-center rounded bg-danger-100 dark:bg-danger-500/10 px-1.5 py-0.5 text-xs text-danger-700 dark:text-danger-300 font-mono">{{ $skippedTable }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex justify-between">
                <button wire:click="resetWizard" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ring-1 ring-gray-300 dark:ring-gray-600 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    {{ __('Start Over') }}
                </button>
                <button wire:click="proceedToConfigure" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    {{ __('Continue to Configure') }}
                    <x-filament::icon icon="heroicon-o-arrow-right" class="w-4 h-4" />
                </button>
            </div>
        </div>
        @endif

        {{-- ═══ STEP: Configure ═══ --}}
        @if($step === 'configure')
        <div class="space-y-4">

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 space-y-4">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Import Options') }}</h3>

                @if(config('filament-system-tools.smart_migration.scope_resolver'))
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="applyScope" class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                    <div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Apply current scope') }}</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Override the configured scope column on every imported row.') }}</p>
                    </div>
                </label>
                @endif

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="preserveTimestamps" class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                    <div>
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Preserve timestamps') }}</span>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Keep original created_at and updated_at values from the export') }}</p>
                    </div>
                </label>

                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('When a record already exists') }}</p>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="onDuplicate" value="skip" class="fi-radio-input border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                            <div>
                                <span class="text-sm text-gray-900 dark:text-gray-100">{{ __('Skip') }}</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Keep existing record unchanged') }}</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" wire:model.live="onDuplicate" value="update" class="fi-radio-input border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                            <div>
                                <span class="text-sm text-gray-900 dark:text-gray-100">{{ __('Update') }}</span>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Overwrite existing record with imported data') }}</p>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Select Tables to Import') }}</h3>
                    <div class="flex items-center gap-3">
                        <button wire:click="selectAllTables" type="button" class="text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400">{{ __('Select all') }}</button>
                        <button wire:click="deselectAllTables" type="button" class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400">{{ __('Deselect all') }}</button>
                        <span class="text-xs text-gray-500">{{ count($selectedTables) }} / {{ count($diffTables) }} {{ __('selected') }}</span>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                    @foreach($diffTables as $table => $info)
                        <label class="flex items-center gap-3 px-4 py-2.5 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition">
                            <input type="checkbox"
                                wire:click="toggleTable('{{ $table }}')"
                                @checked(in_array($table, $selectedTables))
                                class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700">
                            <div class="flex items-center gap-2 flex-1 min-w-0">
                                <span class="text-sm text-gray-900 dark:text-gray-100 font-mono">{{ $table }}</span>
                                @if($info['status'] === 'match')
                                    <span class="inline-block w-2 h-2 rounded-full bg-success-500"></span>
                                @else
                                    <span class="inline-block w-2 h-2 rounded-full bg-warning-500"></span>
                                @endif
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="rounded-xl bg-warning-50 dark:bg-warning-500/10 border border-warning-200 dark:border-warning-500/20 p-4">
                <div class="flex gap-3">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5 text-warning-500 shrink-0 mt-0.5" />
                    <div class="text-sm text-warning-700 dark:text-warning-400">
                        <p class="font-medium">{{ __('Important') }}</p>
                        <p class="text-xs mt-1">{{ __('This will create new records in your database. A database backup is strongly recommended before proceeding.') }}</p>
                    </div>
                </div>
            </div>

            <div class="flex justify-between">
                <button wire:click="backToAnalysis" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-gray bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ring-1 ring-gray-300 dark:ring-gray-600 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </button>
                <button wire:click="runImport"
                    wire:confirm="{{ __('Start the import? This will create new records in your database.') }}"
                    wire:loading.attr="disabled"
                    @disabled(! $this->canRunImport())
                    class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-success bg-success-600 text-white hover:bg-success-500 dark:bg-success-500 dark:hover:bg-success-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                    <span wire:loading.remove wire:target="runImport">
                        <x-filament::icon icon="heroicon-o-play" class="w-4 h-4 inline" />
                        {{ $this->canRunImport() ? __('Start Import') : __('Import disabled') }}
                    </span>
                    <span wire:loading wire:target="runImport">
                        <x-filament::loading-indicator class="w-4 h-4 inline" />
                        {{ __('Importing...') }}
                    </span>
                </button>
            </div>
        </div>
        @endif

        {{-- ═══ STEP: Importing ═══ --}}
        @if($step === 'importing')
        <div class="space-y-4">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <div class="flex items-center gap-3">
                    <x-filament::loading-indicator class="w-6 h-6 text-primary-500" />
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">{{ __('Importing Data...') }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Please wait while your data is being imported. Do not close this page.') }}</p>
                    </div>
                </div>

                @php
                    $totalTables = count($importProgress);
                    $completedTables = count(array_filter($importProgress, fn ($p) => in_array($p['status'], ['success', 'partial', 'failed'])));
                    $progressPercent = $totalTables > 0 ? round(($completedTables / $totalTables) * 100) : 0;
                @endphp
                <div class="mt-4">
                    <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                        <span>{{ $completedTables }} / {{ $totalTables }} {{ __('tables') }}</span>
                        <span>{{ $progressPercent }}%</span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                        <div class="bg-primary-500 h-2 rounded-full transition-all duration-300" style="width: {{ $progressPercent }}%"></div>
                    </div>
                </div>
            </div>

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                    @foreach($importProgress as $table => $progress)
                        <div class="flex items-center justify-between px-4 py-2.5">
                            <div class="flex items-center gap-3">
                                @if($progress['status'] === 'pending')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-gray-200 dark:bg-gray-700">
                                        <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    </span>
                                @elseif($progress['status'] === 'importing')
                                    <x-filament::loading-indicator class="w-5 h-5 text-primary-500" />
                                @elseif($progress['status'] === 'success')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-success-100 dark:bg-success-500/20">
                                        <x-filament::icon icon="heroicon-o-check" class="w-3.5 h-3.5 text-success-600 dark:text-success-400" />
                                    </span>
                                @elseif($progress['status'] === 'partial')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-warning-100 dark:bg-warning-500/20">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 text-warning-600 dark:text-warning-400" />
                                    </span>
                                @elseif($progress['status'] === 'failed')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-danger-100 dark:bg-danger-500/20">
                                        <x-filament::icon icon="heroicon-o-x-mark" class="w-3.5 h-3.5 text-danger-600 dark:text-danger-400" />
                                    </span>
                                @endif
                                <span class="text-sm font-mono text-gray-900 dark:text-gray-100">{{ $table }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs">
                                @if($progress['status'] !== 'pending')
                                    @if($progress['imported'] > 0)
                                        <span class="text-success-600 dark:text-success-400 font-medium">{{ $progress['imported'] }} {{ __('imported') }}</span>
                                    @endif
                                    @if($progress['skipped'] > 0)
                                        <span class="text-warning-600 dark:text-warning-400">{{ $progress['skipped'] }} {{ __('skipped') }}</span>
                                    @endif
                                    @if(!empty($progress['errors']))
                                        <span class="text-danger-600 dark:text-danger-400">{{ count($progress['errors']) }} {{ __('errors') }}</span>
                                    @endif
                                @else
                                    <span class="text-gray-400">{{ __('Waiting...') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- ═══ STEP: Complete ═══ --}}
        @if($step === 'complete')
        <div class="space-y-4">

            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <div class="flex items-center gap-3 mb-5">
                    @if(empty($importErrors))
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-success-100 dark:bg-success-500/10">
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-7 h-7 text-success-500" />
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-success-600 dark:text-success-400">{{ __('Import Completed Successfully') }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('All data has been imported into the database.') }}</p>
                        </div>
                    @else
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-warning-100 dark:bg-warning-500/10">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-7 h-7 text-warning-500" />
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-warning-600 dark:text-warning-400">{{ __('Import Completed with Issues') }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Some records could not be imported. See details below.') }}</p>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="text-center p-4 rounded-xl bg-primary-50 dark:bg-primary-500/10 ring-1 ring-primary-200 dark:ring-primary-500/20">
                        <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $importSummary['total_imported'] ?? 0 }}</p>
                        <p class="text-xs text-primary-600/70 dark:text-primary-400/70 mt-1 font-medium">{{ __('Records Imported') }}</p>
                    </div>
                    <div class="text-center p-4 rounded-xl bg-gray-50 dark:bg-gray-800 ring-1 ring-gray-200 dark:ring-gray-700">
                        <p class="text-2xl font-bold text-gray-600 dark:text-gray-400">{{ $importSummary['tables_imported'] ?? 0 }}</p>
                        <p class="text-xs text-gray-500 mt-1 font-medium">{{ __('Tables') }}</p>
                    </div>
                    <div class="text-center p-4 rounded-xl bg-warning-50 dark:bg-warning-500/10 ring-1 ring-warning-200 dark:ring-warning-500/20">
                        <p class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ $importSummary['total_skipped'] ?? 0 }}</p>
                        <p class="text-xs text-warning-600/70 dark:text-warning-400/70 mt-1 font-medium">{{ __('Skipped') }}</p>
                    </div>
                    <div class="text-center p-4 rounded-xl bg-danger-50 dark:bg-danger-500/10 ring-1 ring-danger-200 dark:ring-danger-500/20">
                        <p class="text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $importSummary['errors'] ?? 0 }}</p>
                        <p class="text-xs text-danger-600/70 dark:text-danger-400/70 mt-1 font-medium">{{ __('Errors') }}</p>
                    </div>
                </div>
            </div>

            @if(!empty($importProgress))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Import Report') }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                    @foreach($importProgress as $table => $progress)
                        <div class="flex items-center justify-between px-4 py-2.5">
                            <div class="flex items-center gap-3">
                                @if($progress['status'] === 'success')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-success-100 dark:bg-success-500/20">
                                        <x-filament::icon icon="heroicon-o-check" class="w-3.5 h-3.5 text-success-600 dark:text-success-400" />
                                    </span>
                                @elseif($progress['status'] === 'partial')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-warning-100 dark:bg-warning-500/20">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 text-warning-600 dark:text-warning-400" />
                                    </span>
                                @elseif($progress['status'] === 'failed')
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-danger-100 dark:bg-danger-500/20">
                                        <x-filament::icon icon="heroicon-o-x-mark" class="w-3.5 h-3.5 text-danger-600 dark:text-danger-400" />
                                    </span>
                                @else
                                    <span class="flex items-center justify-center w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-700">
                                        <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                                    </span>
                                @endif
                                <span class="text-sm font-mono text-gray-900 dark:text-gray-100">{{ $table }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs">
                                @if($progress['imported'] > 0)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-success-50 dark:bg-success-500/10 px-2 py-0.5 text-success-700 dark:text-success-400 font-medium">
                                        <x-filament::icon icon="heroicon-o-check" class="w-3 h-3" />
                                        {{ $progress['imported'] }}
                                    </span>
                                @endif
                                @if($progress['skipped'] > 0)
                                    <span class="inline-flex items-center gap-1 rounded-md bg-warning-50 dark:bg-warning-500/10 px-2 py-0.5 text-warning-700 dark:text-warning-400">
                                        {{ $progress['skipped'] }} {{ __('skipped') }}
                                    </span>
                                @endif
                                @if(!empty($progress['errors']))
                                    <span class="inline-flex items-center gap-1 rounded-md bg-danger-50 dark:bg-danger-500/10 px-2 py-0.5 text-danger-700 dark:text-danger-400">
                                        {{ count($progress['errors']) }} {{ __('errors') }}
                                    </span>
                                @endif
                                @if($progress['imported'] === 0 && $progress['skipped'] === 0 && empty($progress['errors']))
                                    <span class="text-gray-400">{{ __('No data') }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            @if(!empty($importErrors))
            <div x-data="{ expanded: {{ count($importErrors) <= 10 ? 'true' : 'false' }} }" class="fi-section rounded-xl bg-danger-50 dark:bg-danger-500/5 ring-1 ring-danger-200 dark:ring-danger-500/20 overflow-hidden">
                <div class="px-4 py-3 border-b border-danger-200 dark:border-danger-500/20 flex items-center justify-between cursor-pointer" x-on:click="expanded = !expanded">
                    <h3 class="text-sm font-semibold text-danger-700 dark:text-danger-400">
                        <x-filament::icon icon="heroicon-o-x-circle" class="w-4 h-4 inline -mt-0.5" />
                        {{ __('Errors') }} ({{ count($importErrors) }})
                    </h3>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="w-4 h-4 text-danger-400 transition-transform" x-bind:class="expanded && 'rotate-180'" />
                </div>
                <div x-show="expanded" x-collapse class="p-4 max-h-[250px] overflow-y-auto">
                    <ul class="space-y-1.5">
                        @foreach($importErrors as $error)
                            <li class="text-xs text-danger-600 dark:text-danger-400 font-mono flex items-start gap-2">
                                <span class="text-danger-400 mt-0.5 shrink-0">&bull;</span>
                                <span>{{ $error }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endif

            @if(!empty($importWarnings))
            <div x-data="{ expanded: {{ count($importWarnings) <= 10 ? 'true' : 'false' }} }" class="fi-section rounded-xl bg-warning-50 dark:bg-warning-500/5 ring-1 ring-warning-200 dark:ring-warning-500/20 overflow-hidden">
                <div class="px-4 py-3 border-b border-warning-200 dark:border-warning-500/20 flex items-center justify-between cursor-pointer" x-on:click="expanded = !expanded">
                    <h3 class="text-sm font-semibold text-warning-700 dark:text-warning-400">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-4 h-4 inline -mt-0.5" />
                        {{ __('Warnings') }} ({{ count($importWarnings) }})
                    </h3>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="w-4 h-4 text-warning-400 transition-transform" x-bind:class="expanded && 'rotate-180'" />
                </div>
                <div x-show="expanded" x-collapse class="p-4 max-h-[250px] overflow-y-auto">
                    <ul class="space-y-1.5">
                        @foreach($importWarnings as $warning)
                            <li class="text-xs text-warning-600 dark:text-warning-400 font-mono flex items-start gap-2">
                                <span class="text-warning-400 mt-0.5 shrink-0">&bull;</span>
                                <span>{{ $warning }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            @endif

            <div class="flex justify-center">
                <button wire:click="resetWizard" class="fi-btn fi-btn-size-md relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="w-4 h-4" />
                    {{ __('New Migration') }}
                </button>
            </div>
        </div>
        @endif
    </div>
</x-filament-panels::page>
