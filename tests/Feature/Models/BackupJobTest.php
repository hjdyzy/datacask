<?php

use App\Models\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('markFailed finalizes a command log left dangling at running status', function () {
    $job = BackupJob::create(['status' => 'running']);

    $index = $job->startCommandLog('pg_dump ...');
    expect($job->getLogs()[$index]['status'])->toBe('running');

    $job->markFailed(new Exception('worker was killed mid-command'));

    $job->refresh();

    expect($job->status->value)->toBe('failed')
        ->and($job->getLogs()[$index]['status'])->toBe('failed');
});

test('markFailed leaves already-finalized command logs untouched', function () {
    $job = BackupJob::create(['status' => 'running']);

    $index = $job->startCommandLog('mysqldump ...');
    $job->updateCommandLog($index, ['status' => 'completed', 'exit_code' => 0]);

    $job->markFailed(new Exception('a later step failed'));

    $job->refresh();

    expect($job->getLogs()[$index]['status'])->toBe('completed');
});

test('markRunning clears stale terminal metadata', function () {
    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(30),
        'duration_ms' => 1800000,
        'error_message' => 'previous attempt failed',
        'error_trace' => 'previous trace',
    ]);

    expect($job->markRunning('queue-123'))->toBeTrue();

    $job->refresh();
    expect($job->status->value)->toBe('running')
        ->and($job->job_id)->toBe('queue-123')
        ->and($job->completed_at)->toBeNull()
        ->and($job->duration_ms)->toBeNull()
        ->and($job->error_message)->toBeNull()
        ->and($job->error_trace)->toBeNull();
});

test('markCompleted clears stale error metadata only while running', function () {
    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subMinute(),
        'error_message' => 'transient failure',
        'error_trace' => 'transient trace',
    ]);

    expect($job->markCompleted())->toBeTrue();

    $job->refresh();
    expect($job->status->value)->toBe('completed')
        ->and($job->error_message)->toBeNull()
        ->and($job->error_trace)->toBeNull()
        ->and($job->markCompleted())->toBeFalse();
});

test('claimForExecution refuses a terminal job', function () {
    $job = BackupJob::create([
        'status' => 'failed',
        'completed_at' => now(),
        'error_message' => 'queue timeout',
    ]);

    expect($job->claimForExecution('stale-queue-message'))->toBeFalse();

    $job->refresh();
    expect($job->status->value)->toBe('failed')
        ->and($job->job_id)->toBeNull()
        ->and($job->error_message)->toBe('queue timeout');
});
