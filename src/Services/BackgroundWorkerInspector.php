<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Services;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Throwable;

/**
 * Inspects whether the host app needs Laravel scheduler + queue workers
 * and whether either is currently running on this host.
 *
 * "Running" is detected via heartbeat files that the package's
 * ServiceProvider touches whenever schedule:run or queue:work fires.
 * If the file's mtime is fresh, the worker is alive; if stale or
 * missing, the cron is not configured on the host.
 */
class BackgroundWorkerInspector
{
    public const HEARTBEAT_SCHEDULER = 'framework/cron-heartbeat-scheduler';

    public const HEARTBEAT_QUEUE = 'framework/cron-heartbeat-queue';

    /**
     * How recent the heartbeat file must be to count as "alive".
     * Scheduler runs once per minute, queue worker polls continuously,
     * so 120s is a generous window that tolerates one missed beat.
     */
    public const HEARTBEAT_FRESHNESS_SECONDS = 120;

    /**
     * Whether the host app has any scheduled commands or callbacks
     * registered via Laravel's Schedule facade. Excludes the package's
     * own heartbeat tick so it doesn't falsely report "yes" on an
     * otherwise empty schedule.
     */
    public function requiresScheduler(): bool
    {
        return count($this->scheduledEventDescriptions()) > 0;
    }

    /**
     * @return list<array{description: string, expression: string}>
     */
    public function scheduledEventDescriptions(): array
    {
        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
        } catch (Throwable) {
            return [];
        }

        $items = [];

        foreach ($schedule->events() as $event) {
            $description = (string) ($event->description ?? $event->command ?? 'closure');

            if (str_contains($description, 'filament-system-tools:scheduler-heartbeat')) {
                continue;
            }

            $items[] = [
                'description' => $description,
                'expression' => (string) $event->expression,
            ];
        }

        return $items;
    }

    /**
     * Whether the host app has any queueable jobs declared. Detected by
     * scanning the app's Jobs directory (default: app/Jobs/) and looking
     * for classes that implement Illuminate\Contracts\Queue\ShouldQueue.
     */
    public function requiresQueue(): bool
    {
        return count($this->queueableJobClasses()) > 0;
    }

    /** @return list<string> */
    public function queueableJobClasses(): array
    {
        $dirs = array_filter([
            app_path('Jobs'),
            app_path('Events'),
            app_path('Notifications'),
        ], 'is_dir');

        $classes = [];

        foreach ($dirs as $dir) {
            foreach ($this->phpFilesUnder($dir) as $file) {
                $class = $this->fullyQualifiedClassNameFromFile($file);

                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                try {
                    $reflection = new ReflectionClass($class);
                } catch (Throwable) {
                    continue;
                }

                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                if ($reflection->implementsInterface(ShouldQueue::class)) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    public function schedulerHeartbeatAt(): ?Carbon
    {
        return $this->heartbeatAt(self::HEARTBEAT_SCHEDULER);
    }

    public function queueHeartbeatAt(): ?Carbon
    {
        return $this->heartbeatAt(self::HEARTBEAT_QUEUE);
    }

    public function schedulerIsAlive(): bool
    {
        return $this->heartbeatIsFresh($this->schedulerHeartbeatAt());
    }

    public function queueIsAlive(): bool
    {
        return $this->heartbeatIsFresh($this->queueHeartbeatAt());
    }

    /**
     * Build the cron line a host operator would add via cPanel / hPanel
     * to run the Laravel scheduler. PHP_BIN defaults to /usr/bin/php
     * because that's the typical alias on shared hosting (Hostinger,
     * cPanel) where the version is selected per-domain.
     */
    public function suggestedSchedulerCronLine(string $phpBin = '/usr/bin/php'): string
    {
        return sprintf(
            '* * * * * cd %s && %s artisan schedule:run >> %s 2>&1',
            base_path(),
            $phpBin,
            storage_path('logs/schedule.log'),
        );
    }

    /**
     * Build the cron-loop queue worker line. `--stop-when-empty --max-time=55`
     * gives a fresh process each minute, which is the standard pattern on
     * shared hosting where you can't run supervisor.
     */
    public function suggestedQueueCronLine(string $phpBin = '/usr/bin/php'): string
    {
        $connection = (string) config('queue.default');

        return sprintf(
            '* * * * * cd %s && %s artisan queue:work %s --stop-when-empty --max-time=55 --tries=3 --sleep=1 >> %s 2>&1',
            base_path(),
            $phpBin,
            $connection !== '' ? $connection : 'database',
            storage_path('logs/worker.log'),
        );
    }

    /**
     * @return array{
     *     scheduler: array{required: bool, alive: bool, last_seen: ?string, events: list<array{description: string, expression: string}>, cron_line: string},
     *     queue:     array{required: bool, alive: bool, last_seen: ?string, jobs: list<string>, cron_line: string},
     * }
     */
    public function summary(): array
    {
        $schedulerHeartbeat = $this->schedulerHeartbeatAt();
        $queueHeartbeat = $this->queueHeartbeatAt();

        return [
            'scheduler' => [
                'required' => $this->requiresScheduler(),
                'alive' => $this->heartbeatIsFresh($schedulerHeartbeat),
                'last_seen' => $schedulerHeartbeat?->toIso8601String(),
                'events' => $this->scheduledEventDescriptions(),
                'cron_line' => $this->suggestedSchedulerCronLine(),
            ],
            'queue' => [
                'required' => $this->requiresQueue(),
                'alive' => $this->heartbeatIsFresh($queueHeartbeat),
                'last_seen' => $queueHeartbeat?->toIso8601String(),
                'jobs' => $this->queueableJobClasses(),
                'cron_line' => $this->suggestedQueueCronLine(),
            ],
        ];
    }

    private function heartbeatAt(string $relative): ?Carbon
    {
        $path = storage_path($relative);

        if (! is_file($path)) {
            return null;
        }

        $mtime = @filemtime($path);

        return $mtime ? Carbon::createFromTimestamp($mtime) : null;
    }

    private function heartbeatIsFresh(?Carbon $at): bool
    {
        return $at instanceof Carbon
            && $at->diffInSeconds(Carbon::now()) <= self::HEARTBEAT_FRESHNESS_SECONDS;
    }

    /** @return iterable<string> */
    private function phpFilesUnder(string $dir): iterable
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function fullyQualifiedClassNameFromFile(string $path): ?string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $contents, $classMatch)) {
            return null;
        }

        return trim($nsMatch[1]).'\\'.$classMatch[1];
    }
}
