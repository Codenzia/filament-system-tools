<?php

namespace Codenzia\FilamentSystemTools\Pages;

use Codenzia\FilamentSystemTools\FilamentSystemToolsPlugin;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class About extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-information-circle';

    protected static ?int $navigationSort = 104;

    protected static ?string $slug = 'system/about';

    protected string $view = 'filament-system-tools::pages.about';

    public static function getNavigationGroup(): ?string
    {
        return FilamentSystemToolsPlugin::make()->getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('About');
    }

    public function getTitle(): string
    {
        return __('About');
    }

    public function getSubheading(): ?string
    {
        return config('app.name');
    }

    /**
     * Get release information from config.
     *
     * @return array{version: string, name: string, date: string}
     */
    public function getReleaseInfo(): array
    {
        return [
            'version' => config('filament-system-tools.release.version', '1.0.0'),
            'name' => config('filament-system-tools.release.name', ''),
            'date' => config('filament-system-tools.release.date', ''),
        ];
    }

    /**
     * Get comprehensive system information for support and troubleshooting.
     *
     * @return array<string, array<string, string>>
     */
    public function getSystemInfo(): array
    {
        return [
            'environment' => $this->getEnvironmentInfo(),
            'server' => $this->getServerInfo(),
            'database' => $this->getDatabaseInfo(),
            'drivers' => $this->getDriverInfo(),
            'storage' => $this->getStorageInfo(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getEnvironmentInfo(): array
    {
        return [
            __('PHP Version') => PHP_VERSION,
            __('Laravel Version') => app()->version(),
            __('Filament Version') => $this->getFilamentVersion(),
            __('Environment') => config('app.env'),
            __('Debug Mode') => config('app.debug') ? __('Enabled') : __('Disabled'),
            __('URL') => config('app.url'),
            __('Timezone') => config('app.timezone'),
            __('Locale') => config('app.locale'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getServerInfo(): array
    {
        return [
            __('Operating System') => PHP_OS_FAMILY . ' ' . php_uname('r'),
            __('Architecture') => php_uname('m'),
            __('Server API') => PHP_SAPI,
            __('Memory Limit') => ini_get('memory_limit') ?: __('Unknown'),
            __('Max Upload Size') => ini_get('upload_max_filesize') ?: __('Unknown'),
            __('Max POST Size') => ini_get('post_max_size') ?: __('Unknown'),
            __('Max Execution Time') => (ini_get('max_execution_time') ?: '0') . 's',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getDatabaseInfo(): array
    {
        $driver = config('database.default');
        $info = [
            __('Driver') => ucfirst($driver),
            __('Host') => config("database.connections.{$driver}.host", '-'),
            __('Database') => config("database.connections.{$driver}.database", '-'),
        ];

        try {
            $version = match ($driver) {
                'mysql', 'mariadb' => DB::selectOne('SELECT VERSION() as version')?->version ?? __('Unknown'),
                'pgsql' => DB::selectOne('SELECT version() as version')?->version ?? __('Unknown'),
                'sqlite' => DB::selectOne('SELECT sqlite_version() as version')?->version ?? __('Unknown'),
                default => __('Unknown'),
            };
            $info[__('Version')] = $version;

            // Table count
            $tables = match ($driver) {
                'mysql', 'mariadb' => DB::select('SHOW TABLES'),
                'pgsql' => DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"),
                'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type='table'"),
                default => [],
            };
            $info[__('Tables')] = (string) count($tables);

            // Database size
            if (in_array($driver, ['mysql', 'mariadb'])) {
                $dbName = config("database.connections.{$driver}.database");
                $size = DB::selectOne(
                    'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.tables WHERE table_schema = ?',
                    [$dbName]
                );
                $info[__('Size')] = ($size?->size ?? '0') . ' MB';
            }
        } catch (\Throwable) {
            $info[__('Version')] = __('Unable to query');
        }

        return $info;
    }

    /**
     * @return array<string, string>
     */
    private function getDriverInfo(): array
    {
        return [
            __('Cache') => config('cache.default'),
            __('Session') => config('session.driver'),
            __('Queue') => config('queue.default'),
            __('Mail') => config('mail.default'),
            __('Filesystem') => config('filesystems.default'),
            __('Broadcasting') => config('broadcasting.default') ?: 'null',
            __('Log Channel') => config('logging.default'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getStorageInfo(): array
    {
        $storagePath = storage_path();
        $info = [];

        try {
            $diskTotal = disk_total_space($storagePath);
            $diskFree = disk_free_space($storagePath);
            $diskUsed = $diskTotal - $diskFree;
            $usagePercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

            $info[__('Disk Total')] = $this->formatBytes((int) $diskTotal);
            $info[__('Disk Used')] = $this->formatBytes((int) $diskUsed);
            $info[__('Disk Free')] = $this->formatBytes((int) $diskFree);
            $info[__('Disk Usage')] = $usagePercent . '%';
        } catch (\Throwable) {
            $info[__('Disk')] = __('Unable to read disk info');
        }

        // Logs directory size
        $logsPath = storage_path('logs');
        if (File::isDirectory($logsPath)) {
            $logsSize = 0;
            foreach (File::allFiles($logsPath) as $file) {
                $logsSize += $file->getSize();
            }
            $info[__('Logs Size')] = $this->formatBytes($logsSize);
        }

        return $info;
    }

    /**
     * Read Filament version from composer.lock.
     */
    private function getFilamentVersion(): string
    {
        $lockPath = base_path('composer.lock');

        if (! File::exists($lockPath)) {
            return __('Unknown');
        }

        try {
            $lock = json_decode(File::get($lockPath), true);
            $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

            foreach ($packages as $package) {
                if ($package['name'] === 'filament/filament') {
                    return ltrim($package['version'] ?? __('Unknown'), 'v');
                }
            }
        } catch (\Throwable) {
            // Fall through
        }

        return __('Unknown');
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
