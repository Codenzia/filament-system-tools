<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector;
use Cron\CronExpression;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueMonitor extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 103;

    public static function getNavigationSort(): ?int
    {
        return config('filament-system-tools.navigation_sort.queue_monitor', 103);
    }

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

    public static function canAccess(): bool
    {
        return app()->bound('filament')
            && (filament()->auth()->user()?->can('view_queue_monitor') ?? false);
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
     * The queue connection whose storage this page reports on.
     */
    private function queueConnectionName(): string
    {
        return (string) config('queue.default');
    }

    public function queueDriver(): string
    {
        return (string) config('queue.connections.'.$this->queueConnectionName().'.driver');
    }

    /**
     * Job storage for the configured queue connection — its own database
     * connection and table, not the framework defaults. Null when the driver
     * keeps jobs somewhere this page cannot read (Redis, SQS, sync).
     */
    private function jobsQuery(): ?Builder
    {
        if ($this->queueDriver() !== 'database') {
            return null;
        }

        $name = $this->queueConnectionName();

        return $this->tableQuery(
            (string) (config("queue.connections.{$name}.connection") ?? config('database.default')),
            (string) config("queue.connections.{$name}.table", 'jobs'),
        );
    }

    private function failedJobsQuery(): ?Builder
    {
        return $this->tableQuery(
            (string) (config('queue.failed.database') ?? config('database.default')),
            (string) config('queue.failed.table', 'failed_jobs'),
        );
    }

    private function batchesQuery(): ?Builder
    {
        return $this->tableQuery(
            (string) (config('queue.batching.database') ?? config('database.default')),
            (string) config('queue.batching.table', 'job_batches'),
        );
    }

    private function tableQuery(string $connection, string $table): ?Builder
    {
        try {
            if (! Schema::connection($connection)->hasTable($table)) {
                return null;
            }

            return DB::connection($connection)->table($table);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     driver: string,
     *     metrics_supported: bool,
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
        $jobs = $this->jobsQuery();

        $stats = [
            'driver' => $this->queueConnectionName(),
            'metrics_supported' => $jobs !== null,
            'pending' => 0,
            'reserved' => 0,
            'failed' => 0,
            'queues' => [],
            'recent_pending' => [],
            'recent_failed' => [],
            'batches' => [],
        ];

        try {
            if ($jobs !== null) {
                $stats['pending'] = (int) (clone $jobs)->whereNull('reserved_at')->count();
                $stats['reserved'] = (int) (clone $jobs)->whereNotNull('reserved_at')->count();

                $stats['queues'] = (clone $jobs)
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

                $stats['recent_pending'] = (clone $jobs)
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

            $failed = $this->failedJobsQuery();

            if ($failed !== null) {
                $stats['failed'] = (int) (clone $failed)->count();

                $stats['recent_failed'] = (clone $failed)
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

            $batches = $this->batchesQuery();

            if ($batches !== null) {
                $stats['batches'] = $batches
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

    /**
     * The in-request "Process now" worker is opt-in: it can block a PHP-FPM
     * process, so production hosts (which should run a real worker) keep it off.
     */
    public function canProcessInline(): bool
    {
        return $this->canManageQueueJobs()
            && (bool) config('filament-system-tools.queue.allow_inline_worker', false);
    }

    /**
     * Run a queue command and report a failing exit status instead of showing
     * a success notification for work that never happened.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runQueueCommand(string $command, array $parameters = []): bool
    {
        try {
            $exitCode = Artisan::call($command, $parameters);
        } catch (Throwable $e) {
            Notification::make()->title(__('Command failed'))->body($e->getMessage())->danger()->send();

            return false;
        }

        if ($exitCode !== 0) {
            Notification::make()
                ->title(__('Command failed'))
                ->body(trim(Artisan::output()) ?: __('The command exited with code :code.', ['code' => $exitCode]))
                ->danger()
                ->send();

            return false;
        }

        return true;
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

        if (! $this->runQueueCommand('queue:retry', ['id' => ['all']])) {
            return;
        }

        Notification::make()->title(__('All failed jobs queued for retry'))->success()->send();
    }

    public function flushFailedJobs(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        if (! $this->runQueueCommand('queue:flush')) {
            return;
        }

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

        if (! $this->runQueueCommand('queue:retry', ['id' => [$uuid]])) {
            return;
        }

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

        if (! $this->runQueueCommand('queue:forget', ['id' => $uuid])) {
            return;
        }

        Notification::make()->title(__('Failed job deleted'))->success()->send();
    }

    /** Pending (un-reserved) jobs waiting on the queue right now. */
    public function pendingJobs(): int
    {
        $jobs = $this->jobsQuery();

        if ($jobs === null) {
            return 0;
        }

        try {
            return (int) $jobs->whereNull('reserved_at')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Jobs are queued but nothing is working them: pending > 0, none reserved
     * (no worker mid-job), and the queue heartbeat is stale. The classic
     * "I clicked things and nothing happens" — surfaced as a banner.
     */
    public function queueStalled(): bool
    {
        $jobs = $this->jobsQuery();

        if ($jobs === null) {
            return false;
        }

        try {
            $pending = (int) (clone $jobs)->whereNull('reserved_at')->count();
            $reserved = (int) (clone $jobs)->whereNotNull('reserved_at')->count();
        } catch (Throwable) {
            return false;
        }

        if ($pending < 1 || $reserved > 0) {
            return false;
        }

        return ! app(BackgroundWorkerInspector::class)->queueIsAlive();
    }

    /**
     * Drain the queue in this request — runs an in-process worker until empty
     * or a short time budget elapses. For local/dev or a quick one-off catch-up
     * when no long-running worker is configured; production should run a real
     * worker (see the cron line on the cards above).
     */
    public function processPendingNow(): void
    {
        if (! $this->canProcessInline()) {
            $this->denyQueueAction();

            return;
        }

        $before = $this->pendingJobs();

        if ($before < 1) {
            Notification::make()->title(__('Nothing to process'))->body(__('The queue is empty.'))->success()->send();

            return;
        }

        @set_time_limit(0);

        try {
            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => 15,
                '--max-jobs' => 25,
                '--tries' => 1,
                '--sleep' => 0,
            ]);
        } catch (Throwable $e) {
            Notification::make()->title(__('Could not process the queue'))->body($e->getMessage())->danger()->send();

            return;
        }

        $after = $this->pendingJobs();
        $done = max(0, $before - $after);

        Notification::make()
            ->title(trans_choice('{0}No jobs processed|{1}Processed :count job|[2,*]Processed :count jobs', $done, ['count' => $done]))
            ->body($after > 0
                ? __(':count still waiting — click “Process now” again to continue.', ['count' => $after])
                : __('The queue is now empty.'))
            ->success()
            ->send();
    }

    /**
     * Delete the jobs still waiting on the configured queue store. Returns the
     * number removed, or null when the driver keeps jobs out of reach.
     */
    public function deleteWaitingJobs(): ?int
    {
        $jobs = $this->jobsQuery();

        return $jobs === null ? null : (int) $jobs->whereNull('reserved_at')->delete();
    }

    /**
     * Drop waiting jobs without running them, e.g. a stale backlog. Reserved
     * jobs are left alone: a worker is mid-execution on those, and deleting the
     * row would only hide work that still runs to completion.
     */
    public function clearPendingJobs(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        $jobs = $this->jobsQuery();

        if ($jobs === null) {
            Notification::make()
                ->title(__('Not supported for this queue driver'))
                ->body(__('Clearing only the waiting jobs requires the database queue driver; :driver stores jobs elsewhere.', [
                    'driver' => $this->queueDriver() ?: __('this driver'),
                ]))
                ->danger()
                ->send();

            return;
        }

        try {
            $cleared = $this->deleteWaitingJobs();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Could not clear pending jobs'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans_choice('{0}No pending jobs to clear|{1}Cleared :count pending job|[2,*]Cleared :count pending jobs', $cleared, ['count' => $cleared]))
            ->body(__('Jobs already picked up by a worker were left untouched.'))
            ->success()
            ->send();
    }

    public function restartQueueWorkers(): void
    {
        if (! $this->canManageQueueJobs()) {
            $this->denyQueueAction();

            return;
        }

        if (! $this->runQueueCommand('queue:restart')) {
            return;
        }

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
