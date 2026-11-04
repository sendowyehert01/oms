@props(['order', 'summary'])
@php
    $allReady = $order->items->isNotEmpty() && $order->items->every(fn ($item) => $item->isReadyForDelivery());
@endphp
<section aria-labelledby="delivery-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 id="delivery-heading" class="text-lg font-semibold">Delivery and completion</h2>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Completion requires confirmed full delivery and no outstanding balance.</p>
    @if ($order->delivery_confirmed_at)
        <p class="mt-4 font-semibold">Full delivery confirmed</p>
        <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Delivery date</dt><dd>{{ $order->delivery_date?->format('M j, Y') }}</dd></div>
            <div><dt class="text-slate-500">Received by</dt><dd class="break-words">{{ $order->delivery_recipient }}</dd></div>
            <div><dt class="text-slate-500">Confirmed by</dt><dd class="break-words">{{ $order->delivery_confirmed_by_name }}</dd></div>
            <div><dt class="text-slate-500">Confirmation time ({{ config('app.reporting_timezone') }})</dt><dd>{{ $order->delivery_confirmed_at->timezone(config('app.reporting_timezone'))->format('M j, Y g:i A') }}</dd></div>
        </dl>
        @if (filled($order->delivery_notes))<p class="mt-3 whitespace-pre-line break-words text-sm">{{ $order->delivery_notes }}</p>@endif
    @elseif (in_array($order->status, ['Delivered', 'Completed'], true))
        <p class="mt-4 text-sm text-slate-500">Historical delivery details are not recorded. The saved status has been preserved.</p>
    @endif
    <p class="mt-4 text-sm">Outstanding balance: <strong><x-money :cents="$summary['balance_in_cents']" /></strong></p>
    @can('updateStatus', $order)
        @if ($order->status === 'Ready for delivery' && $allReady)
            <details class="mt-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                <summary class="cursor-pointer py-2 font-semibold text-brand-700 dark:text-brand-300">Confirm full delivery</summary>
                <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Confirming delivery..." class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="deliver">
                    <x-form-input name="delivery_date" type="date" label="Delivery date" :value="old('delivery_date', now(config('app.reporting_timezone'))->toDateString())" :min="$order->order_date->toDateString()" :max="now(config('app.reporting_timezone'))->toDateString()" />
                    <x-form-input name="delivery_recipient" label="Recipient" :value="old('delivery_recipient')" maxlength="255" />
                    <x-form-textarea name="notes" id="delivery-notes" label="Delivery notes" :value="old('action') === 'deliver' ? old('notes') : ''" :required="false" maxlength="2000" />
                    <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Confirm delivery of all items</button>
                </form>
            </details>
        @elseif ($order->status === 'Ready for delivery')
            <p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Resolve outstanding item readiness checks before confirming delivery.</p>
        @endif
        @if ($order->status === 'Delivered' && $order->delivery_confirmed_at)
            @if ($summary['balance_in_cents'] === 0)
                <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Completing order..." class="mt-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Mark order Completed</button>
                </form>
            @else
                <p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Record the remaining payment to enable completion.</p>
            @endif
        @endif
    @endcan
</section>
