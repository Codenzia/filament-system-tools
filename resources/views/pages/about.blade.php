<x-filament-panels::page>
    @php
        $info = $this->getSystemInfo();
        $release = $this->getReleaseInfo();
        $supportSnippet = $this->getSupportSnippet();
    @endphp

    {{-- Copy Support Snippet --}}
    <div x-data="{
            copied: false,
            snippet: @js($supportSnippet),
            copy() {
                const done = () => { this.copied = true; setTimeout(() => this.copied = false, 2000); };
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(this.snippet).then(done);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = this.snippet; document.body.appendChild(ta);
                    ta.select(); document.execCommand('copy'); ta.remove(); done();
                }
            },
        }"
        class="rounded-xl bg-info-50 dark:bg-info-500/10 border border-info-200 dark:border-info-500/20 p-4 flex items-center justify-between gap-3">
        <div class="flex gap-3">
            <x-filament::icon icon="heroicon-o-clipboard-document" class="w-5 h-5 text-info-500 shrink-0 mt-0.5" />
            <div class="text-sm text-info-700 dark:text-info-400">
                <p class="font-medium">{{ __('Support snapshot') }}</p>
                <p class="text-xs mt-0.5">{{ __('Copy a markdown-formatted summary of versions, drivers, and storage info to attach to a support ticket.') }}</p>
            </div>
        </div>
        <button x-on:click="copy()" type="button"
            class="fi-btn shrink-0 relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-info-600 text-white hover:bg-info-500 dark:bg-info-500 dark:hover:bg-info-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
            <x-filament::icon x-show="!copied" icon="heroicon-o-clipboard" class="w-4 h-4" />
            <x-filament::icon x-show="copied" icon="heroicon-o-check" class="w-4 h-4" />
            <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy snapshot') }}'"></span>
        </button>
    </div>

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
