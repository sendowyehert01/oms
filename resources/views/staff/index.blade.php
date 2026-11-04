<x-app-layout title="Staff accounts">
    <x-page-heading title="Staff accounts" eyebrow="Team management" description="Create an account, then share its email and initial password with your staff member. They can change their password after signing in." />
    @if (session('success'))
        <p role="status" class="mt-6 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</p>
    @endif
    <div class="mt-8 grid items-start gap-6 xl:grid-cols-[minmax(280px,1fr)_minmax(0,2fr)]">
        <section aria-labelledby="create-staff-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="create-staff-heading" class="mb-6 text-xl font-semibold">Create staff account</h2>
            <x-form-errors />
            <form method="POST" action="{{ route('staff.store') }}" data-busy-form="Creating account..." class="space-y-5">
                @csrf
                <x-form-input name="name" label="Name" :value="old('name')" autocomplete="name" maxlength="255" />
                <x-form-input name="email" label="Email" type="email" :value="old('email')" autocomplete="off" maxlength="255" />
                <x-form-input name="password" label="Initial password" type="password" autocomplete="new-password" minlength="12" maxlength="72" />
                <p class="text-sm text-slate-500 dark:text-slate-400">Use 12 to 72 characters.</p>
                <x-form-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />
                <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Create account</button>
            </form>
        </section>
        <section aria-labelledby="accounts-heading" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="accounts-heading" class="text-xl font-semibold">Accounts ({{ number_format($users->total()) }})</h2>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">OMS admin and staff accounts</caption>
                    <thead><tr class="border-b border-slate-200 dark:border-slate-700"><th scope="col" class="pb-3 pr-4">Name</th><th scope="col" class="pb-3 pr-4">Email</th><th scope="col" class="pb-3">Role</th></tr></thead>
                    <tbody>
                        @foreach ($users as $account)
                            <tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-4 pr-4 font-semibold">{{ $account->name }}</td><td class="py-4 pr-4">{{ $account->email }}</td><td class="py-4"><span @class(['rounded-full px-3 py-1 text-xs font-semibold', 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-200' => $account->isAdmin(), 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' => ! $account->isAdmin()])>{{ $account->isAdmin() ? 'Admin' : 'Staff' }}</span></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $users->links() }}</div>
        </section>
    </div>
</x-app-layout>
