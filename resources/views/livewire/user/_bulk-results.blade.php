<x-modal wire:model="showBulkResults" :title="$resultTitle ?? __('Bulk action results')" box-class="max-w-[36rem]" separator persistent>
    @if($showBulkResults)
        @php
            $resultsWithMessages = array_filter($bulkResults, fn ($result) => $result['message'] !== null);
            $failureCount = count(array_filter($resultsWithMessages, fn ($result) => $result['failed']));
            $counts = [
                'success' => count($bulkResults) - count($resultsWithMessages),
                'skipped' => count($resultsWithMessages) - $failureCount,
                'failed' => $failureCount,
            ];
            $metrics = [
                [__('Succeeded'), 'o-check-circle', 'text-success', $counts['success']],
                [__('Skipped'), 'o-minus-circle', 'text-warning', $counts['skipped']],
                [__('Failed'), 'o-x-circle', 'text-error', $counts['failed']],
            ];
            $invitationLinks = array_filter($bulkResults, fn ($result) => $result['link'] !== null);
        @endphp

        <p class="sr-only" role="status">
            {{ __('Succeeded: :success · Skipped: :skipped · Failed: :failed', $counts) }}
        </p>
        <dl class="grid grid-cols-3 gap-2 sm:gap-3" aria-hidden="true">
            @foreach($metrics as [$label, $icon, $color, $count])
                <div class="rounded-lg border border-base-content/10 bg-base-200/40 px-2.5 py-3 sm:px-3.5">
                    <dt class="flex items-center gap-1.5 text-xs font-medium text-base-content/75 sm:text-sm">
                        <x-icon :name="$icon" :class="'size-4 shrink-0 '.($count > 0 ? $color : 'text-base-content/40')" />
                        {{ $label }}
                    </dt>
                    <dd class="mt-1.5 text-2xl font-semibold leading-none tabular-nums">{{ $count }}</dd>
                </div>
            @endforeach
        </dl>

        @if($invitationLinks !== [])
            <div class="mt-5 min-w-0">
                <x-copy-input
                    :label="__('Invitation links')"
                    :value="implode(PHP_EOL, array_map(fn ($result) => $result['email'].': '.$result['link'], $invitationLinks))"
                    multiline
                    :rows="min(count($invitationLinks) + 1, 8)"
                />
            </div>
        @endif

        @if($resultsWithMessages !== [])
            <ul class="mt-5 max-h-48 divide-y divide-base-content/10 overflow-auto rounded-lg border border-base-content/10 text-sm">
                @foreach($resultsWithMessages as $result)
                    <li wire:key="bulk-user-result-{{ $result['id'] }}" class="flex items-start gap-2.5 px-3 py-2.5">
                        <x-icon
                            :name="$result['failed'] ? 'o-x-circle' : 'o-minus-circle'"
                            :class="$result['failed'] ? 'mt-0.5 size-4 shrink-0 text-error' : 'mt-0.5 size-4 shrink-0 text-warning'"
                        />
                        <div class="min-w-0">
                            <p class="font-medium break-all">{{ filled($result['email']) ? $result['email'] : (isset($resultTitle) ? __('CSV row :number', ['number' => $result['id']]) : __('User # :id', ['id' => $result['id']])) }}</p>
                            <p class="text-base-content/70">{{ $result['message'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    <x-slot:actions>
        <x-button :label="__('Close')" wire:click="closeBulkResults" spinner class="btn-primary" />
    </x-slot:actions>
</x-modal>
