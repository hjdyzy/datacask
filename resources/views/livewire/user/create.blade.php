<div>
    <x-header :title="__('Add User')" :subtitle="__('Invite a new user or add an existing one to this organization')" size="text-2xl" separator class="mb-6">
        <x-slot:actions>
            <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate icon="o-arrow-left" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <x-card class="space-y-6">
            <x-radio-card-group :class="$this->hasMultipleOrganizations ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2'">
                <x-radio-card
                    :active="$mode === 'invite'"
                    icon="o-envelope"
                    :label="__('Invite new user')"
                    :hint="__('Create a new account and send an invitation link')"
                    value="invite"
                    horizontal
                    wire:model.live="mode"
                />
                <x-radio-card
                    :active="$mode === 'batch'"
                    icon="o-user-group"
                    :label="__('Bulk invite users')"
                    :hint="__('Create multiple accounts from a CSV list')"
                    value="batch"
                    horizontal
                    wire:model.live="mode"
                />
                @if($this->hasMultipleOrganizations)
                <x-radio-card
                    :active="$mode === 'existing'"
                    icon="o-user-plus"
                    :label="__('Add existing user')"
                    :hint="__('Add a user who already has an account to this organization')"
                    value="existing"
                    horizontal
                    wire:model.live="mode"
                />
                @endif
            </x-radio-card-group>

        @if($mode === 'invite')
            <form wire:submit="save" class="space-y-6">
                @include('livewire.user._form', [
                    'roleOptions' => $roleOptions,
                    'abilityGroups' => $abilityGroups,
                ])

                <div class="flex justify-end gap-3">
                    <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate />
                    <x-button type="submit" :label="__('Invite User')" class="btn-primary" spinner="save" />
                </div>
            </form>
        @elseif($mode === 'batch')
            <form wire:submit="importInvitations" class="space-y-5">
                <x-select
                    wire:model="batchRole"
                    :label="__('Role')"
                    :options="$roleOptions"
                    :placeholder="__('Select a role')"
                />
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-textarea
                        wire:model="batchCsv"
                        :label="__('CSV content')"
                        :hint="__('One user per row in name,email order. The header is optional.')"
                        :placeholder="'name,email'.PHP_EOL.'Alex,alex@example.com'"
                        rows="5"
                        class="font-mono"
                    />
                    <x-file
                        wire:model="batchFile"
                        :label="__('CSV file')"
                        :hint="__('A selected CSV file takes precedence over pasted content.')"
                        accept=".csv,text/csv"
                    />
                </div>
                <div class="flex justify-end gap-3">
                    <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate />
                    <div wire:loading.attr="inert" wire:target="batchFile">
                        <x-button type="submit" :label="__('Create invitation links')" class="btn-primary" spinner="importInvitations" />
                    </div>
                </div>
            </form>
        @elseif($mode === 'existing')
            <form wire:submit="addExisting" class="space-y-6">
                <x-select
                    wire:model="existingUserId"
                    :label="__('User')"
                    :options="$availableUsers"
                    :placeholder="__('Select a user')"
                    icon="o-user"
                    required
                />

                @include('livewire.user._role-cards', [
                    'roleOptions' => $roleOptions,
                    'selected' => $existingUserRole,
                    'model' => 'existingUserRole',
                ])

                <div class="flex justify-end gap-3">
                    <x-button :label="__('Cancel')" link="{{ route('users.index') }}" wire:navigate />
                    <x-button type="submit" :label="__('Add to Organization')" class="btn-primary" spinner="addExisting" />
                </div>
            </form>
        @endif
    </x-card>

    @include('livewire.user._bulk-results', ['resultTitle' => __('Invitation import results')])

    <!-- INVITATION LINK MODAL -->
    <x-invitation-link-modal
        :title="__('User Created Successfully')"
        :message="__('The user has been created. Copy the invitation link below and send it to the user so they can set their password and complete registration.')"
        doneAction="closeAndRedirect"
    />
</div>
