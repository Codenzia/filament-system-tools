<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Livewire\SqlQueryRunner;
use Codenzia\FilamentSystemTools\Livewire\TableDataViewer;
use Codenzia\FilamentSystemTools\Livewire\TableSchemaViewer;
use Codenzia\FilamentSystemTools\Pages\About;
use Codenzia\FilamentSystemTools\Pages\DatabaseBackup;
use Codenzia\FilamentSystemTools\Pages\QueueMonitor;
use Codenzia\FilamentSystemTools\Pages\SmartDataMigration;
use Codenzia\FilamentSystemTools\Pages\SystemHealth;
use Codenzia\FilamentSystemTools\Pages\SystemLogs;
use Filament\Pages\BasePage as FilamentPage;
use Livewire\Component as LivewireComponent;

/**
 * BVT — Build Verification Test (existence net).
 *
 * A thin roll-call over every shipped Filament page and Livewire component: the
 * class must load (no autoload/type fatal), extend the base type it claims, and
 * expose a resolvable package view. Deep behaviour lives in the feature suites
 * (SystemHealthTest, DatabaseBackupTest, QueueMonitorTest, SystemLogsTest,
 * DatabaseSqlToolTest, SmartImporterIntegrationTest, …). The full render of the
 * table/schema viewers needs a booted Filament panel, so they are covered here
 * by class/view roll-call only.
 */
it('every shipped Filament page loads, is a Filament page, and its view resolves', function (string $class): void {
    expect(class_exists($class))->toBeTrue()
        ->and(is_subclass_of($class, FilamentPage::class))->toBeTrue();

    $view = (new ReflectionClass($class))->getProperty('view')->getDefaultValue();

    expect($view)->toBeString()
        ->and(view()->exists($view))->toBeTrue();
})->with([
    'About' => [About::class],
    'DatabaseBackup' => [DatabaseBackup::class],
    'QueueMonitor' => [QueueMonitor::class],
    'SmartDataMigration' => [SmartDataMigration::class],
    'SystemHealth' => [SystemHealth::class],
    'SystemLogs' => [SystemLogs::class],
]);

it('every shipped Livewire component loads and is a Livewire component', function (string $class): void {
    expect(class_exists($class))->toBeTrue()
        ->and(is_subclass_of($class, LivewireComponent::class))->toBeTrue();
})->with([
    'SqlQueryRunner' => [SqlQueryRunner::class],
    'TableDataViewer' => [TableDataViewer::class],
    'TableSchemaViewer' => [TableSchemaViewer::class],
]);

it('every shipped view namespace path resolves', function (string $view): void {
    expect(view()->exists($view))->toBeTrue();
})->with([
    'filament-system-tools::pages.about',
    'filament-system-tools::pages.database-backup',
    'filament-system-tools::pages.queue-monitor',
    'filament-system-tools::pages.smart-data-migration',
    'filament-system-tools::pages.system-health',
    'filament-system-tools::pages.system-logs',
    'filament-system-tools::pages.partials.sql-query-modal',
    'filament-system-tools::pages.partials.table-data-modal',
    'filament-system-tools::pages.partials.table-schema-modal',
    'filament-system-tools::livewire.sql-query-runner',
    'filament-system-tools::livewire.table-data-viewer',
    'filament-system-tools::livewire.table-schema-viewer',
]);
