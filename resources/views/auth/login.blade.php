<x-app-layout title="Log in">
    <div class="auth-grid">
        <section class="auth-story" aria-label="Welcome to OMS">
            <x-brand-logo />
            <div class="auth-copy">
                <p class="auth-eyebrow">Your daily workspace</p>
                <h2 class="auth-title">Your team's work,<br><span>in one place.</span></h2>
                <p class="auth-description">From the first client conversation to the final delivery. Keep every order and payment moving with clarity.</p>
                <x-auth-artwork />
            </div>
            <p class="auth-story-footer">Made for the way your team works.</p>
        </section>
        <section aria-labelledby="login-heading" class="auth-form-side">
            <x-theme-toggle class="auth-theme" />
            <div class="auth-form-content page-enter">
                <x-brand-logo class="auth-mobile-brand" />
                <p class="page-eyebrow">Welcome back</p>
                <h1 id="login-heading">Log in to OMS</h1>
                <p class="page-description mb-6">Use the account provided by your administrator.</p>
                <x-form-errors />
                <form method="POST" action="{{ route('login.store') }}" data-busy-form="Logging in..." class="space-y-5">
                    @csrf
                    <x-form-input name="email" label="Email" type="email" :value="old('email')" autocomplete="username" maxlength="255" placeholder="you@example.com" autofocus />
                    <x-form-input name="password" label="Password" type="password" autocomplete="current-password" maxlength="72" placeholder="Enter your password" />
                    <label class="flex min-h-11 items-center gap-3 text-xs text-slate-600 dark:text-slate-400">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-slate-300 accent-brand-600">
                        Remember me on this device
                    </label>
                    <button type="submit" class="flex min-h-12 w-full items-center justify-center gap-3 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-wait disabled:opacity-60">Log in <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 12h16m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                </form>
                <div class="auth-divider"></div>
                <p class="auth-footnote"><span class="font-semibold text-slate-700 dark:text-slate-300">Need access?</span><br>Contact your administrator to get an account or reset your password.</p>
            </div>
        </section>
    </div>
</x-app-layout>
