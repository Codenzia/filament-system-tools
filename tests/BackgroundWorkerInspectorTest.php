<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector;
use Illuminate\Console\Scheduling\Schedule;

it('reports requiresScheduler=false when only the package heartbeat is registered', function () {
    $inspector = new BackgroundWorkerInspector;

    // The package registers a single heartbeat scheduled callback; the
    // inspector filters that out so it doesn't falsely report "yes".
    expect($inspector->requiresScheduler())->toBeFalse();
    expect($inspector->scheduledEventDescriptions())->toBeEmpty();
});

it('reports requiresScheduler=true once the host app schedules a command', function () {
    app(Schedule::class)->command('cache:clear')->daily();

    $inspector = new BackgroundWorkerInspector;

    expect($inspector->requiresScheduler())->toBeTrue();
    $events = $inspector->scheduledEventDescriptions();
    expect($events)->toHaveCount(1);
    expect($events[0]['expression'])->toBe('0 0 * * *');
});

it('detects a fresh scheduler heartbeat as alive', function () {
    $path = storage_path(BackgroundWorkerInspector::HEARTBEAT_SCHEDULER);
    @mkdir(dirname($path), 0775, true);
    touch($path);

    $inspector = new BackgroundWorkerInspector;

    expect($inspector->schedulerIsAlive())->toBeTrue();
    expect($inspector->schedulerHeartbeatAt())->not->toBeNull();

    @unlink($path);
});

it('reports schedulerIsAlive=false when heartbeat file is stale', function () {
    $path = storage_path(BackgroundWorkerInspector::HEARTBEAT_SCHEDULER);
    @mkdir(dirname($path), 0775, true);
    touch($path);
    touch($path, time() - (BackgroundWorkerInspector::HEARTBEAT_FRESHNESS_SECONDS + 60));

    $inspector = new BackgroundWorkerInspector;

    expect($inspector->schedulerIsAlive())->toBeFalse();

    @unlink($path);
});

it('reports schedulerIsAlive=false when heartbeat file is missing', function () {
    $path = storage_path(BackgroundWorkerInspector::HEARTBEAT_SCHEDULER);
    @unlink($path);

    $inspector = new BackgroundWorkerInspector;

    expect($inspector->schedulerIsAlive())->toBeFalse();
    expect($inspector->schedulerHeartbeatAt())->toBeNull();
});

it('produces a cron line that references base_path and the configured php binary', function () {
    $inspector = new BackgroundWorkerInspector;
    $line = $inspector->suggestedSchedulerCronLine('/usr/bin/php8.3');

    expect($line)->toStartWith('* * * * * cd ');
    expect($line)->toContain(base_path());
    expect($line)->toContain('/usr/bin/php8.3 artisan schedule:run');
    expect($line)->toContain('>> '.storage_path('logs/schedule.log'));
});

it('produces a queue cron line using --stop-when-empty and --max-time=55', function () {
    config()->set('queue.default', 'database');

    $line = (new BackgroundWorkerInspector)->suggestedQueueCronLine();

    expect($line)->toContain('artisan queue:work database');
    expect($line)->toContain('--stop-when-empty');
    expect($line)->toContain('--max-time=55');
});

it('summary() exposes scheduler + queue sections with the expected shape', function () {
    $summary = (new BackgroundWorkerInspector)->summary();

    expect($summary)->toHaveKeys(['scheduler', 'queue']);
    expect($summary['scheduler'])->toHaveKeys(['required', 'alive', 'last_seen', 'events', 'cron_line']);
    expect($summary['queue'])->toHaveKeys(['required', 'alive', 'last_seen', 'jobs', 'cron_line']);
});
