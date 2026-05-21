<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Throwable;

class SystemHealth extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'system/health';

    protected string $view = 'filament-system-tools::pages.system-health';

    public string $cacheSize = '0 B';

    public function mount(): void
    {
        $this->refreshCacheSize();
    }

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('System Health');
    }

    public function getTitle(): string
    {
        return __('System Health');
    }

    /* ──────────────────────────────────────────────
     | Health Checks
     ────────────────────────────────────────────── */

    /**
     * @return array<string, array{status: 'ok'|'warning'|'error'|'info', label: string, detail: string}>
     */
    public function getHealthChecks(): array
    {
        $checks = [];

        try {
            DB::connection()->getPdo();
            $checks['database'] = [
                'status' => 'ok',
                'label' => __('Database'),
                'detail' => DB::getDriverName().' — '.DB::getDatabaseName(),
            ];
        } catch (Throwable $e) {
            $checks['database'] = [
                'status' => 'error',
                'label' => __('Database'),
                'detail' => $e->getMessage(),
            ];
        }

        try {
            $token = (string) microtime(true);
            Cache::put('filament_system_tools_health_check', $token, 5);
            $cacheOk = Cache::get('filament_system_tools_health_check') === $token;
            Cache::forget('filament_system_tools_health_check');
            $checks['cache'] = [
                'status' => $cacheOk ? 'ok' : 'error',
                'label' => __('Cache'),
                'detail' => (string) config('cache.default'),
            ];
        } catch (Throwable $e) {
            $checks['cache'] = [
                'status' => 'error',
                'label' => __('Cache'),
                'detail' => $e->getMessage(),
            ];
        }

        $storagePath = storage_path('app');
        $checks['storage'] = [
            'status' => is_writable($storagePath) ? 'ok' : 'error',
            'label' => __('Storage'),
            'detail' => is_writable($storagePath) ? __('Writable') : __('Not writable'),
        ];

        $queueDriver = (string) config('queue.default');
        $pendingJobs = 0;
        if ($queueDriver === 'database') {
            try {
                $pendingJobs = DB::table('jobs')->count();
            } catch (Throwable) {
                // Silent — jobs table may not exist; treated as 0.
            }
        }
        $checks['queue'] = [
            'status' => $queueDriver !== 'sync' ? 'ok' : 'warning',
            'label' => __('Queue'),
            'detail' => $queueDriver
                .($queueDriver === 'database' ? " ({$pendingJobs} ".__('pending').')' : '')
                .($queueDriver === 'sync' ? ' — '.__('Not recommended for production') : ''),
        ];

        $mailDriver = (string) config('mail.default');
        $checks['mail'] = [
            'status' => $mailDriver !== 'log' ? 'ok' : 'warning',
            'label' => __('Mail'),
            'detail' => $mailDriver.($mailDriver === 'log' ? ' — '.__('Emails are logged, not sent') : ''),
        ];

        $debugMode = (bool) config('app.debug');
        $checks['environment'] = [
            'status' => app()->isProduction() && $debugMode ? 'warning' : 'ok',
            'label' => __('Environment'),
            'detail' => (string) config('app.env').($debugMode ? ' — '.__('Debug mode ON') : ''),
        ];

        $checks['https'] = [
            'status' => request()->isSecure() ? 'ok' : 'warning',
            'label' => 'HTTPS',
            'detail' => request()->isSecure() ? __('Secure') : __('Not secure — enable HTTPS for production'),
        ];

        $checks['storage_link'] = [
            'status' => $this->storageLinkExists() ? 'ok' : 'warning',
            'label' => __('Storage symlink'),
            'detail' => $this->storageLinkExists()
                ? public_path('storage')
                : __('Missing — public/storage is not linked to storage/app/public'),
        ];

        $checks['scheduler'] = [
            'status' => 'info',
            'label' => __('Scheduler'),
            'detail' => __('Ensure cron is configured: * * * * * php artisan schedule:run'),
        ];

        return $checks;
    }

    /**
     * @return array<string, array{label: string, passed: bool}>
     */
    public function getProductionChecklist(): array
    {
        $queueDriver = (string) config('queue.default');
        $mailDriver = (string) config('mail.default');

        return [
            'database' => [
                'label' => __('Database is MySQL / MariaDB / PostgreSQL'),
                'passed' => in_array(DB::getDriverName(), ['mysql', 'pgsql', 'mariadb'], true),
            ],
            'queue' => [
                'label' => __('Queue driver is not "sync"'),
                'passed' => $queueDriver !== 'sync',
            ],
            'mail' => [
                'label' => __('Mail driver is not "log"'),
                'passed' => $mailDriver !== 'log',
            ],
            'env' => [
                'label' => 'APP_ENV=production',
                'passed' => app()->isProduction(),
            ],
            'debug' => [
                'label' => 'APP_DEBUG=false',
                'passed' => ! config('app.debug'),
            ],
            'https' => [
                'label' => __('HTTPS is enabled'),
                'passed' => request()->isSecure(),
            ],
            'storage_link' => [
                'label' => __('Storage symlink exists'),
                'passed' => $this->storageLinkExists(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getEnvironmentInfo(): array
    {
        return [
            __('PHP Version') => PHP_VERSION,
            __('Laravel Version') => app()->version(),
            __('Server Software') => (string) ($_SERVER['SERVER_SOFTWARE'] ?? __('Unknown')),
            __('Database Driver') => DB::getDriverName(),
            __('Cache Driver') => (string) config('cache.default'),
            __('Session Driver') => (string) config('session.driver'),
            __('Queue Driver') => (string) config('queue.default'),
            __('Mail Driver') => (string) config('mail.default'),
            __('App Environment') => (string) config('app.env'),
            __('Debug Mode') => config('app.debug') ? __('Yes') : __('No'),
            __('Timezone') => (string) config('app.timezone'),
            __('Locale') => (string) config('app.locale'),
            __('Memory Limit') => (string) (ini_get('memory_limit') ?: '—'),
            __('Max Execution Time') => (string) (ini_get('max_execution_time') ?: '0').'s',
            __('Upload Max Filesize') => (string) (ini_get('upload_max_filesize') ?: '—'),
            __('Disk Free') => $this->formatBytes((int) (disk_free_space(base_path()) ?: 0)),
        ];
    }

    /* ──────────────────────────────────────────────
     | Cache Management
     ────────────────────────────────────────────── */

    /**
     * Compute on-disk cache footprint (framework cache + compiled views).
     */
    public function refreshCacheSize(): void
    {
        $size = 0;

        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/views'),
        ];

        foreach ($paths as $path) {
            if (! File::exists($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $file) {
                $size += $file->getSize();
            }
        }

        $this->cacheSize = $this->formatBytes($size);
    }

    public function canClearApplicationCache(): bool
    {
        return filament()->auth()->user()?->can('clear_application_cache') ?? false;
    }

    public function canClearConfigCache(): bool
    {
        return filament()->auth()->user()?->can('clear_config_cache') ?? false;
    }

    public function canClearRouteCache(): bool
    {
        return filament()->auth()->user()?->can('clear_route_cache') ?? false;
    }

    public function canClearViewCache(): bool
    {
        return filament()->auth()->user()?->can('clear_view_cache') ?? false;
    }

    public function canClearEventCache(): bool
    {
        return filament()->auth()->user()?->can('clear_event_cache') ?? false;
    }

    public function canClearCompiled(): bool
    {
        return filament()->auth()->user()?->can('clear_compiled_files') ?? false;
    }

    public function canOptimizeApplication(): bool
    {
        return filament()->auth()->user()?->can('optimize_application') ?? false;
    }

    public function canGenerateStorageLink(): bool
    {
        return filament()->auth()->user()?->can('manage_storage_link') ?? false;
    }

    public function canRunMigrations(): bool
    {
        return filament()->auth()->user()?->can('run_migrations') ?? false;
    }

    private function denyAction(): void
    {
        Notification::make()->title(__('Unauthorized'))->danger()->send();
    }

    public function clearAllCaches(): void
    {
        if (! $this->canClearApplicationCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('optimize:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('All caches cleared'))->success()->send();
    }

    public function clearApplicationCache(): void
    {
        if (! $this->canClearApplicationCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('cache:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('Application cache cleared'))->success()->send();
    }

    public function clearConfigCache(): void
    {
        if (! $this->canClearConfigCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('config:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('Configuration cache cleared'))->success()->send();
    }

    public function clearRouteCache(): void
    {
        if (! $this->canClearRouteCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('route:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('Route cache cleared'))->success()->send();
    }

    public function clearViewCache(): void
    {
        if (! $this->canClearViewCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('view:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('View cache cleared'))->success()->send();
    }

    public function clearEventCache(): void
    {
        if (! $this->canClearEventCache()) {
            $this->denyAction();

            return;
        }

        Artisan::call('event:clear');
        $this->refreshCacheSize();
        Notification::make()->title(__('Event cache cleared'))->success()->send();
    }

    public function clearCompiled(): void
    {
        if (! $this->canClearCompiled()) {
            $this->denyAction();

            return;
        }

        Artisan::call('clear-compiled');
        $this->refreshCacheSize();
        Notification::make()->title(__('Compiled class file removed'))->success()->send();
    }

    public function optimizeApplication(): void
    {
        if (! $this->canOptimizeApplication()) {
            $this->denyAction();

            return;
        }

        Artisan::call('optimize');
        $this->refreshCacheSize();
        Notification::make()->title(__('Application optimized'))->success()->send();
    }

    /* ──────────────────────────────────────────────
     | Operational Actions
     ────────────────────────────────────────────── */

    /**
     * Create the public/storage symlink. Falls back to a shell `ln -s` (or
     * `mklink /D` on Windows) when PHP's `symlink()` is disabled — common on
     * shared PHP-FPM hosting where the function is in `disable_functions`.
     */
    public function generateStorageLink(): void
    {
        if (! $this->canGenerateStorageLink()) {
            $this->denyAction();

            return;
        }

        $target = storage_path('app/public');
        $link = public_path('storage');

        if ($this->storageLinkExists()) {
            Notification::make()->title(__('Storage symlink already exists'))->info()->send();

            return;
        }

        try {
            Artisan::call('storage:link');

            if ($this->storageLinkExists()) {
                Notification::make()->title(__('Storage symlink created'))->success()->send();

                return;
            }
        } catch (Throwable) {
            // Fall through to shell fallback
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? sprintf('cmd /c mklink /D %s %s', escapeshellarg($link), escapeshellarg($target))
            : sprintf('ln -s %s %s', escapeshellarg($target), escapeshellarg($link));

        $result = Process::run($command);

        if ($result->successful() && $this->storageLinkExists()) {
            Notification::make()->title(__('Storage symlink created'))->success()->send();

            return;
        }

        Notification::make()
            ->title(__('Storage symlink failed'))
            ->body(__('Both PHP symlink() and shell fallback failed. Create the link manually via SSH or your hosting file manager.'))
            ->danger()
            ->send();
    }

    public function runMigrations(): void
    {
        if (! $this->canRunMigrations()) {
            $this->denyAction();

            return;
        }

        if (! config('filament-system-tools.health.allow_run_migrations', false)) {
            Notification::make()
                ->title(__('Running migrations from the UI is disabled'))
                ->body(__('Set filament-system-tools.health.allow_run_migrations to true to enable.'))
                ->warning()
                ->send();

            return;
        }

        try {
            Artisan::call('migrate', ['--force' => true]);

            Notification::make()
                ->title(__('Migrations completed'))
                ->body(trim(Artisan::output()) ?: __('No new migrations to run.'))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title(__('Migration failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /* ──────────────────────────────────────────────
     | Helpers
     ────────────────────────────────────────────── */

    private function storageLinkExists(): bool
    {
        $link = public_path('storage');

        return is_link($link) || (file_exists($link) && is_dir($link));
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        for (; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
