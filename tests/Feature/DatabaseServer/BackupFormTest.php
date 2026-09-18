<?php

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Livewire\DatabaseServer\BackupForm;
use App\Models\Backup;
use Illuminate\Validation\ValidationException;

test('validatePatternMode throws when the include pattern is not a valid regex', function () {
    expect(fn () => BackupForm::validatePatternMode(0, [
        'database_selection_mode' => DatabaseSelectionMode::Pattern->value,
        'database_include_pattern' => '(unclosed',
    ]))->toThrow(ValidationException::class);
});

test('excluded mode requires at least one excluded database name', function () {
    $rules = BackupForm::rulesFor(0, [
        'database_selection_mode' => DatabaseSelectionMode::Excluded->value,
        'database_names' => [],
        'retention_policy' => \App\Models\Backup::RETENTION_DAYS,
        'retention_days' => 7,
    ], DatabaseType::MYSQL, false);

    expect($rules['backups.0.database_names'])->toBe('required|array|min:1');
});

test('excluded mode keeps the exclusion list through selection normalisation', function () {
    $entry = [
        'database_selection_mode' => DatabaseSelectionMode::Excluded->value,
        'database_names' => ['legacy_db'],
        'database_include_pattern' => null,
    ];

    BackupForm::normalizeSelection($entry, DatabaseType::MYSQL);

    expect($entry['database_names'])->toBe(['legacy_db']);
});

test('switching back to all databases drops the stored exclusion list', function () {
    $entry = [
        'database_selection_mode' => DatabaseSelectionMode::All->value,
        'database_names' => ['legacy_db'],
        'database_include_pattern' => null,
    ];

    BackupForm::normalizeSelection($entry, DatabaseType::MYSQL);

    expect($entry['database_names'])->toBeNull();
});

test('excluded mode summary reports the exclusion count', function () {
    $summary = BackupForm::selectionSummary([
        'database_selection_mode' => DatabaseSelectionMode::Excluded->value,
        'database_names' => ['legacy_db', 'staging_db'],
    ], DatabaseType::MYSQL);

    expect($summary)->toBe('all databases except 2 excluded');
});

test('excluded mode filters system databases when loading a saved configuration', function () {
    $backup = Backup::factory()->excluded(['legacy_db', 'mysql', 'sys'])->create();

    $entry = BackupForm::fromModel($backup->load('volumes'));

    expect($entry['database_names'])->toBe(['legacy_db'])
        ->and($entry['database_names_input'])->toBe('legacy_db');
});

test('excluded mode filters system databases while preserving user exclusions', function () {
    $entry = [
        'database_selection_mode' => DatabaseSelectionMode::Excluded->value,
        'database_names' => ['legacy_db', 'mysql', 'analytics_db', 'sys'],
        'database_include_pattern' => null,
    ];

    BackupForm::normalizeSelection($entry, DatabaseType::MYSQL);

    expect($entry['database_names'])->toBe(['legacy_db', 'analytics_db']);
});

test('migration removes system databases from historical exclusion lists', function () {
    $backup = Backup::factory()->excluded(['legacy_db', 'mysql', 'analytics_db', 'sys'])->create();

    $migration = require database_path('migrations/2026_09_18_000001_remove_system_databases_from_excluded_backup_lists.php');
    $migration->up();

    $backup->refresh();

    expect($backup->database_names)->toBe(['legacy_db', 'analytics_db']);
});
