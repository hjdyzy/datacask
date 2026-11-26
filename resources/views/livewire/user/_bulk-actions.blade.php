@php
    $bulkTitle = match ($bulkAction) {
        'delete' => __('Delete selected users'),
        'remove' => __('Remove selected users from this organization'),
        'role' => __('Change role for selected users'),
        default => '',
    };
    $confirmationLabel = match ($bulkAction) {
        'delete' => __('Delete'),
        'remove' => __('Remove'),
        default => __('Confirm'),
    };
@endphp

<x-modal wire:model="showBulkConfirmation" :title="$bulkTitle" box-class="max-w-lg" separator persistent>
    @if($bulkAction)
        <p class="mb-4 text-sm">{{ __('Selected users: :count', ['count' => count($bulkIds)]) }}</p>
        @if($bulkAction === 'role')
            <x-select :label="__('Role')" :options="$roleFilterOptions" :placeholder="__('Select a role')" wire:model="bulkRole" />
        @elseif($bulkAction === 'remove')
            <div class="flex items-start gap-3 rounded-lg bg-warning/10 p-3 text-sm">
                <x-icon name="o-user-minus" class="mt-0.5 size-5 shrink-0 text-warning" />
                <p>{{ __('Eligible users will lose access to this organization and retain their other memberships.') }}</p>
            </div>
        @elseif($bulkAction === 'delete')
            <div class="flex items-start gap-3 rounded-lg bg-error/10 p-3 text-sm">
                <x-icon name="o-exclamation-triangle" class="mt-0.5 size-5 shrink-0 text-error" />
                <p>{{ __('Eligible user accounts will be deleted permanently.') }}</p>
            </div>
        @endif
    @endif

    <x-slot:actions>
        <x-button :label="__('Cancel')" wire:click="cancelBulkAction" spinner class="btn-ghost" />
        <x-button :label="$confirmationLabel" wire:click="applyBulkAction" spinner :class="$bulkAction === 'delete' ? 'btn-error' : 'btn-primary'" />
    </x-slot:actions>
</x-modal>

@include('livewire.user._bulk-results')
