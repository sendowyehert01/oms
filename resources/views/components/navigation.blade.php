<header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-8 gap-y-3 px-4 py-4 sm:px-6 lg:px-8">
        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="flex items-center gap-3 rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-600">
            <span class="flex size-10 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold tracking-wide text-white" aria-hidden="true">OM</span>
            <span class="text-sm font-semibold sm:text-base">Order Management System</span>
        </a>

        @auth
        <nav aria-label="Main navigation" class="flex flex-wrap gap-2">
            <a
                href="{{ route('dashboard') }}"
                @if (request()->routeIs('dashboard')) aria-current="page" @endif
                @class([
                    'inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('dashboard'),
                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' => ! request()->routeIs('dashboard'),
                ])
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" />
                </svg>
                Dashboard
            </a>
            <a
                href="{{ route('orders.index') }}"
                @if (request()->routeIs('orders.*')) aria-current="page" @endif
                @class([
                    'inline-flex min-h-11 items-center gap-2 rounded-lg px-4 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300' => request()->routeIs('orders.*'),
                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' => ! request()->routeIs('orders.*'),
                ])
            >
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" />
                </svg>
                Orders
            </a>
            @can('manage-staff')
                <a href="{{ route('staff.index') }}" @if (request()->routeIs('staff.*')) aria-current="page" @endif class="inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-medium hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:hover:bg-slate-800">Staff accounts</a>
            @endcan
        </nav>
        <div class="flex flex-wrap items-center gap-3 text-sm md:ml-auto">
            <span class="max-w-48 truncate font-medium">{{ auth()->user()->name }}</span>
            <a href="{{ route('account.password') }}" class="inline-flex min-h-11 items-center rounded-lg px-3 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:hover:bg-slate-800">Change password</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="min-h-11 rounded-lg border border-slate-300 px-4 font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Log out</button>
            </form>
        </div>
        @endauth
    </div>
</header>
