@props(['search' => ''])

<form action="{{ route('orders.index') }}" method="GET" role="search">
    <label for="order-search" class="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Search orders</label>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div class="relative flex-1 sm:max-w-md">
            <svg class="pointer-events-none absolute top-3.5 left-3.5 size-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <circle cx="10.5" cy="10.5" r="6.5" />
                <path stroke-linecap="round" d="m16 16 4 4" />
            </svg>
            <input
                id="order-search"
                type="search"
                name="search"
                value="{{ $search }}"
                placeholder="Order number or customer name"
                class="min-h-12 w-full rounded-lg border border-slate-300 bg-white py-3 pr-3 pl-11 text-sm placeholder:text-slate-400 focus:border-indigo-600 focus:outline-2 focus:outline-indigo-600 dark:border-slate-700 dark:bg-slate-950"
            >
        </div>
        <button type="submit" class="inline-flex min-h-12 items-center justify-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
            Search
        </button>
        @if ($search !== '')
            <a href="{{ route('orders.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-lg px-3 text-sm font-medium text-slate-600 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-slate-300 dark:hover:text-indigo-300">
                Clear search
            </a>
        @endif
    </div>
</form>
