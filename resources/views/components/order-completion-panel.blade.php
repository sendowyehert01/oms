@props(['order', 'summary', 'deliveryConfirmed'])

<section aria-labelledby="completion-heading">
    <h3 id="completion-heading" class="font-semibold">Payment and completion</h3>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Completion requires confirmed full delivery and no outstanding balance.</p>
    <p class="mt-4 text-sm">Outstanding balance: <strong><x-money :cents="$summary['balance_in_cents']" /></strong></p>
    @if ($summary['balance_in_cents'] > 0)
        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Record the remaining payment to enable completion.</p>
        @can('recordPayment', $order)
            <a href="{{ route('orders.payments.create', $order) }}" class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Record payment</a>
        @endcan
    @else
        <p class="mt-3 text-sm text-emerald-700 dark:text-emerald-300">Balance settled.</p>
    @endif
    @if (! $deliveryConfirmed)
        <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">{{ in_array($order->status, ['Delivered', 'Completed'], true) ? 'An administrator must review the previous delivery details before completion can be verified.' : 'Full delivery must be confirmed in step 2 before completing the order.' }}</p>
        <a href="#order-step-deliver" data-order-step-link class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-brand-700 underline underline-offset-4 dark:text-brand-300">View delivery step</a>
    @elseif ($order->status === 'Completed' && $summary['balance_in_cents'] === 0)
        <p class="mt-4 text-sm font-semibold text-emerald-700 dark:text-emerald-300">This order is complete. No further confirmations are needed.</p>
    @elseif ($order->status === 'Delivered' && $summary['balance_in_cents'] === 0)
        @can('updateStatus', $order)
            <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Completing order..." class="mt-4">
                @csrf
                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                <input type="hidden" name="action" value="complete">
                <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Mark order Completed</button>
            </form>
        @else
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">An administrator can now mark this order Completed.</p>
        @endcan
    @endif
</section>
