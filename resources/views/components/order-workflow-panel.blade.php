@props(['order', 'history'])

<section aria-labelledby="production-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="production-heading" class="text-lg font-semibold">Production progress</h2>
        <x-order-status :status="$order->status" />
    </div>
    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Production may proceed without payment. Delivery and payment settlement are tracked separately.</p>
    @can('updateStatus', $order)
        @if (in_array($order->status, ['Pending', 'In production'], true))
            <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Saving progress..." class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                <input type="hidden" name="action" value="{{ $order->status === 'Pending' ? 'start_production' : 'mark_ready' }}">
                <x-form-textarea name="notes" id="workflow-notes" label="Production notes" :value="old('notes')" :required="false" maxlength="2000" />
                @if ($order->status === 'In production')
                    <label class="flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" name="quality_checked" value="1" required class="mt-1 size-4 shrink-0 accent-brand-600">All items are finished and quality checked.</label>
                @endif
                @if ($order->items->isEmpty())<p class="text-sm text-slate-500 dark:text-slate-400">No items are recorded for this order.</p>@endif
                <button type="submit" @disabled($order->items->isEmpty()) class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50">{{ $order->status === 'Pending' ? 'Start production' : 'Mark ready for delivery' }}</button>
            </form>
        @elseif ($order->status === 'Ready for delivery')
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">Production is finished. Delivery has not been confirmed.</p>
        @endif
        @if ($order->status !== 'Pending')
            <details class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                <summary class="py-2 text-sm font-semibold text-brand-700 dark:text-brand-300">Correct status / reopen order</summary>
                <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Reopening..." class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="reopen">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Return this order to Pending for correction. Recorded payments and previous history are retained.</p>
                    <x-form-textarea name="notes" id="reopen-reason" label="Correction reason" :value="old('action') === 'reopen' ? old('notes') : ''" :required="true" maxlength="2000" />
                    <button type="submit" class="min-h-11 rounded-lg border border-brand-200 px-5 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Reopen as Pending</button>
                </form>
            </details>
        @endif
    @else
        <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">Only administrators can update availability and production progress.</p>
    @endcan
</section>

<section aria-labelledby="workflow-history-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 id="workflow-history-heading" class="text-lg font-semibold">Status history</h2>
    <ol class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($history as $entry)
            <li class="py-4 first:pt-0">
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
