<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Pages\QueueMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Point the page at a database queue backed by the testing connection. */
function useDatabaseQueue(string $table = 'jobs'): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => $table,
            'queue' => 'default',
            'retry_after' => 90,
        ],
    ]);
}

function makeJobsTable(string $name = 'jobs'): void
{
    Schema::create($name, function ($table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function queueAJob(?int $reservedAt = null, string $queue = 'default', string $table = 'jobs'): void
{
    DB::table($table)->insert([
        'queue' => $queue,
        'payload' => json_encode(['displayName' => 'App\\Jobs\\Demo', 'data' => []]),
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

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
        ->and(method_exists($page, 'runScheduler'))->toBeTrue()
        ->and(method_exists($page, 'processPendingNow'))->toBeTrue()
        ->and(method_exists($page, 'clearPendingJobs'))->toBeTrue();
});

it('reports no pending jobs and is not stalled without a jobs table', function () {
    $page = new QueueMonitor;

    expect($page->pendingJobs())->toBe(0)
        ->and($page->queueStalled())->toBeFalse();
});

it('flags the queue as stalled when jobs wait but nothing is processing them', function () {
    useDatabaseQueue();
    makeJobsTable();
    queueAJob();          // pending, un-reserved
    queueAJob();

    $page = new QueueMonitor;

    // No worker heartbeat in tests + nothing reserved → stalled.
    expect($page->pendingJobs())->toBe(2)
        ->and($page->queueStalled())->toBeTrue();
});

it('is not stalled while a worker is mid-job (a job is reserved)', function () {
    useDatabaseQueue();
    makeJobsTable();
    queueAJob(reservedAt: now()->timestamp); // reserved = a worker is on it

    expect((new QueueMonitor)->queueStalled())->toBeFalse();
});

it('is not stalled when the queue is empty', function () {
    useDatabaseQueue();
    makeJobsTable();

    expect((new QueueMonitor)->queueStalled())->toBeFalse();
});

it('reads jobs from the configured queue connection table', function () {
    useDatabaseQueue('custom_jobs');
    makeJobsTable('custom_jobs');
    queueAJob(table: 'custom_jobs');
    queueAJob(reservedAt: now()->timestamp, table: 'custom_jobs');

    $stats = (new QueueMonitor)->getQueueStats();

    expect($stats['metrics_supported'])->toBeTrue()
        ->and($stats['pending'])->toBe(1)
        ->and($stats['reserved'])->toBe(1);
});

it('reports metrics as unsupported for a non-database queue driver', function () {
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
    makeJobsTable();
    queueAJob();

    $stats = (new QueueMonitor)->getQueueStats();

    expect($stats['metrics_supported'])->toBeFalse()
        ->and($stats['pending'])->toBe(0);
});

it('clears only waiting jobs and keeps reserved work', function () {
    useDatabaseQueue();
    makeJobsTable();
    queueAJob();
    queueAJob(reservedAt: now()->timestamp);

    expect((new QueueMonitor)->deleteWaitingJobs())->toBe(1)
        ->and(DB::table('jobs')->whereNotNull('reserved_at')->count())->toBe(1);
});

it('reports no clearable store for a driver that keeps jobs elsewhere', function () {
    config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis']]);

    expect((new QueueMonitor)->deleteWaitingJobs())->toBeNull();
});
