<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Pages\QueueMonitor;

it('returns zeroed queue stats when jobs tables do not exist', function () {
    $stats = (new QueueMonitor)->getQueueStats();

    expect($stats)->toHaveKeys([
        'driver', 'pending', 'reserved', 'failed', 'queues', 'recent_pending', 'recent_failed', 'batches',
    ])->and($stats['pending'])->toBe(0)
        ->and($stats['failed'])->toBe(0)
        ->and($stats['queues'])->toBe([])
        ->and($stats['recent_pending'])->toBe([])
        ->and($stats['recent_failed'])->toBe([])
        ->and($stats['batches'])->toBe([]);
});

it('returns the active queue driver from config', function () {
    config(['queue.default' => 'database']);

    expect((new QueueMonitor)->getQueueStats()['driver'])->toBe('database');
});

it('returns an array of scheduled tasks', function () {
    $tasks = (new QueueMonitor)->getScheduledTasks();

    expect($tasks)->toBeArray();

    foreach ($tasks as $task) {
        expect($task)->toHaveKeys(['command', 'description', 'expression', 'next_run']);
    }
});

it('exposes all expected control actions', function () {
    $page = new QueueMonitor;

    // Methods send Filament notifications which require panel/Livewire context;
    // verify presence here, behaviour is exercised in the demo app.
    expect(method_exists($page, 'retryAllFailedJobs'))->toBeTrue()
        ->and(method_exists($page, 'flushFailedJobs'))->toBeTrue()
        ->and(method_exists($page, 'retryFailedJob'))->toBeTrue()
        ->and(method_exists($page, 'deleteFailedJob'))->toBeTrue()
        ->and(method_exists($page, 'restartQueueWorkers'))->toBeTrue()
        ->and(method_exists($page, 'runScheduler'))->toBeTrue();
});
