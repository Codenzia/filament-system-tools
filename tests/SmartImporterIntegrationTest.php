<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaIntrospector;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartExporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartImporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\TableSorter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('posts');
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('email')->unique();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users');
        $table->string('title');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('posts');
    Schema::dropIfExists('users');
});

it('round-trips data through SmartExporter and SmartImporter with FK remapping', function () {
    DB::table('users')->insert([
        ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 2, 'email' => 'bob@example.com', 'name' => 'Bob', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('posts')->insert([
        ['id' => 10, 'user_id' => 1, 'title' => 'Hello', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 11, 'user_id' => 2, 'title' => 'World', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $exporter = new SmartExporter(new SchemaIntrospector);
    $payload = $exporter->export();

    expect($payload['_meta']['version'])->toBe(2)
        ->and($payload['_data'])->toHaveKeys(['users', 'posts']);

    DB::table('posts')->delete();
    DB::table('users')->delete();

    $importer = new SmartImporter(new SchemaIntrospector, new TableSorter);
    $result = $importer->import($payload);

    expect($result->hasErrors())->toBeFalse()
        ->and($result->getTotalImported())->toBe(4);

    $importedPosts = DB::table('posts')->orderBy('title')->get();
    expect($importedPosts->count())->toBe(2);

    foreach ($importedPosts as $post) {
        expect(DB::table('users')->where('id', $post->user_id)->exists())->toBeTrue();
    }
});

it('skips duplicate rows when on_duplicate is skip', function () {
    DB::table('users')->insert([
        ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = (new SmartExporter(new SchemaIntrospector))->export();

    $importer = new SmartImporter(new SchemaIntrospector, new TableSorter);
    $result = $importer->import($payload, [], ['on_duplicate' => 'skip']);

    expect(DB::table('users')->count())->toBe(1)
        ->and($result->hasErrors())->toBeFalse();
});

it('updates existing rows when on_duplicate is update', function () {
    DB::table('users')->insert([
        ['id' => 1, 'email' => 'alice@example.com', 'name' => 'Old Name', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = (new SmartExporter(new SchemaIntrospector))->export();

    DB::table('users')->where('email', 'alice@example.com')->update(['name' => 'Stale Name']);

    $importer = new SmartImporter(new SchemaIntrospector, new TableSorter);
    $importer->import($payload, [], ['on_duplicate' => 'update']);

    expect(DB::table('users')->where('email', 'alice@example.com')->value('name'))->toBe('Old Name');
});

it('emits per-table progress callbacks', function () {
    DB::table('users')->insert([
        ['email' => 'a@x.com', 'name' => 'A', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = (new SmartExporter(new SchemaIntrospector))->export();
    DB::table('users')->delete();

    $progressTables = [];
    $importer = new SmartImporter(new SchemaIntrospector, new TableSorter);
    $importer->import(
        $payload,
        [],
        [],
        function (string $table, string $status) use (&$progressTables): void {
            $progressTables[$table] = $status;
        },
    );

    expect($progressTables)->toHaveKey('users')
        ->and(in_array('importing', array_values($progressTables), true) || in_array('success', array_values($progressTables), true))
        ->toBeTrue();
});

it('does not overwrite a destination row that merely shares an auto-increment id', function () {
    DB::table('users')->insert([
        ['id' => 1, 'email' => 'source@example.com', 'name' => 'Source User', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('posts')->insert([
        ['id' => 1, 'user_id' => 1, 'title' => 'Source post', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = (new SmartExporter(new SchemaIntrospector))->export();

    DB::table('posts')->delete();
    DB::table('users')->delete();

    DB::table('users')->insert([
        ['id' => 1, 'email' => 'local@example.com', 'name' => 'Local User', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('posts')->insert([
        ['id' => 1, 'user_id' => 1, 'title' => 'Unrelated local post', 'created_at' => now(), 'updated_at' => now()],
    ]);

    (new SmartImporter(new SchemaIntrospector, new TableSorter))
        ->import($payload, [], ['on_duplicate' => 'update']);

    expect(DB::table('posts')->where('id', 1)->value('title'))->toBe('Unrelated local post')
        ->and(DB::table('posts')->count())->toBe(2);
});

it('keeps a scoped import inside its own scope', function () {
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('projects', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('code');
        $table->string('title');
        $table->unique(['team_id', 'code']);
    });

    DB::table('teams')->insert([['id' => 1, 'name' => 'One'], ['id' => 2, 'name' => 'Two']]);
    DB::table('projects')->insert([
        ['id' => 1, 'team_id' => 2, 'code' => 'SHARED', 'title' => 'Other tenant project'],
    ]);

    $payload = [
        '_meta' => ['version' => 2],
        '_schema' => [],
        '_data' => [
            'projects' => [
                ['id' => 1, 'team_id' => 9, 'code' => 'SHARED', 'title' => 'Imported project'],
            ],
        ],
    ];

    (new SmartImporter(new SchemaIntrospector, new TableSorter))->import(
        $payload,
        [],
        ['scope' => ['column' => 'team_id', 'value' => 1], 'on_duplicate' => 'update', 'tables' => ['projects']],
    );

    expect(DB::table('projects')->where('code', 'SHARED')->where('team_id', 2)->value('title'))
        ->toBe('Other tenant project')
        ->and(DB::table('projects')->where('team_id', 1)->count())->toBe(1);

    Schema::dropIfExists('projects');
    Schema::dropIfExists('teams');
});

it('skips tables that the scope cannot filter unless they are declared global', function () {
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('key');
    });

    $payload = [
        '_meta' => ['version' => 2],
        '_schema' => [],
        '_data' => ['settings' => [['id' => 1, 'key' => 'locale']]],
    ];

    $result = (new SmartImporter(new SchemaIntrospector, new TableSorter))->import(
        $payload,
        [],
        ['scope' => ['column' => 'team_id', 'value' => 1], 'tables' => ['settings']],
    );

    expect(DB::table('settings')->count())->toBe(0)
        ->and($result->warnings)->not->toBeEmpty();

    config(['filament-system-tools.smart_migration.global_tables' => ['settings']]);

    (new SmartImporter(new SchemaIntrospector, new TableSorter))->import(
        $payload,
        [],
        ['scope' => ['column' => 'team_id', 'value' => 1], 'tables' => ['settings']],
    );

    expect(DB::table('settings')->count())->toBe(1);

    Schema::dropIfExists('settings');
});

it('excludes unscopable tables from a scoped export', function () {
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->string('key');
    });
    Schema::create('scoped_items', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('team_id');
        $table->string('label');
    });

    DB::table('settings')->insert(['key' => 'locale']);
    DB::table('scoped_items')->insert([
        ['team_id' => 1, 'label' => 'mine'],
        ['team_id' => 2, 'label' => 'theirs'],
    ]);

    $payload = (new SmartExporter(new SchemaIntrospector))->export(['column' => 'team_id', 'value' => 1]);

    expect($payload['_data'])->not->toHaveKey('settings')
        ->and($payload['_data']['scoped_items'])->toHaveCount(1)
        ->and($payload['_meta']['excluded_unscoped_tables'])->toContain('settings');

    Schema::dropIfExists('scoped_items');
    Schema::dropIfExists('settings');
});

it('rolls back and reports nothing imported when a required reference cannot be resolved', function () {
    $payload = [
        '_meta' => ['version' => 2],
        '_schema' => [],
        '_data' => [
            'posts' => [
                ['id' => 5, 'user_id' => 99, 'title' => 'Orphan', 'created_at' => now(), 'updated_at' => now()],
            ],
        ],
    ];

    // The failure is logged; keep it out of the shared testbench log file.
    Log::spy();

    $result = (new SmartImporter(new SchemaIntrospector, new TableSorter))
        ->import($payload, [], ['tables' => ['posts']]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->getTotalImported())->toBe(0)
        ->and(DB::table('posts')->count())->toBe(0);
});
