<?php

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class SystemCache extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 100;

    protected static ?string $title = 'Cache Management';

    protected static ?string $slug = 'system/cache';

    protected string $view = 'filament-system-tools::pages.system-cache';

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public function clearAllCache(): void
    {
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');
        Artisan::call('event:clear');

        Notification::make()
            ->title(__('All caches cleared successfully!'))
            ->success()
            ->send();
    }

    public function clearApplicationCache(): void
    {
        Artisan::call('cache:clear');

        Notification::make()
            ->title(__('Application cache cleared!'))
            ->success()
            ->send();
    }

    public function clearConfigCache(): void
    {
        Artisan::call('config:clear');

        Notification::make()
            ->title(__('Configuration cache cleared!'))
            ->success()
            ->send();
    }

    public function clearRouteCache(): void
    {
        Artisan::call('route:clear');

        Notification::make()
            ->title(__('Route cache cleared!'))
            ->success()
            ->send();
    }

    public function clearViewCache(): void
    {
        Artisan::call('view:clear');

        Notification::make()
            ->title(__('View cache cleared!'))
            ->success()
            ->send();
    }

    public function optimizeApplication(): void
    {
        Artisan::call('optimize');

        Notification::make()
            ->title(__('Application optimized!'))
            ->body(__('Config, routes, and views have been cached.'))
            ->success()
            ->send();
    }

    public function getSystemInfo(): array
    {
        return [
            'PHP Version' => PHP_VERSION,
            'Laravel Version' => app()->version(),
            'Environment' => app()->environment(),
            'Debug Mode' => config('app.debug') ? 'Enabled' : 'Disabled',
            'Cache Driver' => config('cache.default'),
            'Session Driver' => config('session.driver'),
            'Queue Driver' => config('queue.default'),
            'Timezone' => config('app.timezone'),
            'Locale' => config('app.locale'),
        ];
    }
}
