<x-app-layout title="Change password">
    <section aria-labelledby="password-heading" class="mx-auto max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <p class="page-eyebrow">Account settings</p>
        <h1 id="password-heading" class="page-title">Change password</h1>
        <p class="page-description mb-6">Keep your account secure with a password only you know.</p>
        @if (session('status') === \Laravel\Fortify\Fortify::PASSWORD_UPDATED)
            <p role="status" class="mb-6 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Your password has been updated.</p>
        @endif
        <x-form-errors error-bag="updatePassword" />
        <form method="POST" action="{{ route('user-password.update') }}" data-busy-form="Updating password..." class="space-y-5">
            @csrf
            @method('PUT')
            <x-form-input name="current_password" label="Current password" type="password" error-bag="updatePassword" autocomplete="current-password" />
            <x-form-input name="password" label="New password" type="password" error-bag="updatePassword" autocomplete="new-password" minlength="12" maxlength="72" />
            <p class="text-sm text-slate-500 dark:text-slate-400">Use 12 to 72 characters.</p>
            <x-form-input name="password_confirmation" label="Confirm new password" type="password" error-bag="updatePassword" autocomplete="new-password" />
            <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Update password</button>
        </form>
    </section>
</x-app-layout>
