<?php

namespace App\Livewire\User;

use App\Actions\Role\AssignRoleToUserAction;
use App\Actions\User\ImportUserInvitationsAction;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Traits\SurfacesValidationErrors;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Silber\Bouncer\Database\Role;

#[Title('Add User')]
class Create extends Component
{
    use AuthorizesRequests, SurfacesValidationErrors, Toast, WithFileUploads;

    public Form $form;

    public string $mode = 'invite';

    public string $existingUserId = '';

    public string $existingUserRole = 'member';

    public bool $showCopyModal = false;

    public string $invitationUrl = '';

    public string $batchRole = '';

    public string $batchCsv = '';

    public ?TemporaryUploadedFile $batchFile = null;

    #[Locked]
    public string $batchOrganizationId = '';

    public bool $showBulkResults = false;

    /** @var list<array{id: int, email: ?string, message: ?string, link: ?string, failed: bool}> */
    #[Locked]
    public array $bulkResults = [];

    public function mount(): void
    {
        $this->authorize('create', User::class);
        $this->batchOrganizationId = app(CurrentOrganization::class)->id();
    }

    public function save(): void
    {
        $this->authorize('create', User::class);

        $user = $this->withValidationFeedback(fn (): User => $this->form->store());

        $this->invitationUrl = $user->getInvitationUrl();
        $this->showCopyModal = true;
    }

    public function importInvitations(): void
    {
        $this->authorize('create', User::class);
        $organization = app(CurrentOrganization::class)->model();
        abort_unless($this->batchOrganizationId === $organization->id, 403);

        $this->validate([
            'batchRole' => ['required', Rule::in(array_column($this->form->roleOptions(), 'id'))],
            'batchCsv' => ['required_without:batchFile', 'string', 'max:'.(ImportUserInvitationsAction::MAX_FILE_KB * 1024)],
            'batchFile' => ['nullable', 'file', 'extensions:csv', 'max:'.ImportUserInvitationsAction::MAX_FILE_KB],
        ], attributes: [
            'batchRole' => __('Role'),
            'batchCsv' => __('CSV content'),
            'batchFile' => __('CSV file'),
        ]);

        $csv = $this->batchFile?->get() ?? $this->batchCsv;
        if ($csv === false) {
            throw ValidationException::withMessages(['batchFile' => __('Could not read the uploaded CSV file.')]);
        }

        $this->bulkResults = ImportUserInvitationsAction::run($organization, $csv, $this->batchRole);
        $this->reset('batchCsv', 'batchFile');
        $this->showBulkResults = true;
    }

    public function closeBulkResults(): void
    {
        $this->reset('showBulkResults', 'bulkResults');
    }

    public function addExisting(CurrentOrganization $currentOrg): void
    {
        $this->authorize('manageOrgMembership', User::class);

        $this->validate([
            'existingUserId' => 'required|exists:users,id',
            'existingUserRole' => ['required', Rule::in($this->assignableRoleNames())],
        ]);

        $user = User::findOrFail($this->existingUserId);

        if ($user->belongsToOrganization($currentOrg->model())) {
            $this->addError('existingUserId', __('This user is already a member of this organization.'));

            return;
        }

        // Membership and role assignment must both succeed, or neither.
        DB::transaction(function () use ($user, $currentOrg) {
            $user->organizations()->attach($currentOrg->id());
            AssignRoleToUserAction::run($user, $this->existingUserRole, $currentOrg->model());
        });

        $this->success(
            title: __('User added to organization.'),
            redirectTo: route('users.index')
        );
    }

    public function closeAndRedirect(): void
    {
        $this->success(
            title: __('User created successfully!'),
            redirectTo: route('users.index')
        );
    }

    /**
     * @return array<int, array{id: string|int, name: string}>
     */
    #[Computed]
    public function availableUsers(): array
    {
        $currentOrg = app(CurrentOrganization::class);

        return User::withoutGlobalScope(OrganizationScope::class)
            ->whereDoesntHave('organizations', function ($query) use ($currentOrg) {
                $query->where('organizations.id', $currentOrg->id());
            })
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => ['id' => $user->id, 'name' => "{$user->name} ({$user->email})"])
            ->all();
    }

    #[Computed]
    public function hasMultipleOrganizations(): bool
    {
        return Organization::count() > 1;
    }

    /**
     * Role names assignable to an existing user (every role except the hidden
     * Demo role). Mirrors the options shown in the form.
     *
     * @return list<string>
     */
    private function assignableRoleNames(): array
    {
        return array_values(
            Role::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Role $role) => (string) $role->name)
                ->all()
        );
    }

    public function render(): View
    {
        return view('livewire.user.create', [
            'roleOptions' => $this->form->roleOptions(),
            'abilityGroups' => $this->form->abilityGroups(),
            'availableUsers' => $this->availableUsers(),
        ]);
    }
}
