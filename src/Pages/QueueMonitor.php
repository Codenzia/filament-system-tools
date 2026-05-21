<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector;
use Cron\CronExpression;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueMonitor extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 103;

    protected static ?string $slug = 'system/queue';

    protected string $view = 'filament-system-tools::pages.queue-monitor';

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('Queue & Scheduler');
    }

    public function getTitle(): string
    {
        return __('Queue & Scheduler');
    }

    /**
     * Snapshot of whether the host has the Laravel scheduler + queue
     * worker cron jobs wired up, plus the suggested cron lines to paste
     * into hPanel / cPanel when they're missing.
     *
     * @return array{
     *     scheduler: array{required: bool, alive: bool, last_seen: ?string, events: list<array{description: string, expression: string}>, cron_line: string},
     *     queue:     array{required: bool, alive: bool, last_seen: ?string, jobs: list<string>, cron_line: string},
     * }
     */
    public function getBackgroundWorkerStatus(): array
    {
        return app(BackgroundWorkerInspector::class)->summary();
    }

    /**
     * @return array{
     *     driver: string,
     *     pending: int,
     *     reserved: int,
     *     failed: int,
     *     queues: list<array{name: string, total: int, processing: int, waiting: int}>,
     *     recent_pending: list<array{id: int, queue: string, job: string, attempts: int, created_at: ?string}>,
     *     recent_failed: list<array{uuid: string, queue: string, job: string, failed_at: ?string, error: string}>,
     *     batches: list<array{id: string, name: string, total: int, pending: int, failed: int, finished: bool, progress: int, created_at: ?string}>,
     * }
     */
    public function getQueueStats(): array
    {
        $stats = [
            'driver' => (string) config('queue.default'),
            'pending' => 0,
            'reserved' => 0,
            'failed' => 0,
            'queues' => [],
            'recent_pending' => [],
            'recent_failed' => [],
            'batches' => [],
        ];

        try {
            if (Schema::hasTable('jobs')) {
                $stats['pending'] = (int) DB::table('jobs')->whereNull('reserved_at')->count();
                $stats['reserved'] = (int) DB::table('jobs')->whereNotNull('reserved_at')->count();

                $stats['queues'] = DB::table('jobs')
                    ->selectRaw('queue, count(*) as total, sum(case when reserved_at is not null then 1 else 0 end) as processing')
                    ->groupBy('queue')
                    ->get()
                    ->map(fn ($q): array => [
                        'name' => (string) $q->queue,
                        'total' => (int) $q->total,
                        'processing' => (int) $q->processing,
                        'waiting' => (int) $q->total - (int) $q->processing,
                    ])
                    ->all();

                $stats['recent_pending'] = DB::table('jobs')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
                    ->map(function ($job): array {
                        $payload = json_decode($job->payload, true);

                        return [
                            'id' => (int) $job->id,
                            'queue' => (string) $job->queue,
                            'job' => (string) ($payload['displayName'] ?? $payload['job'] ?? __('Unknown')),
                            'attempts' => (int) $job->attempts,
                            'created_at' => $this->formatTimestamp($job->created_at),
                        ];
                    })
                    ->all();
            }

            if (Schema::hasTable('failed_jobs')) {
                $stats['failed'] = (int) DB::table('failed_jobs')->count();

                $stats['recent_failed'] = DB::table('failed_jobs')
                    ->orderByDesc('failed_at')
                    ->limit(20)
                    ->get()
                    ->map(function ($job): array {
                        $payload = json_decode($job->payload, true);
                        $error = (string) ($job->exception ?? '');

                        return [
                            'uuid' => (string) $job->uuid,
                            'queue' => (string) $job->queue,
                            'job' => (string) ($payload['displayName'] ?? $payload['job'] ?? __('Unknown')),
                            'failed_at' => $this->formatTimestamp($job->failed_at),
                            'error' => $this->truncate($this->firstLine($error), 200),
                        ];
                    })
                    ->all();
            }

            if (Schema::hasTable('job_batches')) {
                $stats['batches'] = DB::table('job_batches')
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get()
                    ->map(function ($batch): array {
                        $total = (int) $batch->total_jobs;
                        $pending = (int) $batch->pending_jobs;
                        $progress = $total > 0 ? (int) round((($total - $pending) / $total) * 100) : 0;

                        return [
                            'id' => (string) $batch->id,
                            'name' => (string) ($batch->name ?: __('Unnamed batch')),
                            'total' => $total,
                            'pending' => $pending,
                            'failed' => (int) $batch->failed_jobs,
                            'finished' => $batch->finished_at !== null,
                            'progress' => $progress,
                            'created_at' => $this->formatTimestamp($batch->created_at),
                        ];
                    })
                    ->all();
            }
        } catch (Throwable) {
            // Tables exist but query failed; return whatever we have.
        }

        return $stats;
    }

    /**
     * @return list<array{command: string, description: string, expression: string, next_run: string}>
     */
    public function getScheduledTasks(): array
    {
        try {
            $schedule = app(Schedule::class);
            $events = $schedule->events();
        } catch (Throwable) {
            return [];
        }

        $tasks = [];

        foreach ($events as $event) {
            try {
                $expression = (string) $event->expression;
                $next = (new CronExpression($expression))->getNextRunDate()->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $expression = (string) ($event->expression ?? '* * * * *');
                $next = __('Unknown');
            }

            $tasks[] = [
                'command' => (string) ($event->command ?? $event->description ?? __('Closure')),
                'description' => (string) ($event->description ?? ''),
                'expression' => $expression,
                'next_run' => $next,
            ];
        }

        return $tasks;
    }

    public function canManageQueueJobs(): bool
    {
        return filament()->auth()->user()?->can('manage_queue_jobs') ?? false;
    }

    public function canRunScheduler(): bool
    {
        return filament()->auth()->user()?->can('run_scheduler') ?? false;
    }

    private function denyQueueAction(): void
    {
        Notification::make()->title(__('Unauthorized'))->danger()->send();
    }

    public function retryAllFailedJobs(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        Artisan::call('queue:retry', ['id' => ['all']]);
        Notification::make()->title(__('All failed jobs queued for retry'))->success()->send();
    }

    public function flushFailedJobs(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        Artisan::call('queue:flush');
        Notification::make()->title(__('Failed jobs cleared'))->success()->send();
    }

    public function retryFailedJob(string $uuid): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        if ($uuid === '') {
            return;
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Notification::make()->title(__('Job queued for retry'))->success()->send();
    }

    public function deleteFailedJob(string $uuid): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        if ($uuid === '') {
            return;
        }

        Artisan::call('queue:forget', ['id' => $uuid]);
        Notification::make()->title(__('Failed job deleted'))->success()->send();
    }

    public function restartQueueWorkers(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        Artisan::call('queue:restart');
        Notification::make()
            ->title(__('Restart signal sent'))
            ->body(__('Active queue workers will restart after their current job.'))
            ->success()
            ->send();
    }

    public function runScheduler(): void
    {
        if (! $this->canRunScheduler()) {
            $this->denyQueueAction();

            return;
        }

        try {
            Artisan::call('schedule:run');

            Notification::make()
                ->title(__('Scheduler ran'))
                ->body(trim(Artisan::output()) ?: __('No scheduled tasks were due.'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('Scheduler failed'))->body($e->getMessage())->danger()->send();
        }
    }

    private function formatTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        if (is_numeric($timestamp)) {
            return date('Y-m-d H:i:s', (int) $timestamp);
        }

        return (string) $timestamp;
    }

    private function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? '' : $line;
    }

    private function truncate(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
