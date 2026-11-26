<?php

namespace App\Jobs;

use App\Actions\Backup\CompleteBackupAction;
use App\Actions\Backup\FailBackupAction;
use App\Actions\Backup\RecordBackupResultAction;
use App\Enums\BackupJobStatus;
use App\Enums\SnapshotFileStatus;
use App\Exceptions\Backup\JobCancelledException;
use App\Exceptions\Backup\VolumeTransferException;
use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\BackupResult;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Support\FilesystemSupport;
use App\Support\QueueTimeouts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public int $backoff;

    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $snapshotId
    ) {
        $this->timeout = AppConfig::get('backup.job_timeout');
        $this->backoff = AppConfig::get('backup.job_backoff');
        $this->tries = AppConfig::get('backup.job_tries');
        $this->onQueue('backups');
    }

    /**
     * Refuse to dump the same snapshot twice at once.
     *
     * QueueTimeouts keeps retry_after above the job timeout, so a re-delivery
     * mid-run should no longer happen. This is the second line of defence.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [QueueTimeouts::overlapGuard($this->snapshotId, $this->timeout)];
    }

    /**
     * Execute the job. A cancelled job ends here, wherever its runner was:
     * it is neither retried nor reported as failed.
     */
    public function handle(BackupTask $backupTask): void
    {
        try {
            $this->process($backupTask);
        } catch (JobCancelledException) {
            Log::info('Backup cancelled', ['snapshot_id' => $this->snapshotId]);
        }
    }

    private function process(BackupTask $backupTask): void
    {
        $snapshot = Snapshot::with(['job', 'files.volume', 'backup', 'databaseServer.sshConfig'])->findOrFail($this->snapshotId);
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;

        try {
            $queueJobId = $this->job?->getJobId();
            if (! $job->claimForExecution($queueJobId)) {
                return;
            }
            $job->refresh();

            $attemptInfo = $this->job ? " (attempt {$this->attempts()}/{$this->tries})" : '';
            $job->log("Starting backup for database: {$snapshot->database_name}{$attemptInfo}", 'info');

            $result = $this->runBackup($backupTask, $snapshot, $job);
            if ($job->fresh()?->status !== BackupJobStatus::Running) {
                return;
            }

            if ($result !== null) {
                RecordBackupResultAction::run($snapshot, $result);
            }
            CompleteBackupAction::run($snapshot);

            Log::info('Backup completed successfully', [
                'snapshot_id' => $this->snapshotId,
                'database_server_id' => $databaseServer->id,
                'method' => $snapshot->method,
            ]);
        } catch (VolumeTransferException $e) {
            // At least one upload failed. The successful copies are still
            // recorded so their files stay tracked, then the job is failed.
            RecordBackupResultAction::run($snapshot, $e->result);

            $this->recordFailure($job, $e, [
                'exception' => get_class($e),
            ]);

            if ($e->allFailuresAreQuota()) {
                // Over-quota volumes won't free up on their own — fail
                // immediately (no retry). The custom message reaches the user
                // via the failure notification.
                $job->markFailed($e);
                $this->fail($e);

                return;
            }

            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure($job, $e, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    private function runBackup(BackupTask $backupTask, Snapshot $snapshot, BackupJob $job): ?BackupResult
    {
        $existingCopy = $snapshot->files->firstWhere('status', SnapshotFileStatus::Completed);
        $targetFiles = $snapshot->files->reject(
            fn (SnapshotFile $file) => $file->status === SnapshotFileStatus::Completed,
        );

        if ($existingCopy !== null && $targetFiles->isEmpty()) {
            return null;
        }

        if ($existingCopy !== null && blank($snapshot->filename)) {
            throw new \RuntimeException('The uploaded backup copy has no recorded archive name.');
        }

        $config = new BackupConfig(
            database: DatabaseConnectionConfig::fromServer($snapshot->databaseServer),
            volumes: array_values($targetFiles->map(
                fn (SnapshotFile $file) => VolumeConfig::fromVolume($file->volume, $file->volume->usedStorageBytes()),
            )->all()),
            databaseName: $snapshot->database_name,
            workingDirectory: FilesystemSupport::createWorkingDirectory('backup', $snapshot->id),
            backupPath: $snapshot->backup->path ?? '',
            postBackupScript: AppConfig::get('backup.post_backup_script'),
            excludedTables: Backup::parseExcludedTables($snapshot->backup?->excluded_tables),
        );

        if ($existingCopy === null) {
            return $backupTask->execute($config, $job);
        }

        // Every copy must reference the archive already recorded on the snapshot.
        return $backupTask->retryUploads(
            $config,
            $job,
            VolumeConfig::fromVolume($existingCopy->volume),
            new BackupResult($snapshot->filename, $snapshot->file_size, $snapshot->checksum),
        );
    }

    /**
     * Keep transient queue attempts retryable while recording the final failure.
     * Direct calls without a queue worker are treated as final attempts.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordFailure(BackupJob $job, \Throwable $exception, array $context): void
    {
        $job->log("Backup failed: {$exception->getMessage()}", 'error', $context);

        if ($this->job === null && $job->status->isInProgress()) {
            $job->markFailed($exception);
        }
    }

    /**
     * Handle a job failure (called by Laravel queue after all retries exhausted).
     */
    public function failed(\Throwable $exception): void
    {
        $snapshot = Snapshot::with(['job', 'databaseServer'])->find($this->snapshotId);

        if ($snapshot === null) {
            return;
        }

        try {
            // A worker killed on timeout never reached its own catch, so the
            // job is still running and its log does not say why it stopped.
            if ($snapshot->job->status->isInProgress()) {
                $snapshot->job->log("Backup failed: {$exception->getMessage()}", 'error');
            }

            FailBackupAction::run($snapshot, $exception);
        } catch (JobCancelledException) {
            // Cancelled meanwhile: nothing to conclude or notify.
        }
    }
}
