@props(['data', 'user', 'expanded' => false])
<section aria-labelledby="balances-heading" class="oms-surface mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 id="balances-heading" class="text-lg font-semibold">Outstanding balances</h2>@unless ($expanded)<a href="{{ route('balances.index') }}" class="py-2 text-sm font-semibold text-brand-700 dark:text-brand-300">View all balances</a>@endunless</div>
    <p class="mt-2 text-sm text-slate-500">{{ $user->isAdmin() ? 'Balances across all orders.' : 'Balances for orders you can access. These are not global customer balances.' }}</p>
    <dl class="my-5 grid gap-4 sm:grid-cols-3">
        <div><dt class="text-sm text-slate-500">Outstanding (PHP)</dt><dd class="mt-1 break-words text-2xl font-semibold tabular-nums"><x-money :cents="$data['outstanding_in_cents']" /></dd></div>
        <div><dt class="text-sm text-slate-500">Orders with a balance</dt><dd class="mt-1 text-2xl font-semibold">{{ $data['orders_count'] }}</dd></div>
        <div><dt class="text-sm text-slate-500">Overdue orders</dt><dd class="mt-1 text-2xl font-semibold text-rose-700 dark:text-rose-300">{{ $data['overdue_count'] }}</dd></div>
    </dl>
    @if ($data['orders_count'] === 0)
        <p class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500 dark:bg-slate-950">No outstanding balances in this view.</p>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <section aria-label="Customer balance totals">
                <h3 class="mb-3 font-semibold">Customers to follow up</h3>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($data['customers'] as $customer)
                        <li class="py-3"><a href="{{ route('balances.index', ['customer_id' => $customer->id]) }}" class="block break-words font-semibold text-brand-700 dark:text-brand-300">{{ $customer->company_name }}</a><p class="mt-1 text-sm"><x-money :cents="$customer->outstanding_in_cents" /> PHP · {{ $customer->unpaid_orders_count }} unpaid orders</p>@if ($customer->overdue_orders_count > 0)<p class="mt-1 text-xs font-semibold text-rose-700 dark:text-rose-300">{{ $customer->overdue_orders_count }} overdue orders</p>@endif</li>
                    @endforeach
                </ul>
                @if ($expanded && $data['customers']->hasPages())<div class="mt-4">{{ $data['customers']->links() }}</div>@endif
            </section>
            <section aria-label="Orders with outstanding balances">
                <h3 class="mb-3 font-semibold">Unpaid and partially paid orders</h3>
                <ol class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($data['orders'] as $order)
                        <li class="py-3">
                            <a href="{{ route('orders.show', $order) }}" class="block break-words font-semibold text-brand-700 dark:text-brand-300">{{ app(\App\OrderEntries::class)->number($order->id, $order->order_date->toDateString()) }} · {{ $order->customer->company_name }}</a>
                            <p class="mt-1 text-sm">Remaining: <x-money :cents="$order->balance_in_cents" /> PHP</p>
                            <div class="mt-2"><x-order-status :status="$order->status" /></div>
                            <p class="mt-2 text-xs {{ $order->payment_due_date && $order->payment_due_date->toDateString() < $data['today'] ? 'font-semibold text-rose-700 dark:text-rose-300' : 'text-slate-500' }}">
                                @if (!$order->payment_due_date) No payment due date
                                @elseif ($order->payment_due_date->toDateString() < $data['today']) Overdue · due {{ $order->payment_due_date->format('M j, Y') }}
                                @elseif ($order->payment_due_date->toDateString() === $data['today']) Due today
                                @else Due {{ $order->payment_due_date->format('M j, Y') }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ol>
                @if ($expanded && $data['orders']->hasPages())<div class="mt-4">{{ $data['orders']->links() }}</div>@endif
            </section>
        </div>
    @endif
    <p class="mt-4 text-xs text-slate-500">Overdue means a positive balance with a payment due date before today in {{ config('app.reporting_timezone') }}. Payment terms are not parsed into dates.</p>
</section>
