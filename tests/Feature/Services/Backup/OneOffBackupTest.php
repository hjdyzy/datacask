<?php

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Enums\DatabaseType;
use App\Jobs\ProcessBackupJob;
use App\Models\Agent;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\OneOffBackupDatabases;
use App\Services\Backup\RetryFailedSnapshotAction;
use App\Services\Backup\TriggerOneOffBackupAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->withAbilities([Ability::RunBackups->value, Ability::DeleteSnapshots->value])->create();
    $this->server = DatabaseServer::factory()->withoutBackups()->create();
    $this->backup = Backup::factory()->for($this->server)->excluded(['project_b'])->create();
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')
        ->withArgs(fn (DatabaseServer $server, bool $includeSystem = false) => $server->id === $this->server->id && ! $includeSystem)
        ->andReturn(['project_a', 'project_b', 'project_c']);
});

test('one-off selection keeps the schedule unchanged and freezes the requested targets', function () {
    $before = $this->backup->fresh()->getAttributes();
    $snapshots = app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_b', 'project_c'], $this->user, 'Before deployment', true,
    );

    expect(array_column($snapshots, 'database_name'))->toBe(['project_b', 'project_c']);
    expect($this->backup->fresh()->getAttributes())->toBe($before);
    foreach ($snapshots as $snapshot) {
        expect($snapshot->backup_id)->toBe($this->backup->id)
            ->and($snapshot->triggered_by_user_id)->toBe($this->user->id)
            ->and($snapshot->comment)->toBe('Before deployment')
            ->and($snapshot->locked)->toBeTrue()
            ->and($snapshot->metadata['one_off'])->toBeTrue()
            ->and($snapshot->method)->toBe('manual')
            ->and($snapshot->job->status)->toBe(BackupJobStatus::Pending)
            ->and($snapshot->files->pluck('volume_id')->all())->toBe($this->backup->volumes->pluck('id')->all());
    }
    Queue::assertPushed(ProcessBackupJob::class, 2);
});

test('one-off backups are not protected by default', function () {
    $snapshots = app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $this->user);
    expect($snapshots[0]->locked)->toBeFalse()->and($snapshots[0]->comment)->toBeNull();
});

test('invalid or unavailable database selections never create any tasks', function (array $names) {
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, $names, $this->user))
        ->toThrow(ValidationException::class);
    expect(Snapshot::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'empty' => [[]],
    'unknown mixed with valid' => [['project_a', 'removed']],
    'system database' => [['mysql']],
    'duplicate' => [['project_b', 'project_b']],
    'injection' => [['project_b; rm -rf /']],
]);

test('a busy database on another configuration rejects the entire selection', function (BackupJobStatus $status) {
    $other = Backup::factory()->for($this->server)->selected(['project_b'])->create();
    $active = app(BackupJobFactory::class)->createSnapshot($other, 'project_b', 'scheduled');
    $active->job->update(['status' => $status]);

    expect(fn () => app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_a', 'project_b'], $this->user,
    ))->toThrow(ValidationException::class, 'project_b');
    expect(Snapshot::count())->toBe(1);
    Queue::assertNothingPushed();
})->with([BackupJobStatus::Pending, BackupJobStatus::Running]);

test('repeated submission is rejected while the first job is pending', function () {
    $action = app(TriggerOneOffBackupAction::class);
    $action->execute($this->server, $this->backup->id, ['project_b'], $this->user);
    expect(fn () => $action->execute($this->server, $this->backup->id, ['project_b'], $this->user))
        ->toThrow(ValidationException::class);
    expect(Snapshot::count())->toBe(1);
    Queue::assertPushed(ProcessBackupJob::class, 1);
});

test('completed and failed jobs do not block a new one-off backup', function (BackupJobStatus $status) {
    $old = app(BackupJobFactory::class)->createSnapshot($this->backup, 'project_b', 'manual');
    $old->job->update(['status' => $status]);
    app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $this->user);
    expect(Snapshot::count())->toBe(2);
})->with([BackupJobStatus::Completed, BackupJobStatus::Failed]);

test('viewer cannot submit even when bypassing the interface', function () {
    $viewer = User::factory()->withAbilities([])->create();
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $viewer))
        ->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
});

test('run-backups alone does not grant snapshot protection', function () {
    $operator = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $operator, '', true))
        ->toThrow(AuthorizationException::class);
    expect(Snapshot::count())->toBe(0);
});

test('disabled servers cannot receive one-off backups', function () {
    $this->server->update(['backups_enabled' => false]);
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $this->user))
        ->toThrow(AuthorizationException::class);
});

test('a configuration from another instance cannot be used', function () {
    $other = Backup::factory()->create();
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $other->id, ['project_b'], $this->user))
        ->toThrow(ModelNotFoundException::class);
    expect(Snapshot::count())->toBe(0);
});

test('cross organization server ids are rejected', function () {
    [, $foreign] = foreignServer();
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($foreign, $this->backup->id, ['project_b'], $this->user))
        ->toThrow(ModelNotFoundException::class);
});

test('missing storage rolls back every snapshot', function () {
    $this->backup->volumes()->detach();
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $this->user))
        ->toThrow(ValidationException::class);
    expect(Snapshot::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('path databases select only configured paths', function (DatabaseType $type) {
    $this->server->update(['database_type' => $type]);
    $this->backup->update(['database_names' => ['/data/app.db', '/data/other.db'], 'database_selection_mode' => 'selected']);
    $names = app(OneOffBackupDatabases::class)->forServer($this->server->fresh());
    expect($names)->toBe(['/data/app.db', '/data/other.db']);
    $snapshots = app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['/data/other.db'], $this->user);
    expect($snapshots[0]->database_name)->toBe('/data/other.db');
})->with([DatabaseType::SQLITE, DatabaseType::FIREBIRD]);

test('redis cannot pretend to back up a single logical database', function () {
    $this->server->update(['database_type' => DatabaseType::REDIS]);
    expect(fn () => app(OneOffBackupDatabases::class)->forServer($this->server))
        ->toThrow(ValidationException::class, 'whole instance');
});

test('agent discovery does not accidentally dispatch a whole scheduled backup', function () {
    $this->server->update(['agent_id' => Agent::factory()->create()->id]);
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['project_b'], $this->user))
        ->toThrow(ValidationException::class, 'agent-backed');
    expect(Snapshot::count())->toBe(0);
    $this->assertDatabaseCount('agent_jobs', 0);
});

test('failed one-off backup retries the original excluded database and keeps its annotations', function () {
    $source = app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_b'], $this->user, 'Before upgrade', true,
    )[0];
    $source->job->markFailed(new RuntimeException('Dump failed'));
    $retry = app(RetryFailedSnapshotAction::class)->execute($source, $this->user->id);
    expect($retry->database_name)->toBe('project_b')
        ->and($retry->metadata['one_off'])->toBeTrue()
        ->and($retry->comment)->toBe('Before upgrade')
        ->and($retry->locked)->toBeTrue()
        ->and($retry->retry_of_snapshot_id)->toBe($source->id)
        ->and($source->fresh()->job->status)->toBe(BackupJobStatus::Failed);
    expect(fn () => app(RetryFailedSnapshotAction::class)->execute($source, $this->user->id))
        ->toThrow(ValidationException::class);
});

test('ordinary failed backups still honor the exclusion policy', function () {
    $source = app(BackupJobFactory::class)->createSnapshot($this->backup, 'project_b', 'manual');
    $source->job->markFailed(new RuntimeException('Dump failed'));
    expect(fn () => app(RetryFailedSnapshotAction::class)->execute($source, $this->user->id))
        ->toThrow(ValidationException::class, 'excluded');
});

test('queue failures produce failed snapshots rather than stranded pending tasks', function () {
    Bus::shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('Queue unavailable'));
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_a', 'project_b'], $this->user,
    ))->toThrow(ValidationException::class, 'Could not queue');
    expect(Snapshot::count())->toBe(2);
    foreach (Snapshot::with('job')->get() as $snapshot) {
        expect($snapshot->job->status)->toBe(BackupJobStatus::Failed)
            ->and($snapshot->job->error_message)->toBe('Queue unavailable');
    }
});

test('protection permissions also apply when retrying a locked one-off backup', function () {
    $source = app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_b'], $this->user, '', true,
    )[0];
    $source->job->markFailed(new RuntimeException('Dump failed'));
    $operator = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    expect($operator->can('retry', $source))->toBeFalse();
    expect(fn () => app(RetryFailedSnapshotAction::class)->execute($source, $operator->id))
        ->toThrow(AuthorizationException::class);
    expect(Snapshot::count())->toBe(1);
});

test('discovery timeout is transient and does not update connection settings', function () {
    $before = $this->server->fresh()->getAttributes();
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->once()
        ->withArgs(fn (DatabaseServer $server) => $server->extra_config['connect_timeout'] === 5)
        ->andReturn(['project_b']);
    app(OneOffBackupDatabases::class)->forServer($this->server);
    expect($this->server->fresh()->getAttributes())->toBe($before);
});

test('numeric database names are compared exactly rather than coerced', function () {
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andReturn(['814']);
    expect(fn () => app(TriggerOneOffBackupAction::class)->execute($this->server, $this->backup->id, ['0814'], $this->user))
        ->toThrow(ValidationException::class);
    expect(Snapshot::count())->toBe(0);
});
