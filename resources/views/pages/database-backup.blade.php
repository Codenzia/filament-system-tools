<x-filament-panels::page>
    {{-- Stats Row --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        @php $stats = $this->getDatabaseStats(); @endphp
        <x-filament::section>
            <x-slot name="heading">{{ __('Database Driver') }}</x-slot>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ strtoupper($stats['driver']) }}</p>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('Database Size') }}</x-slot>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['size'] }}</p>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('Total Tables') }}</x-slot>
            <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['tables'] }}</p>
        </x-filament::section>
    </div>

    {{-- Tables & Export Section --}}
    <x-filament::section class="mb-6">
        <x-slot name="heading">
            @svg('heroicon-o-arrow-up-tray', 'size-5 inline-block -mt-0.5 me-1')
            {{ __('Export Database') }}
        </x-slot>
        <x-slot name="description">{{ __('Select tables using checkboxes, then use the bulk actions toolbar to export as SQL or JSON.') }}</x-slot>

        {{ $this->table }}
    </x-filament::section>

    {{-- Import Section --}}
    <x-filament::section class="mb-6">
        <x-slot name="heading">
            @svg('heroicon-o-arrow-down-tray', 'size-5 inline-block -mt-0.5 me-1')
            {{ __('Import Database') }}
        </x-slot>
        <x-slot name="description">{{ __('Upload a .sql or .json export file to import data into the database.') }}</x-slot>

        <div class="flex flex-col sm:flex-row items-start sm:items-end gap-4">
            <div class="w-full sm:w-auto sm:flex-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    {{ __('Select File') }}
                </label>
                <input type="file" wire:model="importFile" accept=".sql,.json"
                    class="block w-full text-sm text-gray-500 dark:text-gray-400
                        file:me-3 file:py-2 file:px-4 file:rounded-lg file:border-0
                        file:text-sm file:font-medium
                        file:bg-primary-50 file:text-primary-700
                        dark:file:bg-primary-950 dark:file:text-primary-400
                        hover:file:bg-primary-100 dark:hover:file:bg-primary-900
                        file:cursor-pointer cursor-pointer">
            </div>
            <x-filament::button
                wire:click="importFromFile"
                color="warning"
                icon="heroicon-o-arrow-down-tray"
                wire:confirm="{{ __('This will import data into your database. Existing records may be affected. Are you sure?') }}"
                :disabled="!$importFile">
                {{ __('Import') }}
            </x-filament::button>
        </div>

        @if ($importFile)
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                {{ __('File ready:') }} {{ $importFile->getClientOriginalName() }}
                ({{ number_format($importFile->getSize() / 1024, 1) }} KB)
            </p>
        @endif
    </x-filament::section>

    {{-- Backup & Restore --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Create Backup --}}
        <x-filament::section>
            <x-slot name="heading">
                @svg('heroicon-o-shield-check', 'size-5 inline-block -mt-0.5 me-1')
                {{ __('Full Backup') }}
            </x-slot>
            <x-slot name="description">{{ __('Pick a connection, optionally gzip the dump or restrict to specific tables.') }}</x-slot>
            <div class="w-full">
                {{ $this->createBackupAction }}
            </div>
        </x-filament::section>

        {{-- Existing Backups --}}
        <x-filament::section>
            <x-slot name="heading">
                @svg('heroicon-o-archive-box', 'size-5 inline-block -mt-0.5 me-1')
                {{ __('Existing Backups') }}
            </x-slot>
            @php $backups = $this->getBackupFiles(); @endphp
            @if (count($backups) > 0)
                <div class="space-y-2">
                    @foreach ($backups as $backup)
                        <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <div class="flex items-center justify-between">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $backup['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $backup['size'] }} · {{ $backup['date'] }}</p>
                                </div>
                                <div class="flex items-center gap-1 ms-3 shrink-0">
                                    @if ($this->canDownloadBackup())
                                        <x-filament::button size="sm" color="gray" outlined icon="heroicon-o-arrow-down-tray"
                                            wire:click="downloadBackup('{{ $backup['name'] }}')"
                                            title="{{ __('Download') }}" />
                                    @endif
                                    @if ($this->canRestoreBackup())
                                        <x-filament::button size="sm" color="warning" outlined icon="heroicon-o-arrow-path"
                                            wire:click="beginRestore('{{ $backup['name'] }}')"
                                            title="{{ __('Restore') }}" />
                                    @endif
                                    @if ($this->canDeleteBackup())
                                        <x-filament::button size="sm" color="danger" outlined icon="heroicon-o-trash"
                                            wire:click="deleteBackup('{{ $backup['name'] }}')"
                                            wire:confirm="{{ __('Are you sure you want to delete this backup?') }}"
                                            title="{{ __('Delete') }}" />
                                    @endif
                                </div>
                            </div>

                            @if ($restoreFile === $backup['name'])
                                <div class="mt-3 space-y-3 border-t border-gray-200 dark:border-gray-600 pt-3">
                                    <p class="text-sm text-danger-600 dark:text-danger-400">
                                        {{ __('Restoring overwrites every table in the target database. Choose the target and type its name to continue.') }}
                                    </p>

                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('Target connection') }}</span>
                                            <select wire:model.live="restoreConnection"
                                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                                <option value="">{{ __('Select a connection') }}</option>
                                                @foreach ($this->getConnectionOptions() as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="block">
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                                {{ __('Type the connection name to confirm') }}
                                            </span>
                                            <input type="text" wire:model.live="restoreConfirmation"
                                                placeholder="{{ $restoreConnection ?? __('Select a connection first') }}"
                                                class="fi-input mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" />
                                        </label>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <x-filament::button size="sm" color="danger" icon="heroicon-o-arrow-path"
                                            wire:click="restoreBackup"
                                            wire:loading.attr="disabled"
                                            :disabled="blank($restoreConnection) || trim($restoreConfirmation) !== $restoreConnection">
                                            {{ __('Restore into :connection', ['connection' => $restoreConnection ?: __('target')]) }}
                                        </x-filament::button>
                                        <x-filament::button size="sm" color="gray" outlined wire:click="cancelRestore">
                                            {{ __('Cancel') }}
                                        </x-filament::button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-6">
                    @svg('heroicon-o-circle-stack', 'size-12 text-gray-400 mx-auto mb-2')
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No backups found. Create your first backup above.') }}
                    </p>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
