<x-app-layout title="Orders">
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Order management</p>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Orders</h1>
            <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Keep track of your customers’ orders in one place.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">
                {{ $isSampleData ? 'Sample orders' : 'Saved orders' }}
            </span>
            <a href="{{ route('orders.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Create Order</a>
        </div>
    </div>

    @if (session('success'))
        <div role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    <section aria-label="Orders list" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-200 p-4 sm:p-6 dark:border-slate-800">
            <x-orders-search :search="$search" />
        </div>

        <x-orders-table :orders="$orders" :search="$search" />

        <x-pagination :paginator="$orders" />
    </section>
</x-app-layout>
