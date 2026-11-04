<x-app-layout title="Welcome">
    <section aria-labelledby="welcome-heading" class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10 dark:border-slate-800 dark:bg-slate-900">
        <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400">OMS</p>
        <h1 id="welcome-heading" class="mt-3 text-3xl font-semibold tracking-tight">Order Management System</h1>
        <p class="mt-4 text-slate-600 dark:text-slate-400">Manage client orders and track their progress.</p>
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Open dashboard</a>
            <a href="{{ route('orders.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">View orders</a>
        </div>
    </section>
</x-app-layout>
