<?php

declare(strict_types=1);

namespace Codenzia\FilamentSystemTools\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;

/**
 * A minimal panel so components that reach for `filament()` — the table viewers
 * in particular — can be rendered by the suite.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('testing');
    }
}
