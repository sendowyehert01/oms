@props(['orders', 'search' => ''])

<div class="overflow-x-auto" role="region" aria-label="Orders table" tabindex="0">
    <table @class(['w-full text-left text-sm', 'min-w-190' => $orders->count() > 0])>
        <caption class="sr-only">Orders with customer names, dates, statuses, and total amounts in Philippine pesos</caption>
        @if ($orders->count() > 0)
            <thead class="border-b border-slate-200 bg-slate-50/80 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                <tr>
                    <th scope="col" class="px-6 py-4">Order number</th>
                    <th scope="col" class="px-6 py-4">Customer name</th>
                    <th scope="col" class="px-6 py-4">Order date</th>
                    <th scope="col" class="px-6 py-4">Order status</th>
                    <th scope="col" class="px-6 py-4 text-right">Total amount <span class="font-normal">(PHP)</span></th>
                </tr>
            </thead>
        @endif
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($orders as $order)
                <tr class="transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <th scope="row" class="px-6 py-5 font-semibold whitespace-nowrap text-indigo-700 dark:text-indigo-300">{{ $order['number'] }}</th>
                    <td class="px-6 py-5 font-medium whitespace-nowrap">{{ $order['customer_name'] }}</td>
                    <td class="px-6 py-5 whitespace-nowrap text-slate-500 dark:text-slate-400">
                        <time datetime="{{ $order['order_date'] }}">{{ \Illuminate\Support\Carbon::parse($order['order_date'])->format('M j, Y') }}</time>
                    </td>
                    <td class="px-6 py-5 whitespace-nowrap">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $order['status'] === 'Pending',
                            'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => $order['status'] === 'In production',
                            'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' => $order['status'] === 'Ready for delivery',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $order['status'] === 'Completed',
                        ])>
                            <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                            {{ $order['status'] }}
                        </span>
                    </td>
                    <td class="px-6 py-5 text-right font-medium whitespace-nowrap tabular-nums">{{ number_format($order['total_amount_in_cents'] / 100, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-16 text-center">
                        <svg class="mx-auto mb-4 size-10 text-slate-300 dark:text-slate-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <circle cx="10.5" cy="10.5" r="6.5" />
                            <path stroke-linecap="round" d="m16 16 4 4" />
                        </svg>
                        <p class="font-semibold">{{ $search !== '' ? 'No matching orders' : 'No orders yet' }}</p>
                        @if ($search !== '')
                            <p class="mt-2 text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400">No orders match “{{ $search }}”. Try another order number or customer name.</p>
                            <a href="{{ route('orders.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-lg px-3 font-medium text-indigo-700 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-indigo-300">View all orders</a>
                        @else
                            <p class="mt-2 text-slate-500 dark:text-slate-400">Orders will appear here when they are available.</p>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
