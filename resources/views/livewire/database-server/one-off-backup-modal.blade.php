<x-modal wire:model="showModal" :title="__('One-off backup')" class="backdrop-blur" box-class="max-w-2xl">
    <x-form wire:submit="submit">
        <div class="font-semibold">{{ $serverName }}</div>
        <x-alert icon="o-information-circle" class="alert-info">
            {{ __('Back up selected databases once, including those excluded from the schedule. Scheduled settings stay unchanged. System databases are not listed.') }}
        </x-alert>

        <x-select :label="__('Storage and retention configuration')" wire:model="backupId"
                  :options="$backupOptions" :placeholder="__('Select a backup configuration')" />
        <p class="text-sm text-base-content/70">
            {{ __('Storage destinations, path, compression and retention follow the existing configuration. Unprotected backups participate in its normal cleanup, including GFS rotation.') }}
        </p>

        <div class="flex items-end gap-2">
            <div class="flex-1">
                <x-input :label="__('Search databases')" wire:model.live.debounce.250ms="search" icon="o-magnifying-glass" />
            </div>
            <x-button :label="__('Refresh')" wire:click="refreshDatabases" icon="o-arrow-path" spinner="refreshDatabases" />
        </div>
        <div class="max-h-64 overflow-y-auto border border-base-300 rounded-lg p-3 space-y-2">
            @forelse($filteredDatabases as $database)
                <label class="flex items-center gap-2 cursor-pointer" wire:key="one-off-db-{{ md5($database) }}">
                    <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="databases" value="{{ $database }}" />
                    <span class="break-all">{{ $database }}</span>
                </label>
            @empty
                <p class="text-sm text-base-content/70">{{ __('No databases available or matching your search.') }}</p>
            @endforelse
        </div>
        <p class="text-sm">{{ __('Selected databases: :count', ['count' => count($databases)]) }}</p>
        @foreach($errors->getMessages() as $messages)
            @foreach($messages as $message)
                <p class="text-error text-sm" role="alert">{{ $message }}</p>
            @endforeach
        @endforeach

        <x-textarea :label="__('Comment')" wire:model="comment" maxlength="1000" rows="2"
                    :placeholder="__('For example: before the project upgrade')" />
        @can('lock', new \App\Models\Snapshot)
            <x-checkbox :label="__('Protect this backup from cleanup')" wire:model="locked" />
            <p class="text-sm text-base-content/70">
                {{ __('Protected snapshots are kept until manually unlocked. They continue to use storage space.') }}
            </p>
        @endcan

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showModal = false" />
            <x-button :label="__('Start one-off backup')" type="submit" class="btn-primary" spinner="submit"
                      :disabled="count($databases) === 0 || $backupId === '' || count($availableDatabases) === 0" />
        </x-slot:actions>
    </x-form>
</x-modal>
