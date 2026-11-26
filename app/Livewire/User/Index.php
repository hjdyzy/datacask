<?php

namespace App\Livewire\User;

use App\Actions\User\ProcessBulkUsers;
use App\Livewire\Concerns\ConfirmsDeletion;
use App\Livewire\Concerns\FiltersAndPaginates;
use App\Models\User;
use App\Services\CurrentOrganization;
use App\Support\Formatters;
use App\Traits\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\Database\Role;

#[Title('Users')]
class Index extends Component
{
    use AuthorizesRequests, ConfirmsDeletion, Toast, WithPagination;
    use FiltersAndPaginates {
        updated as private updatedFilters;
        clear as private clearFilters;
    }

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $statusFilter = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = ['name', 'email', 'created_at'];

    private const PAGE_SIZES = [15, 30, 50, 100];

    private const MAX_BULK_USERS = 200;

    public int $perPage = self::PAGE_SIZES[0];

    /** @var list<int> */
    public array $selected = [];

    #[Locked]
    public string $selectionOrganizationId = '';

    /** @var list<int> */
    #[Locked]
    public array $bulkIds = [];

    #[Locked]
    public string $bulkAction = '';

    #[Locked]
    public string $bulkOrganizationId = '';

    public string $bulkRole = '';

    public bool $showBulkConfirmation = false;

    public bool $showBulkResults = false;

    /** @var list<array{id: int, email: ?string, message: ?string, link: ?string, failed: bool}> */
    #[Locked]
    public array $bulkResults = [];

    public string $deleteBlockReason = '';

    #[Locked]
    public ?int $removeId = null;

    public bool $showRemoveModal = false;

    public string $removeBlockReason = '';

    public bool $showCopyModal = false;

    public string $invitationUrl = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
        $this->selectionOrganizationId = app(CurrentOrganization::class)->id();
    }

    /** @param string|array<string, mixed> $property */
    public function updated(string|array $property): void
    {
        $this->updatedFilters($property);
        if (is_string($property) && in_array($property, $this->filterProperties(), true)) {
            $this->clearSelection();
        }

        if ($property === 'perPage') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->clearSelection();
        $this->clearFilters();
    }

    public function clearSelection(): void
    {
        $this->reset('selected');
        $this->cancelBulkAction();
    }

    public function selectAllMatching(): void
    {
        $this->authorizeBulkSelection();
        $ids = $this->filteredUsers(app(CurrentOrganization::class)->id())
            ->orderBy('id')
            ->limit(self::MAX_BULK_USERS + 1)
            ->pluck('id');

        if ($ids->count() > self::MAX_BULK_USERS) {
            $this->addError('selected', $this->selectionLimitMessage());

            return;
        }

        $this->cancelBulkAction();
        $this->selected = array_map(intval(...), array_values($ids->all()));
    }

    public function togglePageSelection(): void
    {
        $this->authorizeBulkSelection();
        $pageIds = $this->paginatedUsers()->getCollection()->modelKeys();

        $this->selected = array_diff($pageIds, $this->selected) === []
            ? array_values(array_diff($this->selected, $pageIds))
            : array_values(array_unique([...$this->selected, ...$pageIds]));
        $this->resetValidation('selected');
    }

    public function toggleUserSelection(int $id): void
    {
        $this->authorizeBulkSelection();
        $this->selected = in_array($id, $this->selected, true)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];
        $this->resetValidation('selected');
    }

    private function selectionLimitMessage(): string
    {
        return __('Select up to :limit users per batch. Use filters or choose fewer users.', ['limit' => self::MAX_BULK_USERS]);
    }

    public function openBulkAction(string $action): void
    {
        $this->authorizeBulkSelection();
        abort_unless(in_array($action, ['remove', 'delete', 'role', 'invite'], true), 422);
        if ($this->selected === []) {
            return;
        }

        $this->validate([
            'selected' => ['list', 'max:'.self::MAX_BULK_USERS],
            'selected.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], ['selected.max' => $this->selectionLimitMessage()]);

        $this->closeBulkResults();
        $this->resetValidation();
        $this->bulkIds = array_map(intval(...), $this->selected);
        $this->bulkAction = $action;
        $this->bulkOrganizationId = app(CurrentOrganization::class)->id();
        $this->bulkRole = '';
        $this->showBulkConfirmation = true;

        if ($action === 'invite') {
            $this->applyBulkAction(app(ProcessBulkUsers::class));
        }
    }

    public function applyBulkAction(ProcessBulkUsers $processor): void
    {
        $this->authorize('viewAny', User::class);
        if (! $this->showBulkConfirmation || $this->bulkIds === []) {
            return;
        }

        $organization = app(CurrentOrganization::class)->model();
        abort_unless($this->bulkOrganizationId === $organization->id, 403);

        if ($this->bulkAction === 'role') {
            $this->validate(['bulkRole' => ['required', Rule::in(array_column($this->roleFilterOptions(), 'id'))]]);
        }

        $this->bulkResults = $processor->run(auth()->user(), $organization, $this->bulkIds, $this->bulkAction, $this->bulkRole);
        $this->clearSelection();
        $this->showBulkResults = true;
    }

    private function authorizeBulkSelection(): void
    {
        $this->authorize('viewAny', User::class);
        abort_unless($this->selectionOrganizationId === app(CurrentOrganization::class)->id(), 403);
    }

    public function cancelBulkAction(): void
    {
        $this->reset('bulkIds', 'bulkAction', 'bulkRole', 'bulkOrganizationId', 'showBulkConfirmation');
        $this->resetValidation();
    }

    public function closeBulkResults(): void
    {
        $this->reset('showBulkResults', 'bulkResults');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'selection', 'label' => '', 'class' => 'w-1', 'sortable' => false],
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'email', 'label' => __('Email')],
            ['key' => 'role', 'label' => __('Role'), 'class' => 'w-32', 'sortable' => false],
            ['key' => 'status', 'label' => __('Status'), 'class' => 'w-32', 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function roleFilterOptions(): array
    {
        return Role::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => (string) $role->name,
                'name' => (string) ($role->title ?: $role->name),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function statusFilterOptions(): array
    {
        return [
            ['id' => 'active', 'name' => __('Active')],
            ['id' => 'pending', 'name' => __('Pending')],
        ];
    }

    public function copyInvitationLink(int $id): void
    {
        $user = User::findOrFail($id);

        $this->authorize('copyInvitationLink', $user);

        $this->invitationUrl = $user->getInvitationUrl();
        $this->showCopyModal = true;
    }

    public function confirmDelete(int $id): void
    {
        $user = $this->confirmDeletion(User::query(), $id);

        $this->deleteBlockReason = $this->getDeleteBlockReason($user);
    }

    public function delete(): void
    {
        $user = $this->pendingDeletion(User::query());

        if ($user === null || $this->getDeleteBlockReason($user) !== '') {
            return;
        }

        $user->delete();
        $this->closeDeletion();

        $this->success(__('User deleted successfully.'));
    }

    public function confirmRemoveFromOrg(int $id): void
    {
        $user = User::findOrFail($id);

        $this->authorize('removeFromOrganization', $user);

        $this->removeId = $id;
        $this->removeBlockReason = $this->getRemoveBlockReason($user);
        $this->showRemoveModal = true;
    }

    public function removeFromOrg(): void
    {
        if (! $this->removeId) {
            return;
        }

        $user = User::findOrFail($this->removeId);

        $this->authorize('removeFromOrganization', $user);

        if ($this->getRemoveBlockReason($user) !== '') {
            return;
        }

        $currentOrg = app(CurrentOrganization::class);
        $user->organizations()->detach($currentOrg->id());

        $this->removeId = null;
        $this->showRemoveModal = false;

        $this->success(__('User removed from organization.'));
    }

    private function getDeleteBlockReason(User $user): string
    {
        if ($user->isSuperAdmin() && User::where('super_admin', true)->count() === 1) {
            return __('This is the only super admin account. It cannot be deleted.');
        }

        if (! auth()->user()->isSuperAdmin() && $user->organizations()->count() > 1) {
            return __('This user belongs to multiple organizations. Remove them from this organization instead.');
        }

        return '';
    }

    private function getRemoveBlockReason(User $user): string
    {
        return ProcessBulkUsers::removalBlockReason($user) ?? '';
    }

    public function render(): View
    {
        return view('livewire.user.index', [
            'users' => $this->paginatedUsers(),
            'headers' => $this->headers(),
            'roleFilterOptions' => $this->roleFilterOptions(),
            'statusFilterOptions' => $this->statusFilterOptions(),
            'canManageOrgMembership' => auth()->user()->can('manageOrgMembership', User::class),
            'pageSizes' => self::PAGE_SIZES,
        ]);
    }

    /** @return LengthAwarePaginator<int, User> */
    private function paginatedUsers(): LengthAwarePaginator
    {
        $sortColumn = Formatters::sortColumn($this->sortBy['column'] ?? null, self::ALLOWED_SORT_COLUMNS, 'created_at');
        $pageSize = in_array($this->perPage, self::PAGE_SIZES, true) ? $this->perPage : self::PAGE_SIZES[0];

        return $this->filteredUsers(app(CurrentOrganization::class)->id())
            ->with('organizations')
            ->orderBy($sortColumn, Formatters::sortDirection((string) ($this->sortBy['direction'] ?? 'desc')))
            ->orderBy('id')
            ->paginate($pageSize);
    }

    /** @return Builder<User> */
    private function filteredUsers(string $organizationId): Builder
    {
        return User::query()
            ->whereRelation('organizations', 'organization_id', $organizationId)
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->roleFilter !== '', function ($query) use ($organizationId) {
                $query->whereExists(function ($q) use ($organizationId) {
                    $q->from('assigned_roles')
                        ->join('roles', 'roles.id', '=', 'assigned_roles.role_id')
                        ->whereColumn('assigned_roles.entity_id', 'users.id')
                        ->where('assigned_roles.entity_type', (new User)->getMorphClass())
                        ->where('assigned_roles.scope', $organizationId)
                        ->where('roles.name', $this->roleFilter);
                });
            })
            ->when($this->statusFilter !== '', function ($query) {
                if ($this->statusFilter === 'active') {
                    $query->whereNotNull('invitation_accepted_at');
                } else {
                    $query->whereNull('invitation_accepted_at');
                }
            });
    }

    /**
     * @return list<string>
     */
    protected function filterProperties(): array
    {
        return ['search', 'roleFilter', 'statusFilter'];
    }
}
