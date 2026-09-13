<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Livewire\SqlQueryRunner;
use Codenzia\FilamentSystemTools\Pages\DatabaseBackup;
use Codenzia\FilamentSystemTools\Pages\SystemHealth;
use Codenzia\FilamentSystemTools\Services\SmartMigration\TableName;
use Codenzia\FilamentSystemTools\Support\SqlStatementSplitter;

it('splits a single statement into one entry', function () {
    expect(SqlStatementSplitter::split('SELECT * FROM users'))->toHaveCount(1);
});

it('splits multiple statements', function () {
    expect(SqlStatementSplitter::split('DROP TABLE x; SELECT 1'))->toHaveCount(2);
});

it('does not split on a semicolon inside a quoted literal', function () {
    expect(SqlStatementSplitter::split("INSERT INTO t (a) VALUES ('a; b')"))->toHaveCount(1);
});

it('ignores semicolons inside comments', function () {
    expect(SqlStatementSplitter::split("SELECT 1 -- a; b\n"))->toHaveCount(1);
});

it('strips schema prefixes from table names', function () {
    expect(TableName::strip('public.users'))->toBe('users')
        ->and(TableName::strip('users'))->toBe('users')
        ->and(TableName::strip('db.schema.orders'))->toBe('orders');
});

it('resolves a real backup file inside the backup directory', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fst_backups_'.bin2hex(random_bytes(4));
    mkdir($dir);
    $file = $dir.DIRECTORY_SEPARATOR.'backup-testing-2026-07-03_101010.sql';
    file_put_contents($file, 'SELECT 1;');
    config(['filament-system-tools.backup_path' => $dir]);

    $method = new ReflectionMethod(DatabaseBackup::class, 'resolveBackupPath');
    $resolved = $method->invoke(new DatabaseBackup, 'backup-testing-2026-07-03_101010.sql');

    expect($resolved)->toBe(realpath($file));

    unlink($file);
    rmdir($dir);
});

it('refuses a traversal payload and files not in the listing', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fst_backups_'.bin2hex(random_bytes(4));
    mkdir($dir);
    config(['filament-system-tools.backup_path' => $dir]);

    $method = new ReflectionMethod(DatabaseBackup::class, 'resolveBackupPath');
    $page = new DatabaseBackup;

    expect($method->invoke($page, '../../.env'))->toBeNull()
        ->and($method->invoke($page, 'does-not-exist.sql'))->toBeNull();

    rmdir($dir);
});

it('classifies only genuine read statements as read-only', function () {
    $method = new ReflectionMethod(SqlQueryRunner::class, 'isReadStatement');
    $runner = new SqlQueryRunner;

    $isRead = fn (string $sql): bool => $method->invoke($runner, strtoupper($sql));

    expect($isRead('SELECT * FROM users'))->toBeTrue()
        ->and($isRead('EXPLAIN SELECT * FROM users'))->toBeTrue()
        ->and($isRead('PRAGMA table_info(users)'))->toBeTrue()
        ->and($isRead('WITH recent AS (SELECT 1) SELECT * FROM recent'))->toBeTrue()
        ->and($isRead('PRAGMA foreign_keys = OFF'))->toBeFalse()
        ->and($isRead('PRAGMA journal_mode'))->toBeFalse()
        ->and($isRead('WITH x AS (SELECT id FROM users) DELETE FROM posts'))->toBeFalse()
        ->and($isRead('UPDATE users SET name = 1'))->toBeFalse()
        ->and($isRead('DROP TABLE users'))->toBeFalse();
});

it('redacts literals from an audited statement', function () {
    $method = new ReflectionMethod(SqlQueryRunner::class, 'redact');

    $redacted = $method->invoke(new SqlQueryRunner, "UPDATE users SET api_token = 'super-secret' WHERE id = 1");

    expect($redacted)->not->toContain('super-secret')
        ->and($redacted)->toContain('UPDATE users SET api_token =');
});

it('requires every cache capability before clearing all caches', function () {
    $page = new class extends SystemHealth
    {
        public bool $allowCompiled = true;

        public function canClearApplicationCache(): bool
        {
            return true;
        }

        public function canClearConfigCache(): bool
        {
            return true;
        }

        public function canClearRouteCache(): bool
        {
            return true;
        }

        public function canClearViewCache(): bool
        {
            return true;
        }

        public function canClearEventCache(): bool
        {
            return true;
        }

        public function canClearCompiled(): bool
        {
            return $this->allowCompiled;
        }
    };

    expect($page->canClearAllCaches())->toBeTrue();

    $page->allowCompiled = false;

    expect($page->canClearAllCaches())->toBeFalse();
});
