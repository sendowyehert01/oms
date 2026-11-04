@props(['title'])

<!DOCTYPE html>
<html lang="en" data-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{{ $title }} · OMS</title>
        <meta name="theme-color" content="#a70403">
        <link rel="icon" href="{{ asset('oms-logo.png') }}" type="image/png">
        <script>
            try { document.documentElement.dataset.theme = localStorage.getItem('oms.theme') === 'dark' ? 'dark' : 'light'; } catch {}
        </script>

        @vite('resources/css/app.css')
        @vite('resources/js/workspace.js')
        @if (request()->routeIs('orders.creation.details'))
            @vite('resources/js/create-order.js')
        @endif
        @if (request()->routeIs('orders.payments.create'))
            @vite('resources/js/record-payment.js')
        @endif
        @if (request()->routeIs('orders.index'))
            <meta name="csrf-token" content="{{ csrf_token() }}">
            @vite('resources/js/delete-order.js')
        @endif
        @if (request()->routeIs('orders.index', 'orders.show', 'orders.samples.show'))
            @vite('resources/js/order-navigation.js')
        @endif
    </head>
    <body data-layout="{{ request()->routeIs('login') ? 'auth' : (auth()->check() ? 'workspace' : 'guest') }}" class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        <a href="#main-content" class="sr-only rounded-lg bg-brand-700 p-3 text-white focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50">
            Skip to content
        </a>

        @unless (request()->routeIs('login'))<x-navigation :title="$title" />@endunless

        <main id="main-content" tabindex="-1" @class(['auth-main' => request()->routeIs('login'), 'workspace-main' => auth()->check(), 'guest-main' => ! auth()->check() && ! request()->routeIs('login')])>
            <div @class(['workspace-content page-enter' => ! request()->routeIs('login')])>{{ $slot }}</div>
        </main>

        @auth
            <footer class="workspace-footer"><span>OMS · Your daily workspace</span><span>Clients. Orders. Payments.</span></footer>
        @endauth

        @if (request()->routeIs('orders.index', 'orders.show', 'orders.samples.show'))
            <div data-order-loading role="status" aria-live="polite" hidden class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/30 p-4">
                <div class="flex items-center gap-3 rounded-xl bg-white p-6 text-sm font-semibold shadow-lg dark:bg-slate-900">
                    <svg class="size-5 animate-spin motion-reduce:animate-none text-brand-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" /><path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                    Loading order details...
                </div>
            </div>
        @endif
    </body>
</html>
