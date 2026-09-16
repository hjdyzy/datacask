<?php

namespace App\Policies;

use App\Enums\Ability;
use App\Enums\BackupJobStatus;
use App\Models\Snapshot;
use App\Models\User;

class SnapshotPolicy
{
    public function retry(User $user, Snapshot $snapshot): bool
    {
        return $snapshot->job->status === BackupJobStatus::Failed
            && $snapshot->backup !== null
            && ! ($snapshot->metadata['preflight_failure'] ?? false)
            && ! in_array($snapshot->database_name, ['(all databases)', '(preflight)'], true)
            && $user->can('backup', $snapshot->databaseServer);
    }

    /**
     * Determine whether the user can view any models.
     * All authenticated users can view the list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * All authenticated users can view details.
     */
    public function view(User $user, Snapshot $snapshot): bool
    {
        return true;
    }

    /**
     * Determine whether the user can delete the model.
     * Requires the delete-snapshots ability, and refuses a locked snapshot:
     * locking exists to keep it, so no delete path may remove it until it is
     * unlocked.
     */
    public function delete(User $user, Snapshot $snapshot): bool
    {
        return ! $snapshot->locked && $user->can(Ability::DeleteSnapshots->value);
    }

    /**
     * Determine whether the user can edit the model's comment.
     * Requires the run-backups ability.
     */
    public function update(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::RunBackups->value);
    }

    /**
     * Determine whether the user can lock the snapshot against automatic cleanup.
     * Requires the delete-snapshots ability, since locking and unlocking decide
     * whether the retention policy is allowed to remove the snapshot.
     */
    public function lock(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::DeleteSnapshots->value);
    }

    /**
     * Determine whether the user can download the snapshot.
     * Requires the download-snapshots ability.
     */
    public function download(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::DownloadSnapshots->value);
    }

    /**
     * Determine whether the user can use this snapshot as the source of a restore.
     * Requires the operate-restores ability. Final authorization on the target
     * server is still checked separately via DatabaseServerPolicy@restore.
     */
    public function restoreFrom(User $user, Snapshot $snapshot): bool
    {
        return $user->can(Ability::OperateRestores->value);
    }
}
