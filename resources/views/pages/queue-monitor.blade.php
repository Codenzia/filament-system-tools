<x-filament-panels::page>
    @php
        $stats = $this->getQueueStats();
        $tasks = $this->getScheduledTasks();
        $bg = $this->getBackgroundWorkerStatus();
    @endphp

    <div class="space-y-6">

        {{-- Background Workers (cron status) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @foreach (['scheduler' => __('Scheduler'), 'queue' => __('Queue worker')] as $key => $label)
                @php
                    $info = $bg[$key];
                    $needsAttention = $info['required'] && ! $info['alive'];
                    $ringClass = $needsAttention
                        ? 'ring-warning-300 dark:ring-warning-700'
                        : ($info['alive'] ? 'ring-success-300 dark:ring-success-700' : 'ring-gray-950/5 dark:ring-white/10');
                @endphp

                <div class="fi-section rounded-xl bg-white shadow-sm ring-1 dark:bg-gray-900 p-4 {{ $ringClass }}">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <x-filament::icon
                                icon="{{ $key === 'scheduler' ? 'heroicon-o-clock' : 'heroicon-o-queue-list' }}"
                                class="w-5 h-5 text-gray-500 dark:text-gray-400" />
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</h3>
                        </div>
                        @if ($info['alive'])
                            <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-success-100 text-success-700 dark:bg-success-900/40 dark:text-success-300">
                                <span class="w-1.5 h-1.5 rounded-full bg-success-500"></span>
                                {{ __('Cron running') }}
                            </span>
                        @elseif ($info['required'])
                            <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-warning-100 text-warning-700 dark:bg-warning-900/40 dark:text-warning-300">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3 h-3" />
                                {{ __('Cron not detected') }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                {{ __('Not required') }}
                            </span>
                        @endif
                    </div>

                    <dl class="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                        <div class="flex justify-between">
                            <dt>{{ __('Required by app') }}</dt>
                            <dd class="font-medium text-gray-900 dark:text-gray-100">
                                {{ $info['required'] ? __('Yes') : __('No') }}
                                @if ($key === 'scheduler' && $info['required'])
                                    ({{ count($info['events']) }} {{ __('scheduled') }})
                                @elseif ($key === 'queue' && $info['required'])
                                    ({{ count($info['jobs']) }} {{ __('queueable') }})
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt>{{ __('Last heartbeat') }}</dt>
                            <dd class="font-mono text-gray-900 dark:text-gray-100">
                                {{ $info['last_seen'] ? \Illuminate\Support\Carbon::parse($info['last_seen'])->diffForHumans() : __('never') }}
                            </dd>
                        </div>
                    </dl>

                    @if ($needsAttention)
                        <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-xs text-gray-600 dark:text-gray-400 mb-1">{{ __('Add this cron line in your host panel') }}:</p>
                            <code class="block text-xs font-mono bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 rounded p-2 overflow-x-auto whitespace-pre">{{ $info['cron_line'] }}</code>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Driver') }}</p>
                <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-1 font-mono">{{ $stats['driver'] }}</p>
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Pending') }}</p>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400 mt-1">{{ $stats['pending'] }}</p>
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Processing') }}</p>
                <p class="text-2xl font-bold text-warning-600 dark:text-warning-400 mt-1">{{ $stats['reserved'] }}</p>
            </div>
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Failed') }}</p>
                <p class="text-2xl font-bold text-danger-600 dark:text-danger-400 mt-1">{{ $stats['failed'] }}</p>
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="flex flex-wrap gap-2">
            <button wire:click="$refresh"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700 ring-1 ring-gray-300 dark:ring-gray-600 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                <x-filament::icon icon="heroicon-o-arrow-path" class="w-4 h-4" />
                {{ __('Refresh') }}
            </button>
            @if ($this->canManageQueueJobs())
                <button wire:click="restartQueueWorkers"
                    wire:confirm="{{ __('Send restart signal to all queue workers?') }}"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-warning-600 text-white hover:bg-warning-500 dark:bg-warning-500 dark:hover:bg-warning-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="w-4 h-4" />
                    {{ __('Restart workers') }}
                </button>
            @endif
            @if ($this->canRunScheduler())
                <button wire:click="runScheduler"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-play" class="w-4 h-4" />
                    {{ __('Run scheduler now') }}
                </button>
            @endif
            @if($stats['failed'] > 0 && $this->canManageQueueJobs())
                <button wire:click="retryAllFailedJobs"
                    wire:confirm="{{ __('Retry every failed job?') }}"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-success-600 text-white hover:bg-success-500 dark:bg-success-500 dark:hover:bg-success-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-arrow-path-rounded-square" class="w-4 h-4" />
                    {{ __('Retry all failed') }}
                </button>
                <button wire:click="flushFailedJobs"
                    wire:confirm="{{ __('Permanently delete all failed jobs?') }}"
                    class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition rounded-lg bg-danger-600 text-white hover:bg-danger-500 dark:bg-danger-500 dark:hover:bg-danger-400 gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm">
                    <x-filament::icon icon="heroicon-o-trash" class="w-4 h-4" />
                    {{ __('Flush failed jobs') }}
                </button>
            @endif
        </div>

        {{-- Per-Queue Breakdown --}}
        @if(!empty($stats['queues']))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Queues') }}</h3>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Name') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Total') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Waiting') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Processing') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($stats['queues'] as $queue)
                            <tr>
                                <td class="px-4 py-2 font-mono text-gray-900 dark:text-gray-100">{{ $queue['name'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $queue['total'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-primary-600 dark:text-primary-400">{{ $queue['waiting'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-warning-600 dark:text-warning-400">{{ $queue['processing'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Recent Pending Jobs --}}
        @if(!empty($stats['recent_pending']))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Recent Pending Jobs') }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                    @foreach($stats['recent_pending'] as $job)
                        <div class="px-4 py-2.5 flex items-center justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-mono text-gray-900 dark:text-gray-100 truncate">{{ $job['job'] }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    <span class="font-mono">{{ $job['queue'] }}</span> · {{ __('attempts') }}: {{ $job['attempts'] }}
                                    @if($job['created_at']) · {{ $job['created_at'] }} @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Failed Jobs --}}
        @if(!empty($stats['recent_failed']))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-danger-200 dark:bg-gray-900 dark:ring-danger-500/20 overflow-hidden">
                <div class="px-4 py-3 border-b border-danger-200 dark:border-danger-500/20">
                    <h3 class="text-sm font-semibold text-danger-700 dark:text-danger-400">{{ __('Failed Jobs') }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[500px] overflow-y-auto">
                    @foreach($stats['recent_failed'] as $job)
                        <div class="px-4 py-3">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-mono text-gray-900 dark:text-gray-100 truncate">{{ $job['job'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        <span class="font-mono">{{ $job['queue'] }}</span>
                                        @if($job['failed_at']) · {{ $job['failed_at'] }} @endif
                                    </p>
                                    @if($job['error'])
                                        <p class="text-xs text-danger-600 dark:text-danger-400 mt-1 font-mono break-words">{{ $job['error'] }}</p>
                                    @endif
                                </div>
                                @if ($this->canManageQueueJobs())
                                    <div class="flex items-center gap-2 shrink-0">
                                        <button wire:click="retryFailedJob('{{ $job['uuid'] }}')"
                                            class="text-xs text-success-600 hover:text-success-700 dark:text-success-400 underline">
                                            {{ __('Retry') }}
                                        </button>
                                        <button wire:click="deleteFailedJob('{{ $job['uuid'] }}')"
                                            wire:confirm="{{ __('Delete this failed job?') }}"
                                            class="text-xs text-danger-600 hover:text-danger-700 dark:text-danger-400 underline">
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Job Batches --}}
        @if(!empty($stats['batches']))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Recent Batches') }}</h3>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800 max-h-[400px] overflow-y-auto">
                    @foreach($stats['batches'] as $batch)
                        <div class="px-4 py-3">
                            <div class="flex items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $batch['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $batch['total'] }} {{ __('jobs') }} · {{ $batch['pending'] }} {{ __('pending') }} · {{ $batch['failed'] }} {{ __('failed') }}
                                        @if($batch['finished']) · <span class="text-success-600 dark:text-success-400">{{ __('finished') }}</span> @endif
                                    </p>
                                </div>
                                <div class="w-32 shrink-0">
                                    <div class="flex items-center justify-between text-xs text-gray-500 mb-0.5">
                                        <span>{{ $batch['progress'] }}%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                        <div class="bg-primary-500 h-1.5 rounded-full" style="width: {{ $batch['progress'] }}%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Scheduled Tasks --}}
        @if(!empty($tasks))
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Scheduled Tasks') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('Tasks registered via the Laravel Scheduler.') }}</p>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Command') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Cron') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ __('Next Run') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($tasks as $task)
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs text-gray-900 dark:text-gray-100 break-all">{{ $task['command'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $task['expression'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-primary-600 dark:text-primary-400">{{ $task['next_run'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
