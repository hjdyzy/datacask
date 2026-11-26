<?php

use App\Actions\Backup\TriggerBackupAction;
use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
});

test('excluded mode backs up every database but the listed names', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->excluded(['legacy_db', 'staging_db'])->create();

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')
            ->once()
            ->withArgs(function (DatabaseServer $server, ...$arguments): bool {
                return $arguments === [];
            })
            ->andReturn(['app_db', 'legacy_db', 'staging_db', 'analytics_db']);
    });

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toHaveCount(2)
        ->and(collect($result->snapshots)->pluck('database_name')->all())
        ->toBe(['app_db', 'analytics_db']);

    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('excluded mode matches names exactly and case-sensitively', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->excluded(['Legacy_DB'])->create();

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')
            ->andReturn(['Legacy_DB', 'legacy_db', 'legacy_db_archive']);
    });

    $result = TriggerBackupAction::run($backup);

    expect(collect($result->snapshots)->pluck('database_name')->all())
        ->toBe(['legacy_db', 'legacy_db_archive']);
});

test('excluding every discovered database records a failure instead of a silent no-op', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->excluded(['only_db'])->create();

    $this->mock(DatabaseProvider::class, function ($mock) {
        $mock->shouldReceive('listDatabasesForServer')->andReturn(['only_db']);
    });

    expect(fn () => TriggerBackupAction::run($backup))
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();

    $snapshot = $backup->snapshots()->sole();
    expect($snapshot->job->status)->toBe(BackupJobStatus::Failed)
        ->and($snapshot->database_name)->toBe('(excluded databases)')
        ->and($snapshot->metadata['preflight_failure'])->toBeTrue();
});

test('agent server with excluded mode dispatches a discovery job carrying the exclusion list', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => $agent->id]);
    $backup = Backup::factory()->for($server)->excluded(['legacy_db'])->create();

    $result = TriggerBackupAction::run($backup);

    expect($result->snapshots)->toBeEmpty();

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->type)->toBe(AgentJobType::Discover)
        ->and($discoveryJob->payload['selection_mode'])->toBe('excluded')
        ->and($discoveryJob->payload['excluded_databases'])->toBe(['legacy_db']);
});

test('other selection modes keep an empty exclusion list in the discovery payload', function () {
    $agent = Agent::factory()->create();
    $server = DatabaseServer::factory()->withoutBackups()->create(['agent_id' => $agent->id]);
    $backup = Backup::factory()->for($server)->pattern('^prod_')->create();

    TriggerBackupAction::run($backup);

    $discoveryJob = AgentJob::where('database_server_id', $server->id)->sole();
    expect($discoveryJob->payload['excluded_databases'])->toBe([]);
});
