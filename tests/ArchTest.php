<?php

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('pages extend Filament Page')
    ->expect('Codenzia\FilamentSystemTools\Pages')
    ->toExtend('Filament\Pages\Page');
