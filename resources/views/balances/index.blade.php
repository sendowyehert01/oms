<x-app-layout title="Balances">
    <x-page-heading title="Outstanding balances" eyebrow="Payments" description="Follow up customer balances and overdue payments from orders you can access." />
    <x-form-errors message="Please check your balance filters:" />
    <form method="GET" action="{{ route('balances.index') }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
        <x-form-input name="search" label="Customer name" :required="false" :value="$filters['search'] ?? ''" maxlength="255" />
        @if (isset($filters['customer_id']))<input type="hidden" name="customer_id" value="{{ $filters['customer_id'] }}">@endif
        <div><label for="due" class="mb-2 block text-sm font-medium">Payment due</label><select id="due" name="due" class="oms-input min-h-12 w-full"><option value="">All outstanding</option><option value="overdue" @selected(($filters['due'] ?? '') === 'overdue')>Overdue</option><option value="today" @selected(($filters['due'] ?? '') === 'today')>Due today</option><option value="unscheduled" @selected(($filters['due'] ?? '') === 'unscheduled')>No due date</option></select></div>
        <div class="flex flex-wrap items-end gap-3"><button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Apply filters</button><a href="{{ route('balances.index') }}" class="rounded-lg border border-slate-300 px-4 py-3 text-sm">Clear</a></div>
    </form>
    <p class="mt-5 text-sm text-slate-500">Totals reflect the current filters. Customer and order lists each show up to 10 results per page.</p>
    <x-outstanding-balances-panel :data="$data" :user="$user" :expanded="true" />
</x-app-layout>
