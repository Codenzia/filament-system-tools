<div class="space-y-4">
    {{-- SQL Editor --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            {{ __('SQL Query') }}
        </label>
        <textarea
            wire:model="sql"
            rows="6"
            class="fi-input block w-full rounded-lg border-gray-300 bg-white font-mono text-sm
                shadow-sm transition duration-75
                focus:border-primary-500 focus:ring-1 focus:ring-primary-500
                dark:border-white/10 dark:bg-white/5 dark:text-white dark:focus:border-primary-500
                p-3"
            placeholder="SELECT * FROM {{ $tableName }} LIMIT 100"
            spellcheck="false"
        ></textarea>
    </div>

    {{-- Execute Button --}}
    <div class="flex items-center gap-3">
        <x-filament::button
            wire:click="execute"
            icon="heroicon-o-play"
            color="primary"
            wire:loading.attr="disabled"
        >
            <span wire:loading.remove wire:target="execute">{{ __('Execute') }}</span>
            <span wire:loading wire:target="execute">{{ __('Running...') }}</span>
        </x-filament::button>

        @if ($message)
            <p class="text-sm text-success-600 dark:text-success-400">
                {{ $message }}
            </p>
        @endif
    </div>

    {{-- Error Display --}}
    @if ($error)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 dark:border-danger-600 dark:bg-danger-950">
            <div class="flex items-start gap-2">
                @svg('heroicon-o-exclamation-triangle', 'size-5 text-danger-500 shrink-0 mt-0.5')
                <div>
                    <p class="text-sm font-medium text-danger-800 dark:text-danger-200">{{ __('Query Error') }}</p>
                    <p class="text-sm text-danger-700 dark:text-danger-300 font-mono mt-1">{{ $error }}</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Results Table --}}
    @if (count($results) > 0)
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" style="max-height: 400px; overflow-y: auto;">
            <table class="w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                <thead class="bg-gray-50 dark:bg-white/5 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400">#</th>
                        @foreach ($columns as $col)
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                {{ $col }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                    @foreach ($results as $index => $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition">
                            <td class="px-3 py-1.5 text-xs text-gray-400 dark:text-gray-500 font-mono">{{ $index + 1 }}</td>
                            @foreach ($columns as $col)
                                <td class="px-3 py-1.5 text-sm font-mono text-gray-900 dark:text-white whitespace-nowrap max-w-xs truncate"
                                    title="{{ $row[$col] ?? '' }}">
                                    @if (is_null($row[$col] ?? null))
                                        <span class="text-gray-300 dark:text-gray-600 italic">NULL</span>
                                    @else
                                        {{ \Illuminate\Support\Str::limit((string) $row[$col], 100) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
