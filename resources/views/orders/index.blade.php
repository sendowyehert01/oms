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

    <div data-order-success role="status" @if (! session('success')) hidden @endif class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</div>
    <div data-order-error role="alert" @if (! session('error')) hidden @endif class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">{{ session('error') }}</div>
    <noscript><p class="mb-6 text-sm text-slate-600 dark:text-slate-400">Enable JavaScript to confirm and delete orders.</p></noscript>

    @fragment('orders-list')
    <div data-orders-list data-list-url="{{ route('orders.index', ['search' => $search, 'page' => $orders->currentPage()]) }}">
        <section aria-label="Orders list" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 p-4 sm:p-6 dark:border-slate-800">
                <x-orders-search :search="$search" />
            </div>

            <x-orders-table :orders="$orders" :search="$search" :is-sample-data="$isSampleData" />

            <x-pagination :paginator="$orders" />
        </section>
    </div>
    @endfragment

    <x-confirmation-dialog id="delete-order-dialog" title="Delete order" confirm-label="Confirm Delete">
        <p data-delete-message></p>
    </x-confirmation-dialog>
</x-app-layout>
