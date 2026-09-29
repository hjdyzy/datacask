<?php

namespace App\Services\Backup;

use App\Enums\BackupJobStatus;
use App\Jobs\ProcessBackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TriggerOneOffBackupAction
{
    public function __construct(
        private BackupJobFactory $factory,
        private OneOffBackupDatabases $databases,
    ) {}

    /**
     * @param  list<string>  $databases
     * @return list<Snapshot>
     */
    public function execute(DatabaseServer $server, string $backupId, array $databases, User $user, string $comment = '', bool $locked = false): array
    {
        $server = DatabaseServer::findOrFail($server->id);
        $this->authorize($server, $user, $locked);
        $this->validateSelection($server, $databases, $comment);

        $snapshots = DB::transaction(function () use ($server, $backupId, $databases, $user, $comment, $locked) {
            $currentServer = DatabaseServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorize($currentServer, $user, $locked);
            $backup = $currentServer->backups()->with('volumes')->findOrFail($backupId);
            if ($backup->volumes->isEmpty()) {
                throw ValidationException::withMessages(['backupId' => __('This backup configuration has no storage destinations.')]);
            }
            $this->ensureNoActiveBackup($currentServer, $databases);

            return array_map(function (string $name) use ($backup, $user, $comment, $locked): Snapshot {
                $snapshot = $this->factory->createSnapshot($backup, $name, 'manual', $user->id);
                $snapshot->update([
                    'comment' => trim($comment) !== '' ? trim($comment) : null,
                    'locked' => $locked,
                    'metadata' => array_merge($snapshot->metadata ?? [], ['one_off' => true]),
                ]);
                $snapshot->job->log('One-off backup requested.', 'info', [
                    'triggered_by_user_id' => $user->id,
                    'database_name' => $name,
                    'backup_id' => $backup->id,
                    'locked' => $locked,
                ]);

                return $snapshot;
            }, $databases);
        });

        $this->dispatch($snapshots);

        return $snapshots;
    }

    private function authorize(DatabaseServer $server, User $user, bool $locked): void
    {
        Gate::forUser($user)->authorize('backup', $server);
        abort_if($user->isDemo(), 403);
        if ($locked) {
            Gate::forUser($user)->authorize('lock', new Snapshot);
        }
    }

    /** @param list<string> $databases */
    private function validateSelection(DatabaseServer $server, array $databases, string $comment): void
    {
        Validator::make(compact('databases', 'comment'), [
            'databases' => ['required', 'array', 'list', 'min:1'],
            'databases.*' => ['required', 'string', 'distinct:strict', 'max:255'],
            'comment' => ['string', 'max:1000'],
        ])->validate();

        $available = $this->databases->forServer($server);
        foreach ($databases as $index => $name) {
            if (! in_array($name, $available, true)) {
                throw ValidationException::withMessages([
                    "databases.{$index}" => __('A selected database is unavailable. Refresh the list and select again.'),
                ]);
            }
        }
    }

    /** @param list<string> $databases */
    public function ensureNoActiveBackup(DatabaseServer $server, array $databases): void
    {
        $busy = Snapshot::query()->where('database_server_id', $server->id)
            ->whereIn('database_name', $databases)
            ->whereHas('job', fn ($query) => $query->whereIn('status', [BackupJobStatus::Pending, BackupJobStatus::Running]))
            ->pluck('database_name')->unique()->values()->all();

        if ($busy !== []) {
            throw ValidationException::withMessages([
                'databases' => __('Backups are already pending or running for: :databases', ['databases' => implode(', ', $busy)]),
            ]);
        }
    }

    /** @param list<Snapshot> $snapshots */
    private function dispatch(array $snapshots): void
    {
        $failed = [];
        foreach ($snapshots as $snapshot) {
            try {
                ProcessBackupJob::dispatch($snapshot->id);
            } catch (\Throwable $exception) {
                $snapshot->job->log('Could not queue one-off backup.', 'error', ['error' => $exception->getMessage()]);
                $snapshot->job->markFailed($exception);
                report($exception);
                $failed[] = $snapshot->database_name;
            }
        }

        if ($failed !== []) {
            throw ValidationException::withMessages([
                'databases' => __('Could not queue backups for: :databases. Check the failed snapshot logs before retrying.', [
                    'databases' => implode(', ', $failed),
                ]),
            ]);
        }
    }
}
