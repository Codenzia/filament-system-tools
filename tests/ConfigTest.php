<?php

it('publishes default config values', function () {
    expect(config('filament-system-tools.app_name'))->toBe('Laravel')
        ->and(config('filament-system-tools.navigation_group'))->toBe('System')
        ->and(config('filament-system-tools.release.version'))->toBe('1.0.0')
        ->and(config('filament-system-tools.release.name'))->toBe('')
        ->and(config('filament-system-tools.release.date'))->toBe('');
});

it('has default excluded tables', function () {
    $excluded = config('filament-system-tools.excluded_tables');

    expect($excluded)->toBeArray()
        ->and($excluded)->toContain('migrations')
        ->and($excluded)->toContain('sessions')
        ->and($excluded)->toContain('cache')
        ->and($excluded)->toContain('failed_jobs')
        ->and($excluded)->toContain('password_reset_tokens');
});

it('has a default backup path', function () {
    $backupPath = config('filament-system-tools.backup_path');

    expect($backupPath)->toBeString()
        ->and($backupPath)->toContain('backups');
});
