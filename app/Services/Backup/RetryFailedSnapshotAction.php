<?php

namespace App\Services\Backup;

use App\Enums\BackupJobStatus;
use App\Enums\DatabaseSelectionMode;
use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\User;
use App\Services\Agent\AgentJobPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RetryFailedSnapshotAction
{
    public function __construct(
        private BackupJobFactory $factory,
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    public function execute(Snapshot $source, ?int $triggeredByUserId = null): Snapshot
    {
        $retry = DB::transaction(function () use ($source, $triggeredByUserId) {
            DatabaseServer::query()->lockForUpdate()->findOrFail($source->database_server_id);
            $backup = Backup::query()->lockForUpdate()->find($source->backup_id);
            $currentSource = $source->fresh();
            if (! $currentSource || ! $backup) {
                throw ValidationException::withMessages(['snapshot' => __('This failed backup cannot be retried.')]);
            }
            if (($currentSource->metadata['one_off'] ?? false) && $currentSource->locked && $triggeredByUserId !== null) {
                Gate::forUser(User::findOrFail($triggeredByUserId))->authorize('lock', $currentSource);
            }
            $this->validateSource($currentSource, $backup);

            $retry = $this->factory->createSnapshot(
                $backup, $currentSource->database_name, 'manual', $triggeredByUserId, $source->id,
            );

            if ($currentSource->metadata['one_off'] ?? false) {
                $retry->update([
                    'comment' => $currentSource->comment,
                    'locked' => $currentSource->locked,
                    'metadata' => array_merge($retry->metadata ?? [], ['one_off' => true]),
                ]);
            }

            if ($backup->databaseServer->agent_id) {
                AgentJob::create([
                    'type' => AgentJob::TYPE_BACKUP,
                    'database_server_id' => $backup->database_server_id,
                    'snapshot_id' => $retry->id,
                    'status' => AgentJob::STATUS_PENDING,
                    'payload' => $this->payloadBuilder->build($retry),
                ]);
            }

            return $retry;
        });

        if (! $retry->databaseServer->agent_id) {
            ProcessBackupJob::dispatch($retry->id);
        }

        return $retry;
    }

    private function validateSource(Snapshot $source, Backup $backup): void
    {
        if (! $backup->databaseServer->backups_enabled || $source->job->status !== BackupJobStatus::Failed
            || $source->database_name === ''
            || ($source->metadata['preflight_failure'] ?? false)
            || in_array($source->database_name, ['(all databases)', '(excluded databases)', '(preflight)'], true)) {
            throw ValidationException::withMessages(['snapshot' => __('This failed backup cannot be retried.')]);
        }

        if ($source->metadata['one_off'] ?? false) {
            try {
                $this->validateOneOffSource($source, $backup);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['snapshot' => $exception->getMessage()]);
            }

            return;
        }

        $mode = $backup->database_selection_mode;
        $name = $source->database_name;
        $pattern = (string) ($backup->database_include_pattern ?? '');
        if ($mode === DatabaseSelectionMode::Selected && ! in_array($name, $backup->database_names ?? [], true)) {
            throw ValidationException::withMessages(['snapshot' => __('This database is no longer selected for backup.')]);
        }

        if ($mode === DatabaseSelectionMode::Excluded && in_array($name, $backup->database_names ?? [], true)) {
            throw ValidationException::withMessages(['snapshot' => __('This database is excluded from backup.')]);
        }

        if ($mode === DatabaseSelectionMode::Pattern && ($name === $pattern
            || ! DatabaseServer::isValidDatabasePattern($pattern)
            || ! in_array($name, DatabaseServer::filterDatabasesByPattern([$name], $pattern), true))) {
            throw ValidationException::withMessages(['snapshot' => __('This database no longer matches the backup pattern.')]);
        }

        if (Snapshot::query()->where('backup_id', $backup->id)->where('database_name', $name)
            ->whereHas('job', fn ($query) => $query->whereIn('status', [BackupJobStatus::Pending, BackupJobStatus::Running]))->exists()) {
            throw ValidationException::withMessages(['snapshot' => __('A backup for this database is already pending or running.')]);
        }
    }

    private function validateOneOffSource(Snapshot $source, Backup $backup): void
    {
        $available = app(OneOffBackupDatabases::class)->forServer($backup->databaseServer);
        if (! in_array($source->database_name, $available, true)) {
            throw ValidationException::withMessages([
                'snapshot' => __('A selected database is unavailable. Refresh the list and select again.'),
            ]);
        }

        app(TriggerOneOffBackupAction::class)->ensureNoActiveBackup($backup->databaseServer, [$source->database_name]);
    }
}
