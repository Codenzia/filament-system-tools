<x-filament-panels::page>
    {{-- System Info Cards --}}
    @php $info = $this->getSystemInfo(); @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('PHP Version') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ $info['PHP Version'] }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Laravel Version') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ $info['Laravel Version'] }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Environment') }}</p>
            <p class="mt-1 text-lg font-semibold font-mono {{ $info['Environment'] === 'production' ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                {{ ucfirst($info['Environment']) }}
            </p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Cache Driver') }}</p>
            <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white font-mono">{{ ucfirst($info['Cache Driver']) }}</p>
        </div>
        <div class="rounded-xl bg-white dark:bg-gray-900 p-4 ring-1 ring-gray-950/5 dark:ring-white/10 shadow-xs">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Debug Mode') }}</p>
            <p class="mt-1 text-lg font-semibold font-mono {{ $info['Debug Mode'] === 'Enabled' ? 'text-warning-600 dark:text-warning-400' : 'text-gray-950 dark:text-white' }}">
                {{ __($info['Debug Mode']) }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Primary Actions --}}
        <div class="lg:col-span-1 space-y-4">
            <x-filament::section>
                <x-slot name="heading">{{ __('Quick Actions') }}</x-slot>
                <x-slot name="description">{{ __('Manage all caches at once') }}</x-slot>

                <div class="space-y-3">
                    {{-- Clear All --}}
                    <button
                        wire:click="clearAllCache"
                        wire:loading.attr="disabled"
                        class="w-full group relative flex items-center gap-4 rounded-xl p-4 transition
                               bg-danger-50 dark:bg-danger-950/50
                               ring-1 ring-danger-200 dark:ring-danger-800
                               hover:bg-danger-100 dark:hover:bg-danger-900/50
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-danger-500 dark:bg-danger-600 text-white">
                            @svg('heroicon-o-trash', 'size-5')
                        </div>
                        <div class="text-start">
                            <p class="text-sm font-semibold text-danger-700 dark:text-danger-300">{{ __('Clear All Caches') }}</p>
                            <p class="text-xs text-danger-600/70 dark:text-danger-400/70">{{ __('Remove all cached data') }}</p>
                        </div>
                        <div wire:loading wire:target="clearAllCache" class="ms-auto">
                            <x-filament::loading-indicator class="size-5 text-danger-500" />
                        </div>
                    </button>

                    {{-- Optimize --}}
                    <button
                        wire:click="optimizeApplication"
                        wire:loading.attr="disabled"
                        class="w-full group relative flex items-center gap-4 rounded-xl p-4 transition
                               bg-success-50 dark:bg-success-950/50
                               ring-1 ring-success-200 dark:ring-success-800
                               hover:bg-success-100 dark:hover:bg-success-900/50
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-success-500 dark:bg-success-600 text-white">
                            @svg('heroicon-o-bolt', 'size-5')
                        </div>
                        <div class="text-start">
                            <p class="text-sm font-semibold text-success-700 dark:text-success-300">{{ __('Optimize Application') }}</p>
                            <p class="text-xs text-success-600/70 dark:text-success-400/70">{{ __('Cache config, routes & views') }}</p>
                        </div>
                        <div wire:loading wire:target="optimizeApplication" class="ms-auto">
                            <x-filament::loading-indicator class="size-5 text-success-500" />
                        </div>
                    </button>
                </div>
            </x-filament::section>

            {{-- System Details --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('System Details') }}</x-slot>
                <dl class="space-y-3">
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Session Driver') }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white font-mono">{{ ucfirst($info['Session Driver']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Queue Driver') }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white font-mono">{{ ucfirst($info['Queue Driver']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Timezone') }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white font-mono">{{ $info['Timezone'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Locale') }}</dt>
                        <dd class="text-sm font-medium text-gray-950 dark:text-white font-mono">{{ strtoupper($info['Locale']) }}</dd>
                    </div>
                </dl>
            </x-filament::section>
        </div>

        {{-- Individual Cache Actions --}}
        <div class="lg:col-span-2">
            <x-filament::section>
                <x-slot name="heading">{{ __('Individual Caches') }}</x-slot>
                <x-slot name="description">{{ __('Clear specific cache types') }}</x-slot>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    {{-- Application Cache --}}
                    <button
                        wire:click="clearApplicationCache"
                        wire:loading.attr="disabled"
                        class="group relative flex items-start gap-4 rounded-xl p-4 text-start transition
                               bg-white dark:bg-gray-900
                               ring-1 ring-gray-200 dark:ring-white/10
                               hover:ring-primary-300 dark:hover:ring-primary-700
                               hover:bg-primary-50/50 dark:hover:bg-primary-950/20
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400 group-hover:bg-primary-500 group-hover:text-white dark:group-hover:bg-primary-600 transition">
                            @svg('heroicon-o-archive-box', 'size-5')
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Application Cache') }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Clear stored application data and query results') }}</p>
                        </div>
                        <div wire:loading wire:target="clearApplicationCache" class="absolute top-4 inset-e-4">
                            <x-filament::loading-indicator class="size-5 text-primary-500" />
                        </div>
                    </button>

                    {{-- Config Cache --}}
                    <button
                        wire:click="clearConfigCache"
                        wire:loading.attr="disabled"
                        class="group relative flex items-start gap-4 rounded-xl p-4 text-start transition
                               bg-white dark:bg-gray-900
                               ring-1 ring-gray-200 dark:ring-white/10
                               hover:ring-warning-300 dark:hover:ring-warning-700
                               hover:bg-warning-50/50 dark:hover:bg-warning-950/20
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 dark:bg-warning-900/50 text-warning-600 dark:text-warning-400 group-hover:bg-warning-500 group-hover:text-white dark:group-hover:bg-warning-600 transition">
                            @svg('heroicon-o-cog-6-tooth', 'size-5')
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Config Cache') }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Refresh configuration files and .env values') }}</p>
                        </div>
                        <div wire:loading wire:target="clearConfigCache" class="absolute top-4 inset-e-4">
                            <x-filament::loading-indicator class="size-5 text-warning-500" />
                        </div>
                    </button>

                    {{-- Route Cache --}}
                    <button
                        wire:click="clearRouteCache"
                        wire:loading.attr="disabled"
                        class="group relative flex items-start gap-4 rounded-xl p-4 text-start transition
                               bg-white dark:bg-gray-900
                               ring-1 ring-gray-200 dark:ring-white/10
                               hover:ring-info-300 dark:hover:ring-info-700
                               hover:bg-info-50/50 dark:hover:bg-info-950/20
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-info-100 dark:bg-info-900/50 text-info-600 dark:text-info-400 group-hover:bg-info-500 group-hover:text-white dark:group-hover:bg-info-600 transition">
                            @svg('heroicon-o-map', 'size-5')
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Route Cache') }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Rebuild registered route definitions') }}</p>
                        </div>
                        <div wire:loading wire:target="clearRouteCache" class="absolute top-4 inset-e-4">
                            <x-filament::loading-indicator class="size-5 text-info-500" />
                        </div>
                    </button>

                    {{-- View Cache --}}
                    <button
                        wire:click="clearViewCache"
                        wire:loading.attr="disabled"
                        class="group relative flex items-start gap-4 rounded-xl p-4 text-start transition
                               bg-white dark:bg-gray-900
                               ring-1 ring-gray-200 dark:ring-white/10
                               hover:ring-gray-400 dark:hover:ring-gray-500
                               hover:bg-gray-50 dark:hover:bg-gray-800
                               disabled:opacity-50"
                    >
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 group-hover:bg-gray-600 group-hover:text-white dark:group-hover:bg-gray-500 transition">
                            @svg('heroicon-o-eye', 'size-5')
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('View Cache') }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Recompile Blade templates and views') }}</p>
                        </div>
                        <div wire:loading wire:target="clearViewCache" class="absolute top-4 inset-e-4">
                            <x-filament::loading-indicator class="size-5 text-gray-500" />
                        </div>
                    </button>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
