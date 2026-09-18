<?php

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Livewire\DatabaseServer\BackupForm;
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
