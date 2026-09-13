<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Models\DynamicTableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Schema::dropIfExists('members');
    Schema::dropIfExists('uuid_records');
    Schema::dropIfExists('keyless_log');
    Schema::dropIfExists('widgets');
});

it('uses every column of a composite primary key', function () {
    Schema::create('members', function (Blueprint $table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role');
        $table->primary(['team_id', 'user_id']);
    });

    expect(DynamicTableModel::keyColumnsFor('members'))->toBe(['team_id', 'user_id']);
});

it('uses the auto-increment key for a standard table', function () {
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    expect(DynamicTableModel::keyColumnsFor('widgets'))->toBe(['id']);
});

it('uses a uuid primary key', function () {
    Schema::create('uuid_records', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('label');
    });

    $model = DynamicTableModel::forTable('uuid_records');

    expect(DynamicTableModel::keyColumnsFor('uuid_records'))->toBe(['id'])
        ->and($model->getKeyName())->toBe('id')
        ->and($model->getIncrementing())->toBeFalse();
});

it('reports no key for a table without a primary or unique index', function () {
    Schema::create('keyless_log', function (Blueprint $table) {
        $table->string('channel');
        $table->text('message');
    });

    expect(DynamicTableModel::keyColumnsFor('keyless_log'))->toBe([]);
});
