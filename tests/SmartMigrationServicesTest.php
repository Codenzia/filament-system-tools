<?php

declare(strict_types=1);

use Codenzia\FilamentSystemTools\Services\SmartMigration\IdRemapper;
use Codenzia\FilamentSystemTools\Services\SmartMigration\ImportResult;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaDiffer;
use Codenzia\FilamentSystemTools\Services\SmartMigration\SchemaDiffResult;
use Codenzia\FilamentSystemTools\Services\SmartMigration\TableSorter;

/* ──────────────────────────────────────────────────────────────────────────
 | IdRemapper
 ────────────────────────────────────────────────────────────────────────── */

it('records and resolves id mappings', function () {
    $remapper = new IdRemapper;
    $remapper->record('users', 1, 42);

    expect($remapper->resolve('users', 1))->toBe(42)
        ->and($remapper->resolve('users', 99))->toBeNull()
        ->and($remapper->resolve('orders', 1))->toBeNull()
        ->and($remapper->has('users', 1))->toBeTrue()
        ->and($remapper->has('users', 99))->toBeFalse();
});

it('counts total mappings and resets', function () {
    $remapper = new IdRemapper;
    $remapper->record('users', 1, 10);
    $remapper->record('users', 2, 20);
    $remapper->record('orders', 1, 100);

    expect($remapper->getTotalMappings())->toBe(3)
        ->and($remapper->getMappingsForTable('users'))->toBe([1 => 10, 2 => 20]);

    $remapper->reset();
    expect($remapper->getTotalMappings())->toBe(0)
        ->and($remapper->resolve('users', 1))->toBeNull();
});

/* ──────────────────────────────────────────────────────────────────────────
 | TableSorter
 ────────────────────────────────────────────────────────────────────────── */

it('sorts tables in foreign-key dependency order', function () {
    $schema = [
        'posts' => [
            'columns' => ['id' => [], 'user_id' => []],
            'foreign_keys' => ['user_id' => ['references' => 'id', 'on' => 'users']],
        ],
        'comments' => [
            'columns' => ['id' => [], 'post_id' => []],
            'foreign_keys' => ['post_id' => ['references' => 'id', 'on' => 'posts']],
        ],
        'users' => [
            'columns' => ['id' => []],
            'foreign_keys' => [],
        ],
    ];

    $sorted = (new TableSorter)->sort($schema);

    expect(array_search('users', $sorted, true))
        ->toBeLessThan(array_search('posts', $sorted, true))
        ->and(array_search('posts', $sorted, true))
        ->toBeLessThan(array_search('comments', $sorted, true));
});

it('detects self-referencing columns', function () {
    $foreignKeys = [
        'parent_id' => ['references' => 'id', 'on' => 'categories'],
        'creator_id' => ['references' => 'id', 'on' => 'users'],
    ];

    $selfRefs = (new TableSorter)->getSelfReferences('categories', $foreignKeys);

    expect($selfRefs)->toBe(['parent_id']);
});

it('handles cycles by preferring nullable cycle FKs', function () {
    $schema = [
        'a' => [
            'columns' => ['id' => [], 'b_id' => ['nullable' => true]],
            'foreign_keys' => ['b_id' => ['references' => 'id', 'on' => 'b']],
        ],
        'b' => [
            'columns' => ['id' => [], 'a_id' => ['nullable' => false]],
            'foreign_keys' => ['a_id' => ['references' => 'id', 'on' => 'a']],
        ],
    ];

    $sorted = (new TableSorter)->sort($schema);

    expect($sorted)->toContain('a')->toContain('b')
        ->and(array_search('a', $sorted, true))
        ->toBeLessThan(array_search('b', $sorted, true));
});

/* ──────────────────────────────────────────────────────────────────────────
 | SchemaDiffer
 ────────────────────────────────────────────────────────────────────────── */

it('flags identical schemas as full match', function () {
    $schema = [
        'users' => [
            'columns' => [
                'id' => ['type' => 'integer', 'nullable' => false, 'default' => null],
                'name' => ['type' => 'string', 'nullable' => false, 'default' => null],
            ],
            'foreign_keys' => [],
        ],
    ];

    $result = (new SchemaDiffer)->diff($schema, $schema);

    expect($result)->toBeInstanceOf(SchemaDiffResult::class)
        ->and($result->getTableStatus('users'))->toBe('match')
        ->and($result->getSummary())->toMatchArray([
            'total_tables' => 1,
            'matched' => 1,
            'partial' => 0,
            'skipped' => 0,
        ]);
});

it('suggests column renames using levenshtein distance', function () {
    $exported = [
        'users' => [
            'columns' => [
                'id' => ['type' => 'integer', 'nullable' => false, 'default' => null],
                'fullname' => ['type' => 'string', 'nullable' => false, 'default' => null],
            ],
            'foreign_keys' => [],
        ],
    ];
    $current = [
        'users' => [
            'columns' => [
                'id' => ['type' => 'integer', 'nullable' => false, 'default' => null],
                'full_name' => ['type' => 'string', 'nullable' => false, 'default' => null],
            ],
            'foreign_keys' => [],
        ],
    ];

    $result = (new SchemaDiffer)->diff($exported, $current);

    expect($result->tables['users']['suggested_renames'])->toBe(['fullname' => 'full_name'])
        ->and($result->getTableStatus('users'))->toBe('partial');
});

it('flags tables only in export as skipped', function () {
    $exported = [
        'users' => ['columns' => [], 'foreign_keys' => []],
        'legacy_table' => ['columns' => [], 'foreign_keys' => []],
    ];
    $current = [
        'users' => ['columns' => [], 'foreign_keys' => []],
    ];

    $result = (new SchemaDiffer)->diff($exported, $current);

    expect($result->skippedTables)->toBe(['legacy_table'])
        ->and($result->getTableStatus('legacy_table'))->toBe('skipped');
});

it('flags integer type variants as compatible', function () {
    $exported = [
        'users' => [
            'columns' => [
                'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null],
            ],
            'foreign_keys' => [],
        ],
    ];
    $current = [
        'users' => [
            'columns' => [
                'id' => ['type' => 'integer', 'nullable' => false, 'default' => null],
            ],
            'foreign_keys' => [],
        ],
    ];

    $result = (new SchemaDiffer)->diff($exported, $current);

    expect($result->tables['users']['type_mismatches'])->toBe([]);
});

/* ──────────────────────────────────────────────────────────────────────────
 | ImportResult
 ────────────────────────────────────────────────────────────────────────── */

it('summarises import results', function () {
    $result = new ImportResult(
        importedCounts: ['users' => 5, 'posts' => 3, 'tags' => 0],
        skippedCounts: ['users' => 1, 'posts' => 0, 'tags' => 0],
        errors: ['failed once'],
        warnings: ['warning'],
    );

    expect($result->getTotalImported())->toBe(8)
        ->and($result->getTotalSkipped())->toBe(1)
        ->and($result->getTablesImported())->toBe(2)
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->hasWarnings())->toBeTrue()
        ->and($result->getSummary())->toBe([
            'total_imported' => 8,
            'total_skipped' => 1,
            'tables_imported' => 2,
            'errors' => 1,
            'warnings' => 1,
        ]);
});
