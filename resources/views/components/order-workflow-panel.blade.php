@props(['order', 'history'])

<section aria-labelledby="production-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="production-heading" class="scroll-mt-24 text-lg font-semibold">Production progress</h2>
        <x-order-status :status="$order->status" />
    </div>
    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Production may proceed without payment. Delivery and payment settlement are tracked separately.</p>
    @can('updateStatus', $order)
        @if ($order->status !== 'Pending')
            <details class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                <summary class="py-2 text-sm font-semibold text-brand-700 dark:text-brand-300">Correct status / reopen order</summary>
                <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Reopening..." class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="reopen">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Return this order and its items to Pending for correction. Finished-stock checks will be reset. Recorded payments and previous history are retained.</p>
                    <x-form-textarea name="notes" id="reopen-reason" label="Correction reason" :value="old('action') === 'reopen' ? old('notes') : ''" :required="true" maxlength="2000" />
                    <button type="submit" class="min-h-11 rounded-lg border border-brand-200 px-5 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Reopen as Pending</button>
                </form>
            </details>
        @endif
    @else
        <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">Only administrators can update availability and production progress.</p>
    @endcan
    <x-item-production-panel :order="$order" />
</section>

<section aria-labelledby="workflow-history-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
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
