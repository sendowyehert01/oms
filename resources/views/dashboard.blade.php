<x-app-layout title="Dashboard">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-semibold tracking-tight">Dashboard</h1>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">An overview of your clients and orders.</p>
        </div>
        <a href="{{ route('orders.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Create order</a>
    </div>

    @if ($user)
        <section aria-labelledby="account-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="account-heading" class="text-sm font-semibold text-slate-600 dark:text-slate-400">Your account</h2>
            <p class="mt-2 text-lg font-semibold">{{ $user->name }}</p>
            <p class="mt-1 break-words text-sm text-slate-600 dark:text-slate-400">{{ $user->email }}</p>
        </section>
    @endif

    <dl aria-label="Client and order totals" class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'Total clients' => $metrics['clients']['total'],
            'Clients with orders' => $metrics['clients']['with_orders'],
            'Clients without orders' => $metrics['clients']['without_orders'],
            'Total orders' => $metrics['orders'],
        ] as $label => $count)
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dt class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $label }}</dt>
                <dd class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($count) }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Clients are counted once, even when they have multiple orders. Totals include saved records.</p>

    <section aria-labelledby="order-status-heading" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="order-status-heading" class="text-xl font-semibold">Order status</h2>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">The current status of all {{ number_format($metrics['orders']) }} saved orders.</p>
        <x-order-status-chart :statuses="$metrics['statuses']" :total="$metrics['orders']" />
    </section>
</x-app-layout>
