<x-app-layout title="Dashboard">
    <x-page-heading title="Dashboard" eyebrow="Overview" :description="$user->isAdmin() ? 'An overview of all clients and orders.' : 'Your clients, orders created, and daily activity.'">
        <a href="{{ route('orders.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Create order</a>
    </x-page-heading>

    @if ($user)
        <section aria-labelledby="account-heading" class="oms-surface flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex min-w-0 items-center gap-4">
                <span class="account-avatar size-12! text-lg!" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
                <div class="min-w-0">
                    <h2 id="account-heading" class="text-sm font-semibold text-slate-600 dark:text-slate-400">Your account</h2>
                    <p class="mt-2 text-lg font-semibold">{{ $user->name }}</p>
                    <p class="mt-1 break-words text-sm text-slate-600 dark:text-slate-400">{{ $user->email }}</p>
                </div>
            </div>
            <p class="rounded-full border border-brand-100 bg-brand-50 px-4 py-2 text-xs font-semibold text-brand-700 dark:border-brand-900 dark:bg-brand-950 dark:text-brand-200">{{ $user->isAdmin() ? 'Admin' : 'Staff' }}</p>
        </section>
    @endif

    <dl aria-label="Client and order totals" class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            'Total clients' => $metrics['clients']['total'],
            'Clients with orders' => $metrics['clients']['with_orders'],
            'Clients without orders' => $metrics['clients']['without_orders'],
            'Total orders' => $metrics['orders'],
        ] as $label => $count)
            <div class="metric-card oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <dt class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $label }}</dt>
                <dd class="mt-3 text-3xl font-semibold tabular-nums">{{ number_format($count) }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ $user->isAdmin() ? 'Clients are counted once, even when they have multiple orders. Totals include all saved records.' : 'Totals count clients and orders you created. Your clients may also have orders created by an admin; those orders are available on the Orders page.' }}</p>

    <div class="mt-8 grid items-start gap-6 xl:grid-cols-[minmax(0,.95fr)_minmax(0,1.05fr)]">
        <section aria-labelledby="order-status-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="order-status-heading" class="text-xl font-semibold">Order status</h2>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">The current status of {{ number_format($metrics['orders']) }} {{ $user->isAdmin() ? 'saved orders' : 'orders you created' }}.</p>
            <x-order-status-chart :statuses="$metrics['statuses']" :total="$metrics['orders']" />
        </section>
        <x-daily-performance-chart :performance="$performance" :compact="true" />
    </div>
    <x-dashboard-records :clients="$clients" :orders="$orders" :user="$user" />
</x-app-layout>
