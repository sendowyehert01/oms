@props(['title'])

@auth
    <header class="workspace-topbar">
        <div class="flex min-w-0 items-center gap-3">
            <button type="button" data-menu-toggle hidden aria-label="Toggle navigation" aria-controls="workspace-navigation" aria-expanded="false" class="icon-button mobile-menu-toggle">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" /></svg>
            </button>
            <div><p class="topbar-label">Your workspace</p><p class="topbar-title truncate">{{ $title }}</p></div>
        </div>
        <div class="topbar-actions">
            <x-theme-toggle />
            <details class="account-menu">
                <summary aria-label="Account menu for {{ auth()->user()->name }}">
                    <span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="account-name"><span class="block max-w-40 truncate text-xs font-semibold">{{ auth()->user()->name }}</span><span class="mt-1 block text-[10px] text-slate-500 dark:text-slate-400">{{ auth()->user()->isAdmin() ? 'Administrator' : 'Staff member' }}</span></span>
                    <svg class="size-3 text-slate-400" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </summary>
                <div class="account-popover">
                    <p class="text-xs font-semibold">Signed in as</p>
                    <p class="mt-1 mb-4 break-words text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                    <a href="{{ route('account.password') }}">Change password</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="font-semibold text-brand-700 dark:text-brand-300">Log out</button></form>
                </div>
            </details>
        </div>
    </header>
    <aside id="workspace-navigation" class="workspace-navigation">
        <a href="{{ route('dashboard') }}" aria-label="OMS dashboard"><x-brand-logo /></a>
        <p class="navigation-caption">Workspace</p>
        <nav aria-label="Main navigation">
            <a href="{{ route('dashboard') }}" @if (request()->routeIs('dashboard')) aria-current="page" @endif class="workspace-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z" stroke-linejoin="round" /></svg>
                Dashboard
            </a>
            <a href="{{ route('production.index') }}" @if (request()->routeIs('production.*')) aria-current="page" @endif class="workspace-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 4h18v16H3zM9 4v16m6-16v16" /></svg>
                Production
            </a>
            <a href="{{ route('orders.index') }}" @if (request()->routeIs('orders.*')) aria-current="page" @endif class="workspace-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4m-9 4v10" stroke-linejoin="round" /></svg>
                Orders
            </a>
            <a href="{{ route('balances.index') }}" @if (request()->routeIs('balances.*')) aria-current="page" @endif class="workspace-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 5h18v14H3zM3 9h18" /><circle cx="12" cy="14" r="2" /></svg>
                Balances
            </a>
            @can('manage-staff')
                <a href="{{ route('staff.index') }}" @if (request()->routeIs('staff.*')) aria-current="page" @endif class="workspace-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2m1-15a3 3 0 0 1 0 6m3 9v-2a6 6 0 0 0-3-5" stroke-linecap="round" /></svg>
                    Staff accounts
                </a>
            @endcan
        </nav>
        <div class="sidebar-note"><span class="text-gold-400" aria-hidden="true">✦</span><p><strong>One workspace.<br>Every order.</strong></p><p>Keep your clients, orders, and payments connected.</p></div>
    </aside>
@else
    <header class="guest-navigation"><a href="{{ route('login') }}" aria-label="OMS login"><x-brand-logo /></a></header>
@endauth
