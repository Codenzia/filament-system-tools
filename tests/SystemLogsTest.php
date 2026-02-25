<?php

use Codenzia\FilamentSystemTools\Pages\SystemLogs;

it('returns correct level colors', function () {
    expect(SystemLogs::getLevelColor('emergency'))->toBe('red')
        ->and(SystemLogs::getLevelColor('alert'))->toBe('red')
        ->and(SystemLogs::getLevelColor('critical'))->toBe('red')
        ->and(SystemLogs::getLevelColor('error'))->toBe('orange')
        ->and(SystemLogs::getLevelColor('warning'))->toBe('yellow')
        ->and(SystemLogs::getLevelColor('notice'))->toBe('blue')
        ->and(SystemLogs::getLevelColor('info'))->toBe('green')
        ->and(SystemLogs::getLevelColor('debug'))->toBe('gray');
});

it('returns gray for unknown level', function () {
    expect(SystemLogs::getLevelColor('unknown'))->toBe('gray')
        ->and(SystemLogs::getLevelColor(''))->toBe('gray');
});

it('handles level colors case-insensitively', function () {
    expect(SystemLogs::getLevelColor('ERROR'))->toBe('orange')
        ->and(SystemLogs::getLevelColor('Warning'))->toBe('yellow')
        ->and(SystemLogs::getLevelColor('INFO'))->toBe('green');
});

it('returns empty array when no log files exist', function () {
    $page = new SystemLogs;

    expect($page->getLogFiles())->toBe([]);
});

it('returns null for current log file when no logs exist', function () {
    $page = new SystemLogs;

    expect($page->getCurrentLogFile())->toBeNull();
});

it('returns empty entries when no log file exists', function () {
    $page = new SystemLogs;

    expect($page->getLogEntries())->toBe([]);
});

it('provides all log levels including all option', function () {
    $page = new SystemLogs;
    $levels = $page->getLogLevels();

    expect($levels)->toHaveKey('all')
        ->and($levels)->toHaveKey('emergency')
        ->and($levels)->toHaveKey('alert')
        ->and($levels)->toHaveKey('critical')
        ->and($levels)->toHaveKey('error')
        ->and($levels)->toHaveKey('warning')
        ->and($levels)->toHaveKey('notice')
        ->and($levels)->toHaveKey('info')
        ->and($levels)->toHaveKey('debug')
        ->and($levels)->toHaveCount(9);
});

it('parses log content correctly', function () {
    $page = new SystemLogs;

    // Use reflection to call private parseLogContent method
    $method = new ReflectionMethod($page, 'parseLogContent');

    $content = <<<'LOG'
[2025-02-20 10:30:00] local.ERROR: Test error message
Stack trace line 1
Stack trace line 2
[2025-02-20 10:31:00] local.INFO: Test info message
[2025-02-20 10:32:00] local.WARNING: Test warning
LOG;

    $entries = $method->invoke($page, $content);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]['level'])->toBe('ERROR')
        ->and($entries[0]['message'])->toBe('Test error message')
        ->and($entries[0]['context'])->toContain('Stack trace line 1')
        ->and($entries[1]['level'])->toBe('INFO')
        ->and($entries[1]['message'])->toBe('Test info message')
        ->and($entries[1]['context'])->toBe('')
        ->and($entries[2]['level'])->toBe('WARNING');
});

it('parses log entries with timezone offsets', function () {
    $page = new SystemLogs;
    $method = new ReflectionMethod($page, 'parseLogContent');

    $content = '[2025-02-20T10:30:00+03:00] local.ERROR: Timezone test';
    $entries = $method->invoke($page, $content);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['timestamp'])->toBe('2025-02-20T10:30:00+03:00')
        ->and($entries[0]['level'])->toBe('ERROR');
});

it('formats bytes correctly', function () {
    $page = new SystemLogs;
    $method = new ReflectionMethod($page, 'formatBytes');

    // Note: the loop condition uses > (strict greater-than), so boundary values stay in lower unit
    expect($method->invoke($page, 0))->toBe('0 B')
        ->and($method->invoke($page, 1023))->toBe('1023 B')
        ->and($method->invoke($page, 1025))->toBe('1 KB')
        ->and($method->invoke($page, 1048577))->toBe('1 MB')
        ->and($method->invoke($page, 1073741825))->toBe('1 GB');
});

it('has correct default property values', function () {
    $page = new SystemLogs;

    expect($page->lines)->toBe(100)
        ->and($page->level)->toBe('all')
        ->and($page->autoRefresh)->toBeFalse();
});
