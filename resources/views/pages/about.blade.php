<x-filament-panels::page>
    @php
        $info = $this->getSystemInfo();
        $release = $this->getReleaseInfo();
    @endphp

    {{-- Release Info Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <div class="flex items-center gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400">
                    @svg('heroicon-o-tag', 'size-5')
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Version') }}</p>
                    <p class="text-lg font-bold text-gray-950 dark:text-white font-mono">{{ $release['version'] }}
                    </p>
                </div>
            </div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <div class="flex items-center gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-success-100 dark:bg-success-900/50 text-success-600 dark:text-success-400">
                    @svg('heroicon-o-rocket-launch', 'size-5')
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Release Name') }}</p>
                    <p class="text-lg font-bold text-gray-950 dark:text-white">{{ $release['name'] }}</p>
                </div>
            </div>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-5 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <div class="flex items-center gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 dark:bg-warning-900/50 text-warning-600 dark:text-warning-400">
                    @svg('heroicon-o-calendar', 'size-5')
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Release Date') }}</p>
                    <p class="text-lg font-bold text-gray-950 dark:text-white font-mono">{{ $release['date'] }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- System Information --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Environment --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-code-bracket', 'size-5 text-primary-500')
                    {{ __('Environment') }}
                </div>
            </x-slot>
            <dl class="space-y-3">
                @foreach ($info['environment'] as $label => $value)
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd
                            class="text-sm font-medium font-mono text-gray-950 dark:text-white
                            @if ($label === __('Debug Mode')) {{ $value === __('Enabled') ? 'text-warning-600! dark:text-warning-400!' : '' }} @endif
                            @if ($label === __('Environment')) {{ $value === 'production' ? 'text-danger-600! dark:text-danger-400!' : 'text-success-600! dark:text-success-400!' }} @endif
                        ">
                            {{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Server --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-server', 'size-5 text-info-500')
                    {{ __('Server') }}
                </div>
            </x-slot>
            <dl class="space-y-3">
                @foreach ($info['server'] as $label => $value)
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm font-medium font-mono text-gray-950 dark:text-white">{{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Database --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-circle-stack', 'size-5 text-success-500')
                    {{ __('Database') }}
                </div>
            </x-slot>
            <dl class="space-y-3">
                @foreach ($info['database'] as $label => $value)
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm font-medium font-mono text-gray-950 dark:text-white">{{ $value }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        {{-- Drivers --}}
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    @svg('heroicon-o-puzzle-piece', 'size-5 text-warning-500')
                    {{ __('Drivers') }}
                </div>
            </x-slot>
            <dl class="space-y-3">
                @foreach ($info['drivers'] as $label => $value)
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-sm font-medium font-mono text-gray-950 dark:text-white">{{ ucfirst($value) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    </div>

    {{-- Storage (full width) --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                @svg('heroicon-o-folder', 'size-5 text-gray-500')
                {{ __('Storage') }}
            </div>
        </x-slot>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
            @foreach ($info['storage'] as $label => $value)
                <div class="text-center p-3 rounded-lg bg-gray-50 dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p
                        class="mt-1 text-lg font-semibold font-mono text-gray-950 dark:text-white
                        @if ($label === __('Disk Usage')) @php $pct = (float) str_replace('%', '', $value); @endphp
                            {{ $pct > 90 ? 'text-danger-600! dark:text-danger-400!' : ($pct > 70 ? 'text-warning-600! dark:text-warning-400!' : '') }} @endif
                    ">
                        {{ $value }}
                    </p>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
