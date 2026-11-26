<?php

namespace App\Actions\User;

use App\Actions\Role\AssignRoleToUserAction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ProcessBulkUsersAction
{
    use AsFake, AsObject;

    /**
     * @param  list<int>  $ids
     * @return list<array{id: int, email: ?string, message: ?string, link: ?string, failed: bool}>
     */
    public function handle(User $actor, Organization $organization, array $ids, string $action, string $role): array
    {
        Gate::forUser($actor)->authorize('viewAny', User::class);

        $results = [];
        foreach ($ids as $id) {
            try {
                $results[] = DB::transaction(fn () => $this->processUser($actor, $organization, $id, $action, $role));
            } catch (QueryException $exception) {
                report($exception);
                $results[] = $this->result($id, null, __('Database error while processing this user.'), failed: true);
            }
        }

        return $results;
    }

    /** @return array{id: int, email: ?string, message: ?string, link: ?string, failed: bool} */
    private function processUser(User $actor, Organization $organization, int $id, string $action, string $role): array
    {
        $user = User::query()->whereRelation('organizations', 'organization_id', $organization->id)
            ->lockForUpdate()->find($id);

        if ($user === null) {
            return $this->result($id, null, __('User is no longer in this organization.'));
        }

        $reason = $this->blockReason($actor, $user, $action);
        if ($reason !== null) {
            return $this->result($id, $user->email, $reason);
        }

        $link = match ($action) {
            'invite' => $user->getInvitationUrl(),
            'remove' => $user->organizations()->detach($organization->id) === 1 ? null : false,
            'delete' => $user->delete() ? null : false,
            'role' => $this->assignRole($user, $role, $organization),
            default => throw new \LogicException('Unknown bulk user action.'),
        };

        if ($link === false) {
            return $this->result($id, $user->email, __('The change could not be saved for this user.'), failed: true);
        }

        return $this->result($id, $user->email, link: $link);
    }

    private function blockReason(User $actor, User $user, string $action): ?string
    {
        if ($action === 'invite' && ! $user->isPending()) {
            return __('Only pending users have an invitation link.');
        }

        if ($action !== 'invite' && $actor->is($user)) {
            return __('Your own account is protected from bulk changes.');
        }

        if ($action !== 'invite' && $user->isSuperAdmin()) {
            return __('Super administrators are protected from bulk changes.');
        }

        $ability = match ($action) {
            'invite' => 'copyInvitationLink',
            'remove' => 'removeFromOrganization',
            'delete' => 'delete',
            'role' => 'update',
            default => throw new \LogicException('Unknown bulk user action.'),
        };

        if (Gate::forUser($actor)->denies($ability, $user)) {
            return __('You do not have permission to perform this action on this user.');
        }

        return match ($action) {
            'delete' => $user->organizations()->count() > 1
                ? (string) __('This user belongs to multiple organizations. Remove them from this organization instead.') : null,
            'remove' => self::removalBlockReason($user),
            default => null,
        };
    }

    public static function removalBlockReason(User $user): ?string
    {
        return $user->organizations()->count() <= 1
            ? (string) __('This user only belongs to this organization. Removing them would leave them without access.') : null;
    }

    private function assignRole(User $user, string $role, Organization $organization): null
    {
        AssignRoleToUserAction::run($user, $role, $organization);

        return null;
    }

    /** @return array{id: int, email: ?string, message: ?string, link: ?string, failed: bool} */
    private function result(int $id, ?string $email, ?string $message = null, ?string $link = null, bool $failed = false): array
    {
        return compact('id', 'email', 'message', 'link', 'failed');
    }
}
