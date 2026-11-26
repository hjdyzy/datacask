<div>
    <!-- HEADER with filters (Desktop) -->
    <x-header title="{{ __('Users') }}" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                @include('livewire.user._filters', ['variant' => 'desktop'])
            </div>
            @can('create', App\Models\User::class)
                <x-button label="{{ __('Add User') }}" link="{{ route('users.create') }}" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
            @endcan
        </x-slot:actions>
    </x-header>

    <!-- FILTERS (Tablet & Mobile) -->
    <div class="lg:hidden mb-4" x-data="{ showFilters: false }">
        @include('livewire.user._filters', ['variant' => 'mobile'])
    </div>

    <!-- TABLE -->
    <x-card shadow>
        @if($selected !== [])
            <div class="mb-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-lg border border-base-content/10 bg-base-200/60 px-3 py-2 sm:px-4">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-2 text-sm font-semibold" aria-live="polite">
                        <x-icon name="o-check-circle" class="size-4 text-primary" />
                        {{ __('Selected users: :count', ['count' => count($selected)]) }}
                    </span>
                    <x-button :label="__('Clear selection')" wire:click="clearSelection" spinner class="btn-ghost btn-sm font-normal text-base-content/70" />
                    @if($users->hasPages() && count($selected) < $users->total())
                        <x-button :label="__('Select all :count matching users', ['count' => $users->total()])" wire:click="selectAllMatching" spinner class="btn-ghost btn-sm text-primary" />
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-button :label="__('Change role')" icon="o-user-group" wire:click="openBulkAction('role')" spinner class="btn-primary btn-sm" />
                    <x-button :label="__('Copy invitation links')" icon="o-link" wire:click="openBulkAction('invite')" spinner class="btn-outline btn-sm" />
                    <x-floating-dropdown right>
                        <x-slot:trigger>
                            <x-button
                                :label="__('More actions')"
                                icon="o-ellipsis-horizontal"
                                class="btn-ghost btn-sm"
                                x-bind:aria-expanded="open"
                            />
                        </x-slot:trigger>
                        <x-menu-item :title="__('Remove from organization')" icon="o-user-minus" wire:click="openBulkAction('remove')" spinner />
                        <x-menu-item :title="__('Delete')" icon="o-trash" wire:click="openBulkAction('delete')" spinner class="text-error" />
                    </x-floating-dropdown>
                </div>
            </div>
        @endif
        @error('selected')
            <p role="alert" class="mb-3 text-sm text-error">{{ $message }}</p>
        @enderror
        <x-table
            :headers="$headers"
            :rows="$users"
            :sort-by="$sortBy"
            with-pagination
        >
            @scope('header_selection', $header, $users, $selected)
                @php($pageSelected = $users->isNotEmpty() && array_diff($users->getCollection()->modelKeys(), $selected) === [])
                <input
                    type="checkbox"
                    class="checkbox checkbox-sm"
                    aria-label="{{ __('Select users on this page') }}"
                    wire:key="user-page-selection-{{ $users->currentPage() }}-{{ $pageSelected ? 'checked' : 'unchecked' }}"
                    wire:click="togglePageSelection"
                    @disabled($users->isEmpty())
                    @checked($pageSelected)
                />
            @endscope

            @scope('cell_selection', $user, $selected)
                <input
                    type="checkbox"
                    class="checkbox checkbox-sm"
                    aria-label="{{ __('Select :name', ['name' => $user->name]) }}"
                    wire:key="user-selection-{{ $user->id }}"
                    wire:click="toggleUserSelection({{ $user->id }})"
                    @checked(in_array($user->id, $selected, true))
                />
            @endscope

            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($this->hasFilters)
                        {{ __('No users found matching your filters.') }}
                    @else
                        {{ __('No users yet.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $user)
                <div class="flex items-center gap-2">
                    <div class="table-cell-primary">{{ $user->name }}</div>
                    @if($user->id === auth()->id())
                        <span class="text-xs text-base-content/50">{{ __('(You)') }}</span>
                    @endif
                    @if($user->isOAuth())
                        <x-badge value="OAuth" class="badge-ghost badge-sm" />
                    @endif
                </div>
            @endscope

            @scope('cell_email', $user)
                {{ $user->email }}
            @endscope

            @scope('cell_role', $user)
                @php($roleName = $user->currentOrgRoleName())
                <div class="flex flex-wrap items-center gap-1">
                    @if($user->isSuperAdmin())
                        <x-badge :value="__('Super Admin')" icon="o-star" class="badge-warning whitespace-nowrap" />
                    @endif
                    @if($roleName)
                        <x-badge :value="\Illuminate\Support\Str::headline($roleName)" icon="o-user-group" class="badge-neutral" />
                    @endif
                </div>
            @endscope

            @scope('cell_status', $user)
                <x-user-status-badge :user="$user" />
            @endscope

            @scope('cell_created_at', $user)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($user->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $user->created_at->diffForHumans() }}</div>
            @endscope

            @scope('actions', $user)
                <div class="flex gap-2 justify-end">
                    @can('copyInvitationLink', $user)
                        <x-button
                            icon="o-clipboard-document"
                            wire:click="copyInvitationLink({{ $user->id }})"
                            spinner
                            tooltip="{{ __('Copy Invitation Link') }}"
                            class="btn-ghost btn-sm text-info"
                        />
                    @endcan
                    @can('update', $user)
                        <x-button
                            icon="o-pencil"
                            link="{{ route('users.edit', $user) }}"
                            wire:navigate
                            tooltip="{{ __('Edit') }}"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('removeFromOrganization', $user)
                        <x-button
                            icon="o-user-minus"
                            wire:click="confirmRemoveFromOrg({{ $user->id }})"
                            spinner
                            tooltip="{{ __('Remove from organization') }}"
                            class="btn-ghost btn-sm text-warning"
                        />
                    @endcan
                    @can('delete', $user)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete({{ $user->id }})"
                            spinner
                            tooltip="{{ __('Delete') }}"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
        <label class="mt-3 flex w-fit items-center gap-2 text-sm text-base-content/75">
            {{ __('Users per page') }}
            <select wire:model.live="perPage" class="select select-sm w-auto">
                @foreach($pageSizes as $size)
                    <option value="{{ $size }}">{{ $size }}</option>
                @endforeach
            </select>
        </label>
    </x-card>

    @include('livewire.user._bulk-actions')

    <!-- DELETE CONFIRMATION MODAL -->
    <x-confirm-modal
        model="showDeleteModal"
        :title="__('Delete User')"
        :message="__('Are you sure you want to delete this user? This action cannot be undone.')"
        on-confirm="delete"
        :confirm-label="__('Delete')"
        :blocked-reason="$deleteBlockReason"
    >
        <x-alert icon="o-information-circle" class="alert-info mt-4">
            {!! __('Database servers, backups, snapshots, and other resources created by this user <strong>WILL NOT</strong> be deleted and will remain accessible.') !!}
        </x-alert>
    </x-confirm-modal>

    <!-- REMOVE FROM ORG CONFIRMATION MODAL -->
    <x-confirm-modal
        model="showRemoveModal"
        :title="__('Remove User from Organization')"
        :message="__('Are you sure you want to remove this user from the current organization? The user will retain access to other organizations they belong to.')"
        on-confirm="removeFromOrg"
        :confirm-label="__('Remove')"
        confirm-class="btn-warning"
        :blocked-reason="$removeBlockReason"
    />

    <!-- COPY INVITATION LINK MODAL -->
    <x-invitation-link-modal
        :title="__('Invitation Link')"
        :message="__('Copy this link and send it to the user so they can complete their registration.')"
        :doneLabel="__('Close')"
    />
</div>
