<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function cmdsTmp(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
}

function cmdsArg(string $value): string
{
    return escapeshellarg($value);
}

function cmdsCommand(PendingProcess $p): string
{
    return is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;
}

beforeEach(function () {
    config()->set('database.connections.test_mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'app_db',
        'username' => 'app_user',
        'password' => 's3cret',
    ]);

    config()->set('filament-system-tools.dump.mysql.mysqldump', 'mysqldump');
    config()->set('filament-system-tools.dump.mysql.mysql', 'mysql');
});

afterEach(function () {
    foreach (['cli-export.sql', 'cli-export.sql.gz', 'cli-import.sql'] as $name) {
        $path = cmdsTmp($name);
        if (File::exists($path)) {
            File::delete($path);
        }
    }
});

it('db:export runs the export and reports the path', function () {
    Process::fake();

    $exportPath = cmdsTmp('cli-export.sql');

    $this->artisan('db:export', [
        '--connection' => 'test_mysql',
        '--path' => $exportPath,
    ])
        ->expectsOutputToContain('Database exported to: '.$exportPath)
        ->assertExitCode(0);

    Process::assertRan(function (PendingProcess $p) use ($exportPath) {
        $cmd = cmdsCommand($p);

        return str_contains($cmd, 'mysqldump')
            && str_contains($cmd, '> '.cmdsArg($exportPath));
    });
});

it('db:export auto-appends .gz when --gzip is set with a non-gz path', function () {
    Process::fake();

    $exportPath = cmdsTmp('cli-export.sql');

    $this->artisan('db:export', [
        '--connection' => 'test_mysql',
        '--path' => $exportPath,
        '--gzip' => true,
    ])
        ->expectsOutputToContain('Database exported to: '.$exportPath.'.gz')
        ->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $p) => str_contains(cmdsCommand($p), '| gzip -c'));
});

it('db:export forwards --table options to the service', function () {
    Process::fake();

    $this->artisan('db:export', [
        '--connection' => 'test_mysql',
        '--path' => cmdsTmp('cli-export.sql'),
        '--table' => ['users', 'orders'],
    ])->assertExitCode(0);

    Process::assertRan(function (PendingProcess $p) {
        $cmd = cmdsCommand($p);

        return str_contains($cmd, cmdsArg('users'))
            && str_contains($cmd, cmdsArg('orders'));
    });
});

it('db:export reports failures as exit code 1', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'sh: mysqldump: command not found',
            exitCode: 127,
        ),
    ]);

    $this->artisan('db:export', [
        '--connection' => 'test_mysql',
        '--path' => cmdsTmp('cli-export.sql'),
    ])
        ->expectsOutputToContain('mysqldump was not found')
        ->assertExitCode(1);
});

it('db:import refuses to run without --force', function () {
    $importPath = cmdsTmp('cli-import.sql');
    File::put($importPath, 'SELECT 1;');

    $this->artisan('db:import', [
        'path' => $importPath,
        '--connection' => 'test_mysql',
    ])
        ->expectsOutputToContain('Refusing to import without --force')
        ->assertExitCode(1);
});

it('db:import runs when --force is provided', function () {
    $importPath = cmdsTmp('cli-import.sql');
    File::put($importPath, 'SELECT 1;');
    Process::fake();

    $this->artisan('db:import', [
        'path' => $importPath,
        '--connection' => 'test_mysql',
        '--force' => true,
    ])
        ->expectsOutputToContain('Database imported from: '.$importPath)
        ->assertExitCode(0);

    Process::assertRan(function (PendingProcess $p) use ($importPath) {
        $cmd = cmdsCommand($p);

        return str_contains($cmd, 'mysql')
            && str_contains($cmd, '< '.cmdsArg($importPath));
    });
});
