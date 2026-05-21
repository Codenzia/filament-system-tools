<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Pages\DatabaseBackup;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function backupCommand(PendingProcess $p): string
{
    return is_array($p->command) ? implode(' ', $p->command) : (string) $p->command;
}

beforeEach(function () {
    $this->backupPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fst-backups-'.uniqid();
    config()->set('filament-system-tools.backup_path', $this->backupPath);
    File::ensureDirectoryExists($this->backupPath);
});

afterEach(function () {
    if (isset($this->backupPath) && File::isDirectory($this->backupPath)) {
        File::deleteDirectory($this->backupPath);
    }
});

it('lists only sqlite/mysql/mariadb/pgsql connections in the picker', function () {
    config()->set('database.connections.unsupported', ['driver' => 'sqlsrv']);
    config()->set('database.connections.app_sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('database.connections.app_mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'username' => 'u',
    ]);

    $options = (new DatabaseBackup)->getConnectionOptions();

    expect($options)->toHaveKeys(['app_sqlite', 'app_mysql'])
        ->and($options)->not->toHaveKey('unsupported');
});

it('uses the SQLite file-copy fast path when no gzip and no table filter is requested', function () {
    $sqlite = $this->backupPath.DIRECTORY_SEPARATOR.'source.sqlite';
    File::put($sqlite, 'SQLite format 3'."\x00".str_repeat("\x00", 100));

    config()->set('database.default', 'fixture_sqlite');
    config()->set('database.connections.fixture_sqlite', [
        'driver' => 'sqlite',
        'database' => $sqlite,
    ]);

    Process::fake();

    (new DatabaseBackup)->createBackup();

    // Fast path: file copy — no Process should have been invoked.
    Process::assertNothingRan();

    $files = File::files($this->backupPath);
    $copied = collect($files)->first(fn ($f) => str_starts_with($f->getFilename(), 'backup-fixture_sqlite-'));

    expect($copied)->not->toBeNull()
        ->and($copied->getExtension())->toBe('sql');
});

it('routes through the service when gzip is requested for SQLite', function () {
    $sqlite = $this->backupPath.DIRECTORY_SEPARATOR.'source.sqlite';
    File::put($sqlite, 'SQLite format 3'."\x00");

    config()->set('database.default', 'fixture_sqlite');
    config()->set('database.connections.fixture_sqlite', [
        'driver' => 'sqlite',
        'database' => $sqlite,
    ]);

    Process::fake();

    (new DatabaseBackup)->createBackup(gzip: true);

    Process::assertRan(fn (PendingProcess $p) => str_contains(backupCommand($p), 'sqlite3') && str_contains(backupCommand($p), '| gzip -c'));
});

it('routes through the service for MySQL backups', function () {
    config()->set('database.default', 'fixture_mysql');
    config()->set('database.connections.fixture_mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'app',
        'username' => 'u',
        'password' => 'p',
    ]);

    Process::fake();

    (new DatabaseBackup)->createBackup();

    Process::assertRan(function (PendingProcess $p) {
        $cmd = backupCommand($p);

        return str_contains($cmd, 'mysqldump')
            && str_contains($cmd, '--user='.escapeshellarg('u'));
    });
});

it('detects connection from filename and restores via file copy for raw SQLite backups', function () {
    $sqlite = $this->backupPath.DIRECTORY_SEPARATOR.'live.sqlite';
    File::put($sqlite, 'SQLite format 3'."\x00".str_repeat("\x01", 100));

    config()->set('database.connections.live_sqlite', [
        'driver' => 'sqlite',
        'database' => $sqlite,
    ]);
    config()->set('database.default', 'live_sqlite');

    // Place a "raw sqlite binary" backup file matching the new naming scheme
    $backupName = 'backup-live_sqlite-2026-05-06_120000.sql';
    $backupPath = $this->backupPath.DIRECTORY_SEPARATOR.$backupName;
    File::put($backupPath, 'SQLite format 3'."\x00".str_repeat("\x02", 100));

    Process::fake();

    (new DatabaseBackup)->restoreBackup($backupName);

    // Fast path: no Process invocation should occur.
    Process::assertNothingRan();

    expect(File::get($sqlite))->toContain("\x02");
});

it('routes restore through the service for gzipped backups', function () {
    config()->set('database.connections.live_mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'app',
        'username' => 'u',
        'password' => 'p',
    ]);
    config()->set('database.default', 'live_mysql');

    $backupName = 'backup-live_mysql-2026-05-06_120000.sql.gz';
    File::put($this->backupPath.DIRECTORY_SEPARATOR.$backupName, 'fake-gz');

    Process::fake();

    (new DatabaseBackup)->restoreBackup($backupName);

    Process::assertRan(function (PendingProcess $p) {
        $cmd = backupCommand($p);

        return str_contains($cmd, 'gunzip -c') && str_contains($cmd, 'mysql');
    });
});

it('lists existing backups sorted newest first', function () {
    $a = $this->backupPath.DIRECTORY_SEPARATOR.'backup-old.sql';
    $b = $this->backupPath.DIRECTORY_SEPARATOR.'backup-new.sql';

    File::put($a, 'old');
    touch($a, time() - 3600);

    File::put($b, 'new');
    touch($b, time());

    $backups = (new DatabaseBackup)->getBackupFiles();

    expect($backups)->toHaveCount(2)
        ->and($backups[0]['name'])->toBe('backup-new.sql')
        ->and($backups[1]['name'])->toBe('backup-old.sql');
});
