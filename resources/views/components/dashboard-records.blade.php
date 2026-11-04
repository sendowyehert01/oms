@props(['clients', 'orders', 'user'])

<div class="mt-8 grid items-start gap-6 xl:grid-cols-2">
    <section aria-labelledby="created-clients-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="created-clients-heading" class="text-xl font-semibold">{{ $user->isAdmin() ? 'Clients' : 'Clients you created' }}</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Saved clients and their order counts</caption>
                <thead><tr><th scope="col" class="py-3 pr-3">Client</th><th scope="col" class="py-3 pr-3">Orders</th><th scope="col" class="py-3">Created</th></tr></thead>
                <tbody>
                    @forelse ($clients as $client)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <th scope="row" class="py-3 pr-3 font-medium">{{ $client->company_name }}<span class="mt-1 block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $client->contact_person }}</span></th>
                            <td class="py-3 pr-3 tabular-nums">{{ number_format($client->orders_count) }}</td>
                            <td class="py-3 whitespace-nowrap">{{ $client->created_at?->timezone(config('app.reporting_timezone'))->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-slate-500 dark:text-slate-400">No clients yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $clients->links() }}</div>
    </section>
    <section aria-labelledby="created-orders-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="created-orders-heading" class="text-xl font-semibold">{{ $user->isAdmin() ? 'Orders' : 'Orders you created' }}</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Saved orders with clients and statuses</caption>
                <thead><tr><th scope="col" class="py-3 pr-3">Order</th><th scope="col" class="py-3 pr-3">Client</th><th scope="col" class="py-3">Status</th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr class="border-t border-slate-100 dark:border-slate-800">
                            <th scope="row" class="py-3 pr-3 font-medium"><a href="{{ route('orders.show', ['order' => $order]) }}" class="inline-flex min-h-11 items-center text-brand-700 hover:underline dark:text-brand-300">{{ app(\App\OrderEntries::class)->number($order->id, $order->order_date->toDateString()) }}</a></th>
                            <td class="py-3 pr-3">{{ $order->customer->company_name }}</td>
                            <td class="py-3 whitespace-nowrap"><x-order-status :status="$order->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-slate-500 dark:text-slate-400">No orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $orders->links() }}</div>
        <a href="{{ route('orders.index') }}" class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-brand-700 hover:underline dark:text-brand-300">View orders</a>
    </section>
</div>
