<?php

namespace Codenzia\FilamentSystemTools;

use Filament\Support\Facades\FilamentIcon;
use Codenzia\FilamentSystemTools\Livewire\SqlQueryRunner;
use Codenzia\FilamentSystemTools\Livewire\TableDataViewer;
use Codenzia\FilamentSystemTools\Livewire\TableSchemaViewer;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentSystemToolsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-system-tools';

    public static string $viewNamespace = 'filament-system-tools';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('codenzia/filament-system-tools');
            });

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void {}

    public function packageBooted(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', static::$viewNamespace);

        // Register Livewire components
        Livewire::component('filament-system-tools::table-data-viewer', TableDataViewer::class);
        Livewire::component('filament-system-tools::sql-query-runner', SqlQueryRunner::class);
        Livewire::component('filament-system-tools::table-schema-viewer', TableSchemaViewer::class);

        FilamentIcon::register($this->getIcons());
    }

    /** @return array<string> */
    protected function getIcons(): array
    {
        return [];
    }
}
