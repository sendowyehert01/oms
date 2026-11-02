@props(['title'])

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title }} · OMS</title>

        @vite('resources/css/app.css')
        @if (request()->routeIs('orders.creation.details'))
            @vite('resources/js/create-order.js')
        @endif
        @if (request()->routeIs('orders.index'))
            <meta name="csrf-token" content="{{ csrf_token() }}">
            @vite('resources/js/delete-order.js')
        @endif
        @if (request()->routeIs('orders.index', 'orders.show', 'orders.samples.show'))
            @vite('resources/js/order-navigation.js')
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <a href="#main-content" class="sr-only rounded-lg bg-indigo-700 p-3 text-white focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50">
            Skip to content
        </a>

        <x-navigation />

        <main id="main-content" class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
            {{ $slot }}
        </main>

        @if (request()->routeIs('orders.index', 'orders.show', 'orders.samples.show'))
            <div data-order-loading role="status" aria-live="polite" hidden class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/30 p-4">
                <div class="flex items-center gap-3 rounded-xl bg-white p-6 text-sm font-semibold shadow-lg dark:bg-slate-900">
                    <svg class="size-5 animate-spin motion-reduce:animate-none text-indigo-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    Loading order details...
                </div>
            </div>
        @endif
    </body>
</html>
