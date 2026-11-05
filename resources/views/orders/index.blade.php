<x-app-layout title="Orders">
    <x-page-heading title="Orders" eyebrow="Order management" description="Keep track of your customers and orders in one place.">
        <span class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300">Saved orders</span>
        <a href="{{ route('orders.create') }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Create Order</a>
    </x-page-heading>

    <div data-order-success role="status" @if (! session('success')) hidden @endif class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</div>
    <div data-order-error role="alert" @if (! session('error')) hidden @endif class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">{{ session('error') }}</div>
    @if (auth()->user()->isAdmin())
        <noscript><p class="mb-6 text-sm text-slate-600 dark:text-slate-400">Enable JavaScript to confirm and delete orders.</p></noscript>
    @else
        <p class="mb-6 text-sm text-slate-600 dark:text-slate-400">Showing orders you created and all orders for clients you created. You can select any existing client when creating an order.</p>
    @endif

    @fragment('orders-list')
    <div data-orders-list data-list-url="{{ route('orders.index', ['search' => $search, 'page' => $orders->currentPage()]) }}">
        <section aria-label="Orders list" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-200 p-4 sm:p-6 dark:border-slate-800">
                <x-orders-search :search="$search" />
            </div>

            <x-orders-table :orders="$orders" :search="$search" />

            <x-pagination :paginator="$orders" />
        </section>
    </div>
    @endfragment

    @if (auth()->user()->isAdmin())
        <x-confirmation-dialog id="delete-order-dialog" title="Delete order" confirm-label="Confirm Delete">
            <p data-delete-message></p>
        </x-confirmation-dialog>
    @endif
</x-app-layout>
