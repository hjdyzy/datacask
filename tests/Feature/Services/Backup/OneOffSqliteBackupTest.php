<?php

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Facades\AppConfig;
use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Backup\BackupTask;
use App\Services\Backup\TriggerOneOffBackupAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('one-off selection produces a real SQLite archive through the existing worker', function () {
    Queue::fake();
    AppConfig::set('backup.compression', 'gzip');
    $directory = sys_get_temp_dir().'/sqlite-db-test-'.Str::random(12);
    File::makeDirectory($directory);
    $paths = [$directory.'/first.sqlite', $directory.'/selected.sqlite'];
    foreach ($paths as $path) {
        $database = new PDO('sqlite:'.$path);
        $database->exec('CREATE TABLE markers (value TEXT)');
        $database->prepare('INSERT INTO markers (value) VALUES (?)')->execute([basename($path)]);
    }
    $database = null;
    $server = DatabaseServer::factory()->withoutBackups()->create(['database_type' => 'sqlite']);
    $backup = Backup::factory()->for($server)->selected($paths)->create();
    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $snapshot = app(TriggerOneOffBackupAction::class)->execute($server, $backup->id, [$paths[1]], $user)[0];

    (new ProcessBackupJob($snapshot->id))->handle(app(BackupTask::class));

    $snapshot->refresh();
    expect(Snapshot::count())->toBe(1)
        ->and($snapshot->job->status)->toBe(BackupJobStatus::Completed)
        ->and($snapshot->database_name)->toBe($paths[1]);
    $archive = $backup->volumes->first()->config['path'].'/'.$snapshot->filename;
    expect(is_file($archive))->toBeTrue();
    File::put($directory.'/restored.sqlite', gzdecode(File::get($archive)));
    $restored = new PDO('sqlite:'.$directory.'/restored.sqlite');
    expect($restored->query('SELECT value FROM markers')->fetchColumn())->toBe('selected.sqlite')
        ->and($backup->fresh()->database_names)->toBe($paths);
});
