<?php

namespace Codenzia\FilamentSystemTools;

use Codenzia\FilamentSystemTools\Commands\DatabaseExportCommand;
use Codenzia\FilamentSystemTools\Commands\DatabaseImportCommand;
use Codenzia\FilamentSystemTools\Livewire\SqlQueryRunner;
use Codenzia\FilamentSystemTools\Livewire\TableDataViewer;
use Codenzia\FilamentSystemTools\Livewire\TableSchemaViewer;
use Codenzia\FilamentSystemTools\Services\BackgroundWorkerInspector;
use Filament\Support\Facades\FilamentIcon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
            ->hasCommands([
                DatabaseExportCommand::class,
                DatabaseImportCommand::class,
            ])
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', static::$viewNamespace);

        // Register Livewire components
        $components = [
            'filament-system-tools::table-data-viewer' => TableDataViewer::class,
            'filament-system-tools::sql-query-runner' => SqlQueryRunner::class,
            'filament-system-tools::table-schema-viewer' => TableSchemaViewer::class,
        ];

        foreach ($components as $alias => $class) {
            Livewire::component($alias, $class);
        }

        // Livewire v4's Finder only checks registered namespaces for
        // `ns::component` lookups, never classComponents entries.
        if (method_exists(Livewire::getFacadeRoot(), 'resolveMissingComponent')) {
            Livewire::resolveMissingComponent(fn (string $name): ?string => $components[$name] ?? null);
        }

        FilamentIcon::register($this->getIcons());

        $this->applyLogLevelOverride();

        $this->registerBackgroundWorkerHeartbeats();
    }

    /**
     * Heartbeat files for the scheduler + queue workers, used by
     * {@see BackgroundWorkerInspector} to detect whether cron is wired
     * up on the host. The scheduler heartbeat is a one-line scheduled
     * callback; the queue heartbeat hooks Queue::looping(), which fires
     * on every poll of `queue:work` even when no jobs are queued.
     */
    protected function registerBackgroundWorkerHeartbeats(): void
    {
        if (! (bool) config('filament-system-tools.background_workers.heartbeats_enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(static function (): void {
                $path = storage_path(BackgroundWorkerInspector::HEARTBEAT_SCHEDULER);
                @is_dir(dirname($path)) or @mkdir(dirname($path), 0775, true);
                @touch($path);
            })
                ->everyMinute()
                ->name('filament-system-tools:scheduler-heartbeat')
                ->withoutOverlapping();
        });

        Queue::looping(static function (): void {
            $path = storage_path(BackgroundWorkerInspector::HEARTBEAT_QUEUE);
            @is_dir(dirname($path)) or @mkdir(dirname($path), 0775, true);
            @touch($path);
        });
    }

    /**
     * Honour a short-lived "set_log_level" override that super-admins set from
     * the SystemLogs page when troubleshooting in production. The cache key has
     * a TTL (default 30 min) so it self-clears even if the admin forgets.
     */
    protected function applyLogLevelOverride(): void
    {
        try {
            $override = Cache::get('system_tools.log_level_override');
        } catch (\Throwable) {
            return;
        }

        if (! is_string($override) || $override === '') {
            return;
        }

        $channel = (string) config('logging.default');

        if ($channel === '') {
            return;
        }

        config(["logging.channels.{$channel}.level" => $override]);
        Log::forgetChannel($channel);
    }

    /** @return array<string> */
    protected function getIcons(): array
    {
        return [];
    }
}
