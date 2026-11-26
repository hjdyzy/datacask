<?php

namespace App\Actions\User;

use App\Actions\Role\AssignRoleToUserAction;
use App\Actions\Role\SyncUserAbilitiesAction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class InviteUserAction
{
    use AsFake, AsObject;

    /** @param list<string>|null $abilities */
    public function handle(
        Organization $organization,
        string $name,
        string $email,
        string $role,
        bool $superAdmin = false,
        ?array $abilities = null,
    ): User {
        return DB::transaction(function () use ($organization, $name, $email, $role, $superAdmin, $abilities) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'super_admin' => $superAdmin,
            ]);

            $user->organizations()->attach($organization->id);
            AssignRoleToUserAction::run($user, $role, $organization);
            if ($abilities !== null) {
                SyncUserAbilitiesAction::run($user, $abilities, $organization);
            }

            $user->generateInvitationToken();

            return $user;
        });
    }
}
