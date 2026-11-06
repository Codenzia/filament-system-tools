<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Models\DatabaseTable;
use Codenzia\FilamentSystemTools\Pages\DatabaseBackup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'options' => [PDO::ATTR_STRINGIFY_FETCHES => true],
    ]);
    config()->set('filament-system-tools.excluded_tables', ['hidden']);

    DB::statement("ATTACH DATABASE ':memory:' AS information_schema");
    DB::statement('CREATE TABLE information_schema.tables (
        TABLE_NAME TEXT, TABLE_SCHEMA TEXT, TABLE_TYPE TEXT,
        TABLE_ROWS INTEGER, DATA_LENGTH INTEGER, INDEX_LENGTH INTEGER
    )');
    DB::table('information_schema.tables')->insert([
        ['TABLE_NAME' => 'alpha', 'TABLE_SCHEMA' => ':memory:', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_ROWS' => 30, 'DATA_LENGTH' => 1024, 'INDEX_LENGTH' => 0],
        ['TABLE_NAME' => 'beta', 'TABLE_SCHEMA' => ':memory:', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_ROWS' => 10, 'DATA_LENGTH' => 2048, 'INDEX_LENGTH' => 0],
        ['TABLE_NAME' => 'gamma', 'TABLE_SCHEMA' => ':memory:', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_ROWS' => 20, 'DATA_LENGTH' => 512, 'INDEX_LENGTH' => 0],
        ['TABLE_NAME' => 'hidden', 'TABLE_SCHEMA' => ':memory:', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_ROWS' => 0, 'DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0],
        ['TABLE_NAME' => 'foreign', 'TABLE_SCHEMA' => 'other_database', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_ROWS' => 0, 'DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0],
        ['TABLE_NAME' => 'view', 'TABLE_SCHEMA' => ':memory:', 'TABLE_TYPE' => 'VIEW', 'TABLE_ROWS' => 0, 'DATA_LENGTH' => 0, 'INDEX_LENGTH' => 0],
    ]);
});

it('queries qualified metadata aliases and preserves preloaded numeric metrics', function (string $driver) {
    if ($driver === 'mariadb') {
        config()->set('database.connections.mariadb', config('database.connections.mysql'));
        DB::connection('mariadb')->setPdo(DB::connection('mysql')->getPdo());
        config()->set('database.default', 'mariadb');
    }

    $model = new DatabaseTable;
    $table = $model->getTable();
    $alpha = DatabaseTable::query()->whereKey('alpha')->sole();

    expect($alpha->getRowCount())->toBe(30)
        ->and($alpha->getPreloadedSize())->toBe(1024);
    expect(DatabaseTable::query()->whereKey('hidden')->exists())->toBeFalse();
    expect(DatabaseTable::query()->whereKey('foreign')->exists())->toBeFalse();
    expect(DatabaseTable::query()->whereKey('view')->exists())->toBeFalse();
    expect(DatabaseTable::query()->where($table.'.name', 'like', '%bet%')->pluck('name')->all())->toBe(['beta']);

    foreach (['name' => ['alpha', 'beta', 'gamma'], 'rows' => ['beta', 'gamma', 'alpha'], 'size' => ['gamma', 'alpha', 'beta']] as $column => $expected) {
        expect(DatabaseTable::query()->orderBy($table.'.'.$column)->pluck('name')->all())->toBe($expected);
        expect(DatabaseTable::query()->orderByDesc($table.'.'.$column)->pluck('name')->all())->toBe(array_reverse($expected));
    }
})->with(['mysql', 'mariadb']);

it('formats database sizes when the database returns numeric strings', function () {
    expect(DB::selectOne('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS size FROM information_schema.tables WHERE TABLE_SCHEMA = ?', [':memory:'])->size)->toBeString();

    expect((new DatabaseBackup)->getDatabaseStats())->toBe([
        'driver' => 'mysql',
        'size' => '3.5 KB',
        'tables' => 5,
    ]);
});

it('formats an empty database as zero bytes', function () {
    DB::table('information_schema.tables')->delete();

    expect((new DatabaseBackup)->getDatabaseStats())->toBe([
        'driver' => 'mysql',
        'size' => '0 B',
        'tables' => 0,
    ]);
});

it('preserves SQLite metadata queries and row counts', function () {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', config('database.connections.testing'));
    DB::statement('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT)');
    DB::table('widgets')->insert(['id' => 1, 'name' => 'Widget']);

    $widgets = DatabaseTable::query()->whereKey('widgets')->sole();

    expect($widgets->name)->toBe('widgets')
        ->and($widgets->getRowCount())->toBe(1)
        ->and($widgets->getPreloadedSize())->toBeNull();
    expect(DatabaseTable::query()->orderBy('name')->pluck('name')->all())->toBe(['widgets']);
});

it('renders searches sorts and selects MySQL metadata through the native backup page', function () {
    $user = new User;
    $user->id = 1;
    $this->actingAs($user);
    Gate::define('view_database_backups', fn (User $user): bool => true);
    Filament::setCurrentPanel(Filament::getPanel('testing'));
    $alpha = DatabaseTable::query()->whereKey('alpha')->sole();
    $page = Livewire::test(DatabaseBackup::class);

    foreach (['name', 'rows', 'size'] as $column) {
        foreach (['asc', 'desc'] as $direction) {
            $page->sortTable($column, $direction)->assertSuccessful();
        }
    }
    $page->searchTable('alpha')->assertCanSeeTableRecords([$alpha])
        ->selectTableRecords([$alpha->getKey()]);

    expect($page->instance()->getSelectedTableRecords()->sole()->name)->toBe('alpha');
});
