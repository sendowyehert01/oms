<x-app-layout title="Log in">
    <section aria-labelledby="login-heading" class="mx-auto max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <h1 id="login-heading" class="text-2xl font-semibold">Log in to OMS</h1>
        <p class="mt-2 mb-6 text-sm text-slate-600 dark:text-slate-400">Use the account provided by your administrator.</p>
        <x-form-errors />
        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf
            <x-form-input name="email" label="Email" type="email" :value="old('email')" autocomplete="username" maxlength="255" autofocus />
            <x-form-input name="password" label="Password" type="password" autocomplete="current-password" maxlength="72" />
            <label class="flex min-h-11 items-center gap-3 text-sm">
                <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-slate-300 accent-indigo-600">
                Remember me on this device
            </label>
            <button type="submit" class="min-h-11 w-full rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Log in</button>
        </form>
    </section>
</x-app-layout>
