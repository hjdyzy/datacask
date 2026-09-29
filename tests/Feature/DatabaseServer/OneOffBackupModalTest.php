<?php

use App\Enums\Ability;
use App\Livewire\DatabaseServer\OneOffBackupModal;
use App\Livewire\Snapshot\Index as SnapshotIndex;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\TriggerOneOffBackupAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $this->server = DatabaseServer::factory()->withoutBackups()->create();
    $this->backup = Backup::factory()->for($this->server)->excluded(['project_b'])->create();
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andReturn(['project_a', 'project_b']);
});

test('modal defaults one configuration and supports searching without losing the selection', function () {
    Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->dispatch('open-one-off-backup', serverId: $this->server->id)
        ->assertSet('showModal', true)->assertSet('backupId', $this->backup->id)
        ->assertSet('locked', false)->assertSee('project_b')
        ->set('databases', ['project_b'])->set('search', 'project_a')
        ->assertSet('databases', ['project_b'])
        ->set('comment', 'Before deployment')->call('submit')
        ->assertHasNoErrors()->assertSet('showModal', false);
    expect(Snapshot::sole()->database_name)->toBe('project_b')
        ->and(Snapshot::sole()->comment)->toBe('Before deployment');
});

test('multiple configurations require an explicit choice', function () {
    Backup::factory()->for($this->server)->create();
    Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->call('openModal', $this->server->id)->assertSet('backupId', '')
        ->set('databases', ['project_b'])->call('submit')->assertHasErrors('backupId');
    expect(Snapshot::count())->toBe(0);
});

test('viewer cannot open the modal or submit directly', function () {
    $viewer = User::factory()->withAbilities([])->create();
    Livewire::actingAs($viewer)->test(OneOffBackupModal::class)
        ->call('openModal', $this->server->id)->assertForbidden();
});

test('forged protect flag does not bypass snapshot lock permissions', function () {
    Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->call('openModal', $this->server->id)
        ->assertDontSee('Protect this backup from cleanup')
        ->set('databases', ['project_b'])->set('locked', true)->call('submit')->assertForbidden();
    expect(Snapshot::count())->toBe(0);
});

test('submitting a removed database requires refreshing the selection', function () {
    $modal = Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->call('openModal', $this->server->id)->set('databases', ['project_b']);
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andReturn(['project_a']);
    $modal->call('submit')->assertHasErrors('databases.0')
        ->call('refreshDatabases')->assertSet('databases', []);
    expect(Snapshot::count())->toBe(0);
});

test('database discovery failure remains visible and creates no tasks', function () {
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andThrow(new RuntimeException('Connection refused'));
    Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->call('openModal', $this->server->id)->assertHasErrors('databases')
        ->assertSet('showModal', true)->assertSet('availableDatabases', [])
        ->assertSee('Cannot load databases.');
    expect(Snapshot::count())->toBe(0);
});

test('cross organization requests cannot list databases', function () {
    [, $server] = foreignServer();
    expect(fn () => Livewire::actingAs($this->user)->test(OneOffBackupModal::class)
        ->call('openModal', $server->id))->toThrow(ModelNotFoundException::class);
});

test('retry errors use the existing snapshot error channel', function () {
    $source = app(TriggerOneOffBackupAction::class)->execute(
        $this->server, $this->backup->id, ['project_b'], $this->user,
    )[0];
    $source->job->markFailed(new RuntimeException('Dump failed'));
    $this->mock(DatabaseProvider::class)->shouldReceive('listDatabasesForServer')->andReturn([]);

    Livewire::actingAs($this->user)->test(SnapshotIndex::class)
        ->call('retryFailedBackup', $source->id)->assertSuccessful();
    expect(Snapshot::count())->toBe(1);
});
