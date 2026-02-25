<x-filament-panels::page>
    @php
        $logFiles = $this->getLogFiles();
        $entries = $this->getLogEntries();
        $levels = $this->getLogLevels();
    @endphp

    {{-- Log Files Overview --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Log Files') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ count($logFiles) }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Current File') }}</p>
            <p class="mt-1 text-sm font-semibold text-gray-950 dark:text-white font-mono truncate">{{ $logFiles[0]['name'] ?? '—' }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('File Size') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ $logFiles[0]['size'] ?? '—' }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Entries Shown') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ count($entries) }}</p>
        </div>
    </div>

    {{-- Log Viewer Card --}}
    <div @if ($this->autoRefresh) wire:poll.10s @endif
        class="rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-sm overflow-hidden">
        {{-- Controls Bar --}}
        <div class="flex items-center gap-4 px-4 py-3 border-b border-gray-200 dark:border-white/10 flex-wrap">
            <div class="flex items-center gap-2">
                <label for="lines" class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Lines') }}:</label>
                <select wire:model.live="lines" id="lines"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-xs py-1.5 px-2">
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="500">500</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <label for="level" class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Level') }}:</label>
                <select wire:model.live="level" id="level"
                    class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white text-xs py-1.5 px-2">
                    @foreach ($levels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2 ms-auto">
                <button wire:click="$toggle('autoRefresh')"
                    @class([
                        'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium transition',
                        'border-green-300 dark:border-green-500/30 text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-500/10' => $this->autoRefresh,
                        'border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' => ! $this->autoRefresh,
                    ])>
                    @if ($this->autoRefresh)
                        @svg('heroicon-o-arrow-path', 'size-3.5 animate-spin')
                    @else
                        @svg('heroicon-o-arrow-path', 'size-3.5')
                    @endif
                    {{ $this->autoRefresh ? __('Auto: ON') : __('Auto: OFF') }}
                </button>

                <button wire:click="$refresh"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-700 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    @svg('heroicon-o-arrow-path', 'size-3.5')
                    {{ __('Refresh') }}
                </button>

                <button wire:click="downloadLog"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-700 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    @svg('heroicon-o-arrow-down-tray', 'size-3.5')
                    {{ __('Download') }}
                </button>

                @if ($this->canClearLog())
                    <button wire:click="clearLog" wire:confirm="{{ __('Are you sure you want to clear the log file?') }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 dark:border-red-500/30 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition">
                        @svg('heroicon-o-trash', 'size-3.5')
                        {{ __('Clear Log') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Log Table --}}
        <div class="overflow-x-auto min-h-64 max-h-160 overflow-y-auto">
            @if (empty($entries))
                <div class="flex flex-col items-center justify-center py-20 text-gray-400 dark:text-gray-500">
                    @svg('heroicon-o-document-text', 'size-10 mb-3')
                    <p class="text-sm font-medium">{{ __('No log entries found') }}</p>
                    <p class="text-xs mt-1 text-gray-400 dark:text-gray-600">{{ __('The log file may be empty or doesn\'t exist yet.') }}</p>
                </div>
            @else
                <table class="w-full text-left">
                    <thead class="bg-gray-50 dark:bg-gray-800/50 sticky top-0">
                        <tr>
                            <th class="px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-48">{{ __('Timestamp') }}</th>
                            <th class="px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider w-28">{{ __('Level') }}</th>
                            <th class="px-4 py-2.5 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('Message') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($entries as $index => $entry)
                            @php
                                $levelColors = [
                                    'emergency' => 'bg-red-100 text-red-700 ring-red-600/10 dark:bg-red-500/15 dark:text-red-400 dark:ring-red-500/20',
                                    'alert' => 'bg-red-100 text-red-700 ring-red-600/10 dark:bg-red-500/15 dark:text-red-400 dark:ring-red-500/20',
                                    'critical' => 'bg-red-100 text-red-700 ring-red-600/10 dark:bg-red-500/15 dark:text-red-400 dark:ring-red-500/20',
                                    'error' => 'bg-orange-100 text-orange-700 ring-orange-600/10 dark:bg-orange-500/15 dark:text-orange-400 dark:ring-orange-500/20',
                                    'warning' => 'bg-amber-100 text-amber-700 ring-amber-600/10 dark:bg-amber-500/15 dark:text-amber-400 dark:ring-amber-500/20',
                                    'notice' => 'bg-blue-100 text-blue-700 ring-blue-600/10 dark:bg-blue-500/15 dark:text-blue-400 dark:ring-blue-500/20',
                                    'info' => 'bg-green-100 text-green-700 ring-green-600/10 dark:bg-green-500/15 dark:text-green-400 dark:ring-green-500/20',
                                    'debug' => 'bg-gray-100 text-gray-700 ring-gray-600/10 dark:bg-gray-500/15 dark:text-gray-400 dark:ring-gray-500/20',
                                ];
                                $color = $levelColors[strtolower($entry['level'])] ?? $levelColors['debug'];
                            @endphp
                            <tr x-data="{ expanded: false }" class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors">
                                <td class="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-mono whitespace-nowrap align-top">
                                    {{ $entry['timestamp'] }}
                                </td>
                                <td class="px-4 py-2.5 align-top">
                                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $color }}">
                                        {{ strtoupper($entry['level']) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 align-top">
                                    <p class="text-gray-950 dark:text-gray-200 break-all font-mono text-xs leading-relaxed cursor-pointer"
                                        @click="expanded = !expanded"
                                        :class="{ 'line-clamp-2': !expanded }"
                                        :title="expanded ? '{{ __('Click to collapse') }}' : '{{ __('Click to expand') }}'">
                                        {{ $entry['message'] }}
                                    </p>
                                    @if ($entry['context'])
                                        <pre x-show="expanded" x-collapse
                                            class="mt-2 p-3 rounded-lg bg-gray-100 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-400 font-mono overflow-x-auto whitespace-pre-wrap max-h-64 overflow-y-auto">{{ $entry['context'] }}</pre>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Footer --}}
        <div class="border-t border-gray-200 dark:border-white/10 px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
            {{ count($entries) }} {{ __('entries shown') }}
            @if ($this->level !== 'all')
                &middot; {{ __('Filtered') }}: {{ ucfirst($this->level) }}
            @endif
            @if (isset($logFiles[0]))
                &middot; {{ $logFiles[0]['name'] }}
            @endif
            @if ($this->autoRefresh)
                &middot; <span class="text-green-600 dark:text-green-400">{{ __('Auto-refresh: 10s') }}</span>
            @endif
        </div>
    </div>

    {{-- Log Files List --}}
    @if (count($logFiles) > 1)
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('All Log Files') }}</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('File') }}</th>
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Size') }}</th>
                            <th class="px-3 py-2 text-start text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Last Modified') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($logFiles as $file)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                <td class="px-3 py-2 font-mono text-xs text-gray-950 dark:text-white">{{ $file['name'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $file['size'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $file['date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
