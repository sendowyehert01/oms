<x-app-layout title="Confirm password">
    <section class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
        <h1 class="mb-6 text-2xl font-semibold">Confirm your password</h1>
        <x-form-errors />
        <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
            @csrf
            <x-form-input name="password" label="Password" type="password" autocomplete="current-password" />
            <button type="submit" class="min-h-11 rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-indigo-600">Confirm password</button>
        </form>
    </section>
</x-app-layout>
