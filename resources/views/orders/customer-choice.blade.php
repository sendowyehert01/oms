<x-app-layout title="Create Order">
    <x-page-heading title="Create Order" eyebrow="Order management" description="Choose the customer first, then enter their order details." />

    <x-order-creation-progress :step="1" />
    <x-form-errors />

    <section aria-labelledby="customer-choice-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="customer-choice-heading" class="text-xl font-semibold">Is this an existing customer?</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <a href="{{ route('orders.creation.customer', ['type' => 'existing', 'draft' => $submissionKey]) }}" class="choice-card rounded-xl border border-slate-200 p-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700">
                <span class="choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2m2-13h5m-5 5h5m-5 5h5" stroke-linecap="round" /></svg></span>
                <span class="block text-lg font-semibold text-brand-700 dark:text-brand-300">Existing customer</span>
                <span class="mt-2 block text-sm text-slate-600 dark:text-slate-400">Select a customer already saved in the system.</span>
            </a>
            <a href="{{ route('orders.creation.customer', ['type' => 'new', 'draft' => $submissionKey]) }}" class="choice-card rounded-xl border border-slate-200 p-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700">
                <span class="choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2m3-14v6m-3-3h6" stroke-linecap="round" /></svg></span>
                <span class="block text-lg font-semibold text-brand-700 dark:text-brand-300">New customer</span>
                <span class="mt-2 block text-sm text-slate-600 dark:text-slate-400">Enter their contact information before creating the order.</span>
            </a>
        </div>
    </section>

    <form method="POST" action="{{ route('orders.creation.cancel') }}" class="mt-6 flex justify-end">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <button type="submit" class="min-h-11 rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</button>
    </form>
</x-app-layout>
