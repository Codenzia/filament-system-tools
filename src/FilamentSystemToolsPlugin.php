<?php

namespace Codenzia\FilamentSystemTools;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament panel plugin that registers the system administration pages.
 */
class FilamentSystemToolsPlugin implements Plugin
{
    protected bool $logsEnabled = true;

    protected bool $cacheEnabled = true;

    protected bool $backupsEnabled = true;

    protected bool $aboutEnabled = true;

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

    public function enableCache(bool $enabled = true): static
    {
        $this->cacheEnabled = $enabled;

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

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $pages = [];

        if ($this->logsEnabled) {
            $pages[] = Pages\SystemLogs::class;
        }

        if ($this->cacheEnabled) {
            $pages[] = Pages\SystemCache::class;
        }

        if ($this->backupsEnabled) {
            $pages[] = Pages\DatabaseBackup::class;
        }

        if ($this->aboutEnabled) {
            $pages[] = Pages\About::class;
        }

        $panel->pages($pages);
    }

    public function boot(Panel $panel): void {}

    public function getNavigationGroup(): ?string
    {
        return $this->navigationGroup ?? config('filament-system-tools.navigation_group', 'System');
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
