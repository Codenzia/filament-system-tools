<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Services\DatabaseSqlTool;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** Helper: extract the string command from a recorded fake process. */
function processCommand(PendingProcess $p): string
{
    return is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;
}

/** Helper: temp file path that's guaranteed writable on the test runner's OS. */
function tmpFilePath(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.$name;
}

/** Helper: shell-escaped path (matches whatever the OS does — single-quotes on Linux, double-quotes on Windows). */
function shArg(string $value): string
{
    return escapeshellarg($value);
}

beforeEach(function () {
    config()->set('database.connections.test_sqlite', [
        'driver' => 'sqlite',
        'database' => tmpFilePath('test.sqlite'),
        'prefix' => '',
    ]);

    config()->set('database.connections.test_mysql', [
        'driver' => 'mysql',
        'host' => 'db.example.test',
        'port' => '3306',
        'database' => 'app_db',
        'username' => 'app_user',
        'password' => 's3cret',
    ]);

    config()->set('database.connections.test_pgsql', [
        'driver' => 'pgsql',
        'host' => 'pg.example.test',
        'port' => '5432',
        'database' => 'app_db',
        'username' => 'app_user',
        'password' => 'pgs3cret',
    ]);

    config()->set('filament-system-tools.dump.sqlite.sqlite3', 'sqlite3');
    config()->set('filament-system-tools.dump.mysql.mysqldump', 'mysqldump');
    config()->set('filament-system-tools.dump.mysql.mysql', 'mysql');
    config()->set('filament-system-tools.dump.pgsql.pg_dump', 'pg_dump');
    config()->set('filament-system-tools.dump.pgsql.psql', 'psql');
    config()->set('filament-system-tools.dump.compression.gzip', 'gzip');
    config()->set('filament-system-tools.dump.compression.gunzip', 'gunzip');
});

afterEach(function () {
    foreach (['dump.sql', 'dump.sql.gz'] as $name) {
        $path = tmpFilePath($name);
        if (File::exists($path)) {
            File::delete($path);
        }
    }
});

it('rejects unknown connections', function () {
    Process::fake();

    expect(fn () => app(DatabaseSqlTool::class)->export(connection: 'no_such_connection'))
        ->toThrow(RuntimeException::class, 'Unknown database connection');
});

it('builds a sqlite export command using sqlite3 .dump', function () {
    Process::fake();

    $dumpPath = tmpFilePath('dump.sql');
    $sqlitePath = tmpFilePath('test.sqlite');

    app(DatabaseSqlTool::class)->export(
        connection: 'test_sqlite',
        path: $dumpPath,
        gzip: false,
    );

    Process::assertRan(function (PendingProcess $p) use ($dumpPath, $sqlitePath) {
        $cmd = processCommand($p);

        return str_contains($cmd, 'sqlite3')
            && str_contains($cmd, shArg($sqlitePath))
            && str_contains($cmd, shArg('.dump'))
            && str_contains($cmd, '> '.shArg($dumpPath));
    });
});

it('pipes sqlite export through gzip when requested', function () {
    Process::fake();

    app(DatabaseSqlTool::class)->export(
        connection: 'test_sqlite',
        path: tmpFilePath('dump.sql.gz'),
        gzip: true,
    );

    Process::assertRan(fn (PendingProcess $p) => str_contains(processCommand($p), '| gzip -c > '));
});

it('appends specific tables to the sqlite .dump directive', function () {
    Process::fake();

    app(DatabaseSqlTool::class)->export(
        connection: 'test_sqlite',
        path: tmpFilePath('dump.sql'),
        tables: ['users', 'posts'],
    );

    Process::assertRan(fn (PendingProcess $p) => str_contains(processCommand($p), shArg('.dump users posts')));
});

it('builds a mysql export command without leaking the password on the command line', function () {
    Process::fake();

    app(DatabaseSqlTool::class)->export(
        connection: 'test_mysql',
        path: tmpFilePath('dump.sql'),
        gzip: false,
    );

    Process::assertRan(function (PendingProcess $p) {
        $cmd = processCommand($p);

        return str_contains($cmd, 'mysqldump')
            && str_contains($cmd, '--host='.shArg('db.example.test'))
            && str_contains($cmd, '--port='.shArg('3306'))
            && str_contains($cmd, '--user='.shArg('app_user'))
            && str_contains($cmd, shArg('app_db'))
            && ! str_contains($cmd, 's3cret')
            && ! str_contains($cmd, '--password');
    });
});

it('limits mysql export to specific tables when provided', function () {
    Process::fake();

    app(DatabaseSqlTool::class)->export(
        connection: 'test_mysql',
        path: tmpFilePath('dump.sql'),
        tables: ['users', 'orders'],
    );

    Process::assertRan(function (PendingProcess $p) {
        $cmd = processCommand($p);

        return str_contains($cmd, shArg('app_db'))
            && str_contains($cmd, shArg('users'))
            && str_contains($cmd, shArg('orders'));
    });
});

it('builds a pg_dump command without leaking the password on the command line', function () {
    Process::fake();

    app(DatabaseSqlTool::class)->export(
        connection: 'test_pgsql',
        path: tmpFilePath('dump.sql'),
    );

    Process::assertRan(function (PendingProcess $p) {
        $cmd = processCommand($p);

        return str_contains($cmd, 'pg_dump')
            && str_contains($cmd, '--host='.shArg('pg.example.test'))
            && str_contains($cmd, '--username='.shArg('app_user'))
            && ! str_contains($cmd, 'pgs3cret');
    });
});

it('refuses per-table filtering for postgres exports', function () {
    Process::fake();

    expect(fn () => app(DatabaseSqlTool::class)->export(
        connection: 'test_pgsql',
        path: tmpFilePath('dump.sql'),
        tables: ['users'],
    ))->toThrow(RuntimeException::class, 'PostgreSQL export does not support per-table filtering');
});

it('builds a sqlite import command using stdin redirect', function () {
    $dumpPath = tmpFilePath('dump.sql');
    $sqlitePath = tmpFilePath('test.sqlite');

    File::put($dumpPath, 'CREATE TABLE foo (id INTEGER);');
    Process::fake();

    app(DatabaseSqlTool::class)->import(
        path: $dumpPath,
        connection: 'test_sqlite',
    );

    Process::assertRan(function (PendingProcess $p) use ($dumpPath, $sqlitePath) {
        $cmd = processCommand($p);

        return str_contains($cmd, 'sqlite3 '.shArg($sqlitePath).' < '.shArg($dumpPath));
    });
});

it('pipes gzipped imports through gunzip', function () {
    $gzPath = tmpFilePath('dump.sql.gz');
    $sqlitePath = tmpFilePath('test.sqlite');

    File::put($gzPath, 'fake gzipped bytes');
    Process::fake();

    app(DatabaseSqlTool::class)->import(
        path: $gzPath,
        connection: 'test_sqlite',
    );

    Process::assertRan(function (PendingProcess $p) use ($gzPath, $sqlitePath) {
        $cmd = processCommand($p);

        return str_contains($cmd, 'gunzip -c '.shArg($gzPath))
            && str_contains($cmd, '| sqlite3 '.shArg($sqlitePath));
    });
});

it('rejects imports when the source file is missing', function () {
    expect(fn () => app(DatabaseSqlTool::class)->import(
        path: tmpFilePath('does-not-exist-'.uniqid().'.sql'),
        connection: 'test_sqlite',
    ))->toThrow(RuntimeException::class, 'SQL file not found');
});

it('rejects in-memory sqlite for export', function () {
    config()->set('database.connections.memory', [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    Process::fake();

    expect(fn () => app(DatabaseSqlTool::class)->export(
        connection: 'memory',
        path: tmpFilePath('dump.sql'),
    ))->toThrow(RuntimeException::class, 'file-backed database path');
});

it('surfaces helpful guidance when mysqldump is missing', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: 'sh: mysqldump: command not found',
            exitCode: 127,
        ),
    ]);

    expect(fn () => app(DatabaseSqlTool::class)->export(
        connection: 'test_mysql',
        path: tmpFilePath('dump.sql'),
    ))->toThrow(RuntimeException::class, 'mysqldump was not found');
});
