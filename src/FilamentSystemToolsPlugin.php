<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament panel plugin that registers the system administration pages.
 */
class FilamentSystemToolsPlugin implements Plugin
{
    protected bool $logsEnabled = true;

    protected bool $backupsEnabled = true;

    protected bool $aboutEnabled = true;

    protected bool $healthEnabled = true;

    protected bool $queueMonitorEnabled = true;

    protected bool $smartMigrationEnabled = true;

    protected ?string $navigationGroup = null;

    public function getId(): string
    {
        return 'filament-system-tools';
    }

    public function enableLogs(bool $enabled = true): static
    {
        $this->logsEnabled = $enabled;

        return $this;
    }

    public function enableBackups(bool $enabled = true): static
    {
        $this->backupsEnabled = $enabled;

        return $this;
    }

    public function enableAbout(bool $enabled = true): static
    {
        $this->aboutEnabled = $enabled;

        return $this;
    }

    public function enableHealth(bool $enabled = true): static
    {
        $this->healthEnabled = $enabled;

        return $this;
    }

    public function enableQueueMonitor(bool $enabled = true): static
    {
        $this->queueMonitorEnabled = $enabled;

        return $this;
    }

    public function enableSmartMigration(bool $enabled = true): static
    {
        $this->smartMigrationEnabled = $enabled;

        return $this;
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $pages = [];

        if ($this->healthEnabled) {
            $pages[] = Pages\SystemHealth::class;
        }

        if ($this->logsEnabled) {
            $pages[] = Pages\SystemLogs::class;
        }

        if ($this->backupsEnabled) {
            $pages[] = Pages\DatabaseBackup::class;
        }

        if ($this->smartMigrationEnabled) {
            $pages[] = Pages\SmartDataMigration::class;
        }

        if ($this->queueMonitorEnabled) {
            $pages[] = Pages\QueueMonitor::class;
        }

        if ($this->aboutEnabled) {
            $pages[] = Pages\About::class;
        }

        $panel->pages($pages);
    }

    public function boot(Panel $panel): void {}

    public function getNavigationGroup(): ?string
    {
        $group = $this->navigationGroup ?? config('filament-system-tools.navigation_group', 'System');

        return $group ? __($group) : null;
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
