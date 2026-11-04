@props(['order', 'summary'])
@php
    $today = now(config('app.reporting_timezone'))->toDateString();
    $overdue = $summary['balance_in_cents'] > 0 && $order->payment_due_date && $order->payment_due_date->toDateString() < $today;
@endphp
<section aria-labelledby="payment-due-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 id="payment-due-heading" class="text-lg font-semibold">Payment due date</h2>
    <p class="mt-2 text-sm">{{ $order->payment_due_date?->format('M j, Y') ?? 'No payment due date recorded' }}</p>
    @if ($overdue)<p class="mt-2 font-semibold text-rose-700 dark:text-rose-300">Payment overdue — outstanding balance remains.</p>
    @elseif ($summary['balance_in_cents'] > 0 && $order->payment_due_date?->toDateString() === $today)<p class="mt-2 text-sm font-semibold">Payment due today</p>@endif
    @can('setPaymentDueDate', $order)
        <details class="mt-4"><summary class="cursor-pointer py-2 font-semibold text-brand-700 dark:text-brand-300">Edit payment due date</summary>
            <form method="POST" action="{{ route('orders.payment-due-date.update', $order) }}" data-busy-form="Saving due date..." class="mt-3 space-y-4">
                @csrf
                <input type="hidden" name="submission_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                <x-form-input name="payment_due_date" id="payment-due-date-edit" type="date" label="Payment due date" :required="false" :value="old('payment_due_date', $order->payment_due_date?->toDateString())" :min="$order->order_date->toDateString()" />
                <p class="text-xs text-slate-500">Leave empty if no date is agreed. This does not change text payment terms or delivery scheduling.</p>
                <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Save payment due date</button>
            </form>
        </details>
    @endcan
</section>
