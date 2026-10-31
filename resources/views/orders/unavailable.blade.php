<x-app-layout :title="$title">
    <section aria-labelledby="error-heading" class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm sm:p-10 dark:border-slate-800 dark:bg-slate-900">
        <h1 id="error-heading" class="text-2xl font-semibold">{{ $title }}</h1>
        <p role="alert" class="mt-4 text-sm text-slate-600 dark:text-slate-400">{{ $message }}</p>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ $returnToOrdersUrl }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to Orders</a>
            @if ($retryUrl)
                <a href="{{ $retryUrl }}" data-view-order class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Try again</a>
            @endif
        </div>
    </section>
</x-app-layout>
