@props(['order', 'history'])

<section data-order-history aria-labelledby="workflow-history-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 id="workflow-history-heading" class="text-lg font-semibold">Status history</h2>
    <ol class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($history as $entry)
            <li class="py-4 first:pt-0">
                <p class="mb-1 text-xs text-slate-500 dark:text-slate-400">{{ match ($entry->event_type) { 'availability_checked' => 'Finished stock checked', 'availability_reset' => 'Finished-stock check reset', 'item_production_changed' => 'Item production progress', 'readiness_reconfirmed' => 'Item readiness reconfirmed', 'item_reopened' => 'Item reopened', 'order_reopened' => 'Order reopened', 'order_created' => 'Order created', default => 'Order status changed' } }}</p>
                <p class="text-sm font-semibold">{{ $entry->item_name ? $entry->item_name.' · ' : '' }}{{ $entry->from_status ?? 'Created' }} → {{ $entry->to_status }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $entry->actor_name }} · <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</time>{{ $entry->event_type === 'order_reopened' ? ' · Order reopened' : '' }}</p>
                @if (filled($entry->notes))<p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600 dark:text-slate-400">{{ $entry->notes }}</p>@endif
            </li>
        @empty
            <li class="text-sm text-slate-600 dark:text-slate-400">No status changes are recorded. Historical production and delivery events have not been inferred.</li>
        @endforelse
    </ol>
    @if ($history->hasPages())<div class="mt-5">{{ $history->links() }}</div>@endif
</section>
