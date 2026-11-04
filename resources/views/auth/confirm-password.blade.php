<x-app-layout title="Confirm password">
    <section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <p class="page-eyebrow">Account security</p>
        <h1 class="page-title">Confirm your password</h1>
        <p class="page-description mb-6">Enter your password to continue.</p>
        <x-form-errors />
        <form method="POST" action="{{ route('password.confirm.store') }}" data-busy-form="Confirming..." class="space-y-5">
            @csrf
            <x-form-input name="password" label="Password" type="password" autocomplete="current-password" />
            <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-brand-600">Confirm password</button>
        </form>
    </section>
</x-app-layout>
