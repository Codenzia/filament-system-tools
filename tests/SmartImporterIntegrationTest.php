<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaIntrospector;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartExporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SmartImporter;
use Codenzia\FilamentSystemTools\Services\SmartMigration\TableSorter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
