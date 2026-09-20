<?php

namespace App\Console\Commands;

use App\Enums\BackupJobStatus;
use App\Facades\AppConfig;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Support\QueueTimeouts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class RecoverStuckJobsCommand extends Command
{
    protected $signature = 'jobs:recover-stuck';

    protected $description = 'Recover stuck jobs (expired agent leases and timed-out backup jobs)';

    public function handle(): int
    {
        $agentResult = $this->recoverAgentJobs();
        $backupResult = $this->recoverBackupJobs();

        if (! $agentResult && ! $backupResult) {
            $this->info('No stuck jobs found.');
        }

        return self::SUCCESS;
    }

    /**
     * Recover expired agent job leases (reset or fail stale jobs).
     */
    private function recoverAgentJobs(): bool
    {
        $expiredJobs = AgentJob::query()
            ->with(['snapshot.job'])
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->where('lease_expires_at', '<', now())
            ->get();

        if ($expiredJobs->isEmpty()) {
            return false;
        }

        $resetCount = 0;
        $failedCount = 0;

        foreach ($expiredJobs as $job) {
            if ($job->attempts < $job->max_attempts) {
                $job->update([
                    'status' => AgentJob::STATUS_PENDING,
                    'agent_id' => null,
                    'lease_expires_at' => null,
                ]);
                $resetCount++;
            } else {
                $errorMessage = "Max attempts ({$job->max_attempts}) exceeded with expired lease.";
                $job->markFailed($errorMessage);

                // Discovery jobs have no snapshot; only backup jobs carry one to fail.
                $job->snapshot?->job->markFailed(
                    new RuntimeException("Agent job failed: {$errorMessage}")
                );
                $failedCount++;
            }
        }

        $this->info("Agent jobs: recovered {$resetCount}, failed {$failedCount}.");

        return true;
    }

    /**
     * Recover backup jobs stuck in running state beyond their timeout.
     *
     * Pending jobs remain queued until the queue has drained. This prevents
     * queue backlog from being mistaken for an execution timeout.
     */
    private function recoverBackupJobs(): bool
    {
        $timeout = AppConfig::get('backup.job_timeout') + QueueTimeouts::RETRY_GRACE_SECONDS;
        $cutoff = now()->subSeconds($timeout);
        $queueHasPendingJobs = Queue::size('backups') > 0;

        $stuckJobs = BackupJob::query()
            ->inProgress()
            ->whereDoesntHave('snapshot.agentJobs', function ($query) {
                $query->whereIn('status', [
                    AgentJob::STATUS_PENDING,
                    AgentJob::STATUS_CLAIMED,
                    AgentJob::STATUS_RUNNING,
                ]);
            })
            ->where(function ($query) use ($cutoff) {
                $query->where(function ($q) use ($cutoff) {
                    $q->where('status', BackupJobStatus::Running)
                        ->where('started_at', '<', $cutoff);
                });
            })
            ->get();

        if (! $queueHasPendingJobs) {
            $stuckJobs = $stuckJobs->merge($this->findOrphanedPendingBackupJobs($cutoff));
        }

        if ($stuckJobs->isEmpty()) {
            return false;
        }

        foreach ($stuckJobs as $job) {
            $state = $job->status->value;
            $exception = new RuntimeException("Job timed out: stuck in {$state} state beyond the configured timeout.");
            $job->log($exception->getMessage(), 'error', [
                'source' => 'stuck_job_recovery',
                'queue_execution_started' => $state !== BackupJobStatus::Pending->value,
            ]);
            $job->markFailed($exception);
        }

        $this->info("Backup jobs: failed {$stuckJobs->count()} stuck job(s).");

        return true;
    }

    /**
     * Pending jobs can only be considered orphaned after the backup queue is empty.
     */
    private function findOrphanedPendingBackupJobs($cutoff)
    {
        return BackupJob::query()
            ->inProgress()
            ->where('status', BackupJobStatus::Pending)
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('snapshot.agentJobs', function ($query) {
                $query->whereIn('status', [
                    AgentJob::STATUS_PENDING,
                    AgentJob::STATUS_CLAIMED,
                    AgentJob::STATUS_RUNNING,
                ]);
            })
            ->get();
    }
}
