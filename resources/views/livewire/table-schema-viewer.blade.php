<div class="space-y-4">
    {{-- Header with Add Column action --}}
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __(':count columns', ['count' => count($this->getColumns())]) }}
        </p>
        {{ $this->addColumnAction }}
    </div>

    {{-- Columns Table --}}
    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Column') }}</th>
                    <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Type') }}</th>
                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Nullable') }}</th>
                    <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Default') }}</th>
                    <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Auto Inc.') }}</th>
                    <th class="px-3 py-2 text-end text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @forelse ($this->getColumns() as $column)
                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                        <td class="px-3 py-2 text-sm font-mono text-gray-900 dark:text-white">
                            {{ $column['name'] }}
                        </td>
                        <td class="px-3 py-2 text-sm font-mono text-gray-600 dark:text-gray-300">
                            {{ $column['type'] ?? $column['type_name'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if ($column['nullable'] ?? false)
                                @svg('heroicon-o-check-circle', 'size-4 text-success-500 mx-auto')
                            @else
                                @svg('heroicon-o-x-circle', 'size-4 text-gray-300 dark:text-gray-600 mx-auto')
                            @endif
                        </td>
                        <td class="px-3 py-2 text-sm font-mono text-gray-600 dark:text-gray-300">
                            {{ $column['default'] ?? '—' }}
                        </td>
                        <td class="px-3 py-2 text-center">
                            @if ($column['auto_increment'] ?? false)
                                @svg('heroicon-o-check-circle', 'size-4 text-success-500 mx-auto')
                            @else
                                @svg('heroicon-o-x-circle', 'size-4 text-gray-300 dark:text-gray-600 mx-auto')
                            @endif
                        </td>
                        <td class="px-3 py-2 text-end">
                            <div class="flex items-center justify-end gap-1">
                                {{ ($this->editColumnAction)(['column' => $column['name']]) }}
                                @if ($this->supportsDropColumn())
                                    {{ ($this->deleteColumnAction)(['column' => $column['name']]) }}
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('No columns found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Indexes Section --}}
    @php $indexes = $this->getIndexes(); @endphp
    @if (count($indexes) > 0)
        <div class="mt-4">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('Indexes') }}</h4>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Name') }}</th>
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Columns') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Unique') }}</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Primary') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($indexes as $index)
                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                                <td class="px-3 py-2 text-sm font-mono text-gray-900 dark:text-white">{{ $index['name'] }}</td>
                                <td class="px-3 py-2 text-sm font-mono text-gray-600 dark:text-gray-300">
                                    {{ implode(', ', $index['columns'] ?? []) }}
                                </td>
                                <td class="px-3 py-2 text-center">
                                    @if ($index['unique'] ?? false)
                                        @svg('heroicon-o-check-circle', 'size-4 text-success-500 mx-auto')
                                    @else
                                        @svg('heroicon-o-x-circle', 'size-4 text-gray-300 dark:text-gray-600 mx-auto')
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center">
                                    @if ($index['primary'] ?? false)
                                        @svg('heroicon-o-check-circle', 'size-4 text-primary-500 mx-auto')
                                    @else
                                        @svg('heroicon-o-x-circle', 'size-4 text-gray-300 dark:text-gray-600 mx-auto')
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
