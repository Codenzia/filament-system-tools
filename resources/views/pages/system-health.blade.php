<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Health Checks Grid --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Health Checks') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Live status of database, cache, queue, mail and runtime configuration.') }}</p>
                </div>
                <button wire:click="$refresh"
                    class="fi-btn fi-btn-size-sm relative grid-flow-col items-center justify-center font-semibold outline-none transition focus-visible:ring-2 rounded-lg fi-btn-color-gray bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ring-1 ring-gray-300 dark:ring-gray-600 gap-1.5 px-2.5 py-1.5 text-xs inline-grid">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="w-3.5 h-3.5" />
                    {{ __('Refresh') }}
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 divide-x divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($this->getHealthChecks() as $key => $check)
                    @php
                        $color = match($check['status']) {
                            'ok' => 'success',
                            'warning' => 'warning',
                            'error' => 'danger',
                            default => 'info',
                        };
                        $icon = match($check['status']) {
                            'ok' => 'heroicon-s-check-circle',
                            'warning' => 'heroicon-s-exclamation-triangle',
                            'error' => 'heroicon-s-x-circle',
                            default => 'heroicon-s-information-circle',
                        };
                    @endphp
                    <div class="px-4 py-3 flex items-start gap-3">
                        <x-filament::icon :icon="$icon" class="w-5 h-5 shrink-0 text-{{ $color }}-500 mt-0.5" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $check['label'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 break-words">{{ $check['detail'] }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Production Checklist --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Production Readiness') }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Items that should be green before going live.') }}</p>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($this->getProductionChecklist() as $item)
                    <div class="px-4 py-2.5 flex items-center gap-3">
                        @if($item['passed'])
                            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-success-100 dark:bg-success-500/20">
                                <x-filament::icon icon="heroicon-o-check" class="w-3.5 h-3.5 text-success-600 dark:text-success-400" />
                            </span>
                        @else
                            <span class="flex items-center justify-center w-5 h-5 rounded-full bg-warning-100 dark:bg-warning-500/20">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 text-warning-600 dark:text-warning-400" />
                            </span>
                        @endif
                        <span @class([
                            'text-sm',
                            'text-gray-700 dark:text-gray-300' => $item['passed'],
                            'text-warning-700 dark:text-warning-400' => ! $item['passed'],
                        ])>{{ $item['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Quick Actions') }}</h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @if ($this->canGenerateStorageLink())
                    <button wire:click="generateStorageLink"
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                        <x-filament::icon icon="heroicon-o-link" class="w-4 h-4" />
                        {{ __('Create storage symlink') }}
                    </button>
                @endif
                @if ($this->canOptimizeApplication())
                    <button wire:click="optimizeApplication"
                        wire:loading.attr="disabled"
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-success-600 text-white hover:bg-success-500 dark:bg-success-500 dark:hover:bg-success-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                        <x-filament::icon icon="heroicon-o-bolt" class="w-4 h-4" />
                        {{ __('Optimize') }}
                    </button>
                @endif
                @if ($this->canClearApplicationCache())
                    <button wire:click="clearAllCaches"
                        wire:loading.attr="disabled"
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-gray-700 text-white hover:bg-gray-600 dark:bg-gray-600 dark:hover:bg-gray-500 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                        <x-filament::icon icon="heroicon-o-trash" class="w-4 h-4" />
                        {{ __('Clear all caches') }}
                    </button>
                @endif
                @if(config('filament-system-tools.health.allow_run_migrations', false) && $this->canRunMigrations())
                <button wire:click="runMigrations"
                    wire:confirm="{{ __('Run database migrations now? This is a production-affecting operation.') }}"
                    wire:loading.attr="disabled"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-warning-600 text-white hover:bg-warning-500 dark:bg-warning-500 dark:hover:bg-warning-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-arrow-up-circle" class="w-4 h-4" />
                    {{ __('Run migrations') }}
                </button>
                @endif
            </div>
        </div>

        {{-- Cache Management --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Cache Management') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Clear individual caches or run targeted artisan commands.') }}</p>
                </div>
                <div class="text-right">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('On-disk footprint') }}</p>
                    <p class="text-base font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $cacheSize }}</p>
                </div>
            </div>
            <div class="p-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                @if ($this->canClearApplicationCache())
                    <button wire:click="clearApplicationCache"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-cpu-chip" class="w-3.5 h-3.5" />
                        {{ __('Application') }}
                    </button>
                @endif
                @if ($this->canClearConfigCache())
                    <button wire:click="clearConfigCache"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-cog-6-tooth" class="w-3.5 h-3.5" />
                        {{ __('Config') }}
                    </button>
                @endif
                @if ($this->canClearRouteCache())
                    <button wire:click="clearRouteCache"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-map" class="w-3.5 h-3.5" />
                        {{ __('Route') }}
                    </button>
                @endif
                @if ($this->canClearViewCache())
                    <button wire:click="clearViewCache"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-eye" class="w-3.5 h-3.5" />
                        {{ __('View') }}
                    </button>
                @endif
                @if ($this->canClearEventCache())
                    <button wire:click="clearEventCache"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-bell-alert" class="w-3.5 h-3.5" />
                        {{ __('Event') }}
                    </button>
                @endif
                @if ($this->canClearCompiled())
                    <button wire:click="clearCompiled"
                        class="fi-btn relative grid-flow-col items-center justify-center font-medium outline-none transition rounded-lg bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700 gap-1.5 px-3 py-2 text-xs inline-grid">
                        <x-filament::icon icon="heroicon-o-document-minus" class="w-3.5 h-3.5" />
                        {{ __('Compiled') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Environment Info --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Environment') }}</h3>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 divide-x divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($this->getEnvironmentInfo() as $label => $value)
                    <div class="px-4 py-2.5 min-w-0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="text-sm font-mono text-gray-900 dark:text-gray-100 truncate">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-panels::page>
