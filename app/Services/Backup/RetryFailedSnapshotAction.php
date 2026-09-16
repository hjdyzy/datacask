<?php

namespace App\Services\Backup;

use App\Enums\BackupJobStatus;
use App\Enums\DatabaseSelectionMode;
use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;
use Illuminate\Support\Facades\DB;
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
            $backup = Backup::query()->lockForUpdate()->find($source->backup_id);
            $currentSource = $source->fresh();
            if (! $currentSource || ! $backup) {
                throw ValidationException::withMessages(['snapshot' => __('This failed backup cannot be retried.')]);
            }
            $this->validateSource($currentSource, $backup);

            $retry = $this->factory->createSnapshot(
                $backup, $currentSource->database_name, 'manual', $triggeredByUserId, $source->id,
            );

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
            || in_array($source->database_name, ['(all databases)', '(preflight)'], true)) {
            throw ValidationException::withMessages(['snapshot' => __('This failed backup cannot be retried.')]);
        }

        $mode = $backup->database_selection_mode;
        $name = $source->database_name;
        $pattern = (string) ($backup->database_include_pattern ?? '');
        if ($mode === DatabaseSelectionMode::Selected && ! in_array($name, $backup->database_names ?? [], true)) {
            throw ValidationException::withMessages(['snapshot' => __('This database is no longer selected for backup.')]);
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
}
