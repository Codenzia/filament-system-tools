<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;

it('can be instantiated via make', function () {
    $plugin = FilamentSystemToolsPlugin::make();

    expect($plugin)->toBeInstanceOf(FilamentSystemToolsPlugin::class);
});

it('returns correct plugin id', function () {
    $plugin = FilamentSystemToolsPlugin::make();

    expect($plugin->getId())->toBe('filament-system-tools');
});

it('has all pages enabled by default', function () {
    $plugin = FilamentSystemToolsPlugin::make();

    $ref = new ReflectionClass($plugin);

    expect($ref->getProperty('logsEnabled')->getValue($plugin))->toBeTrue()
        ->and($ref->getProperty('backupsEnabled')->getValue($plugin))->toBeTrue()
        ->and($ref->getProperty('aboutEnabled')->getValue($plugin))->toBeTrue()
        ->and($ref->getProperty('healthEnabled')->getValue($plugin))->toBeTrue()
        ->and($ref->getProperty('queueMonitorEnabled')->getValue($plugin))->toBeTrue()
        ->and($ref->getProperty('smartMigrationEnabled')->getValue($plugin))->toBeTrue();
});

it('can disable individual pages', function () {
    $plugin = FilamentSystemToolsPlugin::make()
        ->enableLogs(false)
        ->enableBackups(false)
        ->enableAbout(false)
        ->enableHealth(false)
        ->enableQueueMonitor(false)
        ->enableSmartMigration(false);

    $ref = new ReflectionClass($plugin);

    expect($ref->getProperty('logsEnabled')->getValue($plugin))->toBeFalse()
        ->and($ref->getProperty('backupsEnabled')->getValue($plugin))->toBeFalse()
        ->and($ref->getProperty('aboutEnabled')->getValue($plugin))->toBeFalse()
        ->and($ref->getProperty('healthEnabled')->getValue($plugin))->toBeFalse()
        ->and($ref->getProperty('queueMonitorEnabled')->getValue($plugin))->toBeFalse()
        ->and($ref->getProperty('smartMigrationEnabled')->getValue($plugin))->toBeFalse();
});

it('returns fluent interface from toggle methods', function () {
    $plugin = FilamentSystemToolsPlugin::make();

    expect($plugin->enableLogs(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->enableBackups(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->enableAbout(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->enableHealth(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->enableQueueMonitor(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->enableSmartMigration(true))->toBeInstanceOf(FilamentSystemToolsPlugin::class)
        ->and($plugin->navigationGroup('Test'))->toBeInstanceOf(FilamentSystemToolsPlugin::class);
});

it('does not expose a removed enableCache toggle', function () {
    expect(method_exists(FilamentSystemToolsPlugin::class, 'enableCache'))->toBeFalse();
});

it('uses config navigation group by default', function () {
    config(['filament-system-tools.navigation_group' => 'Admin Tools']);

    $plugin = FilamentSystemToolsPlugin::make();

    expect($plugin->getNavigationGroup())->toBe('Admin Tools');
});

it('prefers explicit navigation group over config', function () {
    config(['filament-system-tools.navigation_group' => 'Admin Tools']);

    $plugin = FilamentSystemToolsPlugin::make()
        ->navigationGroup('Custom Group');

    expect($plugin->getNavigationGroup())->toBe('Custom Group');
});

it('falls back to null when navigation group is explicitly null', function () {
    config(['filament-system-tools.navigation_group' => null]);

    $plugin = FilamentSystemToolsPlugin::make();

    expect($plugin->getNavigationGroup())->toBeNull();
});
