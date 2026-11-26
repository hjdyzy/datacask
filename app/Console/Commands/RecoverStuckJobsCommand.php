<?php

namespace App\Console\Commands;

use App\Actions\Agent\FailAgentJobAction;
use App\Actions\Backup\FailBackupAction;
use App\Actions\Restore\FailRestoreAction;
use App\Enums\AgentJobType;
use App\Enums\BackupJobStatus;
use App\Exceptions\Backup\JobCancelledException;
use App\Models\AgentJob;
use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Support\QueueTimeouts;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class RecoverStuckJobsCommand extends Command
{
    /**
     * How long a backup command may go without a heartbeat before its agent is
     * treated as lost.
     */
    public const int COMMAND_HEARTBEAT_STALE_SECONDS = 600;

    protected $signature = 'jobs:recover-stuck';

    protected $description = 'Recover stuck jobs (expired agent leases, silent agent backups and timed-out backup jobs)';

    public function handle(): int
    {
        $agentTimeoutResult = $this->failTimedOutAgentJobs();
        $agentResult = $this->recoverAgentJobs();
        $backupResult = $this->recoverBackupJobs();
        $cleanupResult = $this->failUnclaimedCleanupJobs();

        if (! $agentTimeoutResult && ! $agentResult && ! $backupResult && ! $cleanupResult) {
            $this->info('No stuck jobs found.');
        }

        return self::SUCCESS;
    }

    private function failTimedOutAgentJobs(): bool
    {
        $timedOut = AgentJob::query()
            ->whereIn('type', [AgentJobType::Backup, AgentJobType::Restore])
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->where('claimed_at', '<', now()->subSeconds(QueueTimeouts::retryAfter()));

        $failedCount = 0;
        foreach ($timedOut->pluck('id') as $id) {
            $failedCount += (int) DB::transaction(function () use ($timedOut, $id): bool {
                $job = (clone $timedOut)->whereKey($id)->lockForUpdate()->first();
                if ($job === null) {
                    return false;
                }

                return FailAgentJobAction::run($job, ucfirst($job->type->value).' timed out.');
            });
        }

        if ($failedCount > 0) {
            $this->info("Agent jobs: failed {$failedCount} timed-out job(s).");
        }

        return $failedCount > 0;
    }

    /**
     * Recover agent jobs whose lease expired, or backups whose agent stopped
     * sending heartbeats while running a command (reset or fail them).
     *
     * Restores are only recovered by lease: a restore the server failed while
     * a cut-off agent was still running it would end up applied but reported
     * as failed, and it is never retried anyway.
     */
    private function recoverAgentJobs(): bool
    {
        $expiredJobs = AgentJob::query()
            ->with(['snapshot.job', 'restore.job'])
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->where(function ($query) {
                $query->where('lease_expires_at', '<', now())
                    ->orWhere(fn ($query) => $query
                        ->where('type', AgentJobType::Backup)
                        ->whereIn('snapshot_id', Snapshot::query()->select('id')->whereIn(
                            'backup_job_id',
                            BackupJob::query()->select('id')->where('command_heartbeat_at', '<', now()->subSeconds(self::COMMAND_HEARTBEAT_STALE_SECONDS)),
                        )));
            })
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

                try {
                    $job->trackedJob()?->markAgentLost();
                } catch (JobCancelledException) {
                    continue;
                }
            } else {
                $errorMessage = "Max attempts ({$job->max_attempts}) exceeded after losing contact with the agent.";

                Log::warning('Agent job failed after losing contact with the agent', [
                    'agent_job_id' => $job->id,
                    'type' => $job->type->value,
                    'attempts' => $job->attempts,
                ]);

                try {
                    $job->trackedJob()?->log("Lost contact with the agent: {$errorMessage}", 'error');
                } catch (Throwable $e) {
                    report($e);
                }

                try {
                    FailAgentJobAction::run($job, $errorMessage, exception: new RuntimeException("Agent job failed: {$errorMessage}"));
                } catch (Throwable $e) {
                    report($e);
                }
                $failedCount++;
            }
        }

        $this->info("Agent jobs: recovered {$resetCount}, failed {$failedCount}.");

        return true;
    }

    /**
     * Fail cleanup jobs no agent picked up in time, such as one too old to
     * run them, so their snapshots leave the deleting state and show why.
     * Backup and restore jobs are failed the same way through their tracked
     * job, which cleanup jobs do not have.
     */
    private function failUnclaimedCleanupJobs(): bool
    {
        $unclaimedJobs = AgentJob::query()
            ->where('type', AgentJobType::Cleanup)
            ->where('status', AgentJob::STATUS_PENDING)
            ->where('created_at', '<', now()->subSeconds(QueueTimeouts::retryAfter()))
            ->get();

        $failedCount = 0;

        foreach ($unclaimedJobs as $job) {
            $errorMessage = 'No agent picked up the deletion of this file. Make sure the agent is online and up to date.';

            try {
                if (! FailAgentJobAction::run($job, $errorMessage, onlyIfPending: true)) {
                    continue;
                }
            } catch (Throwable $e) {
                report($e);
            }

            $failedCount++;
        }

        if ($failedCount > 0) {
            $this->info("Cleanup jobs: failed {$failedCount} unclaimed job(s).");
        }

        return $failedCount > 0;
    }

    /**
     * Recover backup jobs stuck in running/pending state beyond their timeout.
     *
     * Pending jobs remain queued until the queue has drained. This prevents
     * queue backlog from being mistaken for an execution timeout.
     */
    private function recoverBackupJobs(): bool
    {
        $cutoff = now()->subSeconds(QueueTimeouts::retryAfter());
        $queueHasPendingJobs = Queue::size('backups') > 0;

        $stuckJobs = BackupJob::query()
            ->with(['snapshot.databaseServer', 'restore.targetServer'])
            ->inProgress()
            ->whereDoesntHave('snapshot.agentJobs', function ($query) {
                $query->whereIn('status', [
                    AgentJob::STATUS_PENDING,
                    AgentJob::STATUS_CLAIMED,
                    AgentJob::STATUS_RUNNING,
                ]);
            })
            ->whereDoesntHave('restore.agentJobs', function ($query) {
                $query->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING]);
            })
            ->where('status', BackupJobStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->get();

        if (! $queueHasPendingJobs) {
            $stuckJobs = $stuckJobs->merge($this->findOrphanedPendingBackupJobs($cutoff));
        }

        if ($stuckJobs->isEmpty()) {
            return false;
        }

        $failedCount = 0;

        foreach ($stuckJobs as $job) {
            $exception = new RuntimeException('Job timed out: stuck in '.$job->status->value.' state beyond the configured timeout.');

            try {
                try {
                    $job->log($exception->getMessage(), 'error', [
                        'source' => 'stuck_job_recovery',
                        'queue_execution_started' => $job->status !== BackupJobStatus::Pending,
                    ]);
                } catch (JobCancelledException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                }

                match (true) {
                    $job->snapshot !== null => FailBackupAction::run($job->snapshot->setRelation('job', $job), $exception),
                    $job->restore !== null => FailRestoreAction::run($job->restore->setRelation('job', $job), $exception),
                    default => $job->markFailed($exception),
                };
            } catch (JobCancelledException) {
                continue;
            }

            $failedCount++;

            Log::warning('Backup job timed out', [
                'backup_job_id' => $job->id,
                'snapshot_id' => $job->snapshot?->id,
                'restore_id' => $job->restore?->id,
            ]);
        }

        // A timed-out job must never run or be revived afterwards: an agent
        // still running it is stopped at its next report, and one waiting to
        // be claimed is never handed out.
        AgentJob::query()
            ->unfinishedFor($stuckJobs->modelKeys())
            ->get()
            ->each(fn (AgentJob $agentJob) => $agentJob->markFailed(ucfirst($agentJob->type->value).' timed out.'));

        $this->info("Backup jobs: failed {$failedCount} stuck job(s).");

        return true;
    }

    /**
     * Pending jobs can only be considered orphaned after the backup queue is empty.
     *
     * @return Collection<int, BackupJob>
     */
    private function findOrphanedPendingBackupJobs(CarbonInterface $cutoff): Collection
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
