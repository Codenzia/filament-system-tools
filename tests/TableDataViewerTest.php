<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Livewire\TableDataViewer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

afterEach(function () {
    Schema::dropIfExists('widgets');
    Schema::dropIfExists('members');
});

it('renders a table with a single-column key', function () {
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    DB::table('widgets')->insert([
        ['name' => 'first'],
        ['name' => 'second'],
    ]);

    Livewire::test(TableDataViewer::class, ['tableName' => 'widgets'])
        ->assertOk()
        ->assertSee('first')
        ->assertSee('second');
});

it('renders a table with a composite key', function () {
    Schema::create('members', function (Blueprint $table) {
        $table->unsignedBigInteger('team_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role');
        $table->primary(['team_id', 'user_id']);
    });

    DB::table('members')->insert([
        ['team_id' => 1, 'user_id' => 1, 'role' => 'owner'],
        ['team_id' => 1, 'user_id' => 2, 'role' => 'member'],
    ]);

    Livewire::test(TableDataViewer::class, ['tableName' => 'members'])
        ->assertOk()
        ->assertSee('owner')
        ->assertSee('member');
});
