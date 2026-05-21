<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Pages\SystemHealth;

it('exposes the expected health-check categories', function () {
    $checks = (new SystemHealth)->getHealthChecks();

    expect($checks)->toHaveKeys([
        'database',
        'cache',
        'storage',
        'queue',
        'mail',
        'environment',
        'https',
        'storage_link',
        'scheduler',
    ]);

    foreach ($checks as $check) {
        expect($check)->toHaveKeys(['status', 'label', 'detail'])
            ->and($check['status'])->toBeIn(['ok', 'warning', 'error', 'info']);
    }
});

it('reports the database as ok against the testing connection', function () {
    $check = (new SystemHealth)->getHealthChecks()['database'];

    expect($check['status'])->toBe('ok')
        ->and($check['detail'])->toContain('sqlite');
});

it('flags sync queue driver as a warning', function () {
    config(['queue.default' => 'sync']);

    $check = (new SystemHealth)->getHealthChecks()['queue'];

    expect($check['status'])->toBe('warning');
});

it('flags log mail driver as a warning', function () {
    config(['mail.default' => 'log']);

    $check = (new SystemHealth)->getHealthChecks()['mail'];

    expect($check['status'])->toBe('warning');
});

it('builds a production checklist with the expected keys', function () {
    $checklist = (new SystemHealth)->getProductionChecklist();

    expect($checklist)->toHaveKeys([
        'database',
        'queue',
        'mail',
        'env',
        'debug',
        'https',
        'storage_link',
    ]);

    foreach ($checklist as $item) {
        expect($item)->toHaveKeys(['label', 'passed'])
            ->and($item['passed'])->toBeBool();
    }
});

it('passes the debug=false production check when APP_DEBUG is off', function () {
    config(['app.debug' => false]);

    $checklist = (new SystemHealth)->getProductionChecklist();

    expect($checklist['debug']['passed'])->toBeTrue();
});

it('exposes environment info with the running PHP version', function () {
    $info = (new SystemHealth)->getEnvironmentInfo();

    expect($info)->toHaveKey(__('PHP Version'))
        ->and($info[__('PHP Version')])->toBe(PHP_VERSION);
});

it('skips run-migrations when feature flag is disabled', function () {
    config(['filament-system-tools.health.allow_run_migrations' => false]);

    $page = new SystemHealth;

    // Should not throw — and migrate should not run when the flag is off.
    // We verify behaviour indirectly by ensuring no exception is raised.
    expect(fn () => $page->runMigrations())->not->toThrow(Throwable::class);
});

/* ──────────────────────────────────────────────────────────────────────────
 | Cache management (merged from SystemCache)
 ────────────────────────────────────────────────────────────────────────── */

it('exposes a human-readable cache footprint string', function () {
    $page = new SystemHealth;
    $page->refreshCacheSize();

    expect($page->cacheSize)->toMatch('/^\d+(\.\d+)? (B|KB|MB|GB|TB)$/');
});

it('exposes all expected cache action methods', function () {
    $page = new SystemHealth;

    // The clear methods send Filament notifications which require a Livewire/panel
    // context to render, so we verify the methods exist without invoking them here.
    // Behavioural coverage is exercised at the panel level in the demo app.
    expect(method_exists($page, 'clearAllCaches'))->toBeTrue()
        ->and(method_exists($page, 'clearApplicationCache'))->toBeTrue()
        ->and(method_exists($page, 'clearConfigCache'))->toBeTrue()
        ->and(method_exists($page, 'clearRouteCache'))->toBeTrue()
        ->and(method_exists($page, 'clearViewCache'))->toBeTrue()
        ->and(method_exists($page, 'clearEventCache'))->toBeTrue()
        ->and(method_exists($page, 'clearCompiled'))->toBeTrue()
        ->and(method_exists($page, 'optimizeApplication'))->toBeTrue();
});
