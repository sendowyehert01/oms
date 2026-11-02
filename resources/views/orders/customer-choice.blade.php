<x-app-layout title="Create Order">
    <div class="mb-8">
        <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Order management</p>
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Create Order</h1>
        <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Choose the customer first, then enter their order details.</p>
    </div>

    <x-order-creation-progress :step="1" />
    <x-form-errors />

    <section aria-labelledby="customer-choice-heading" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="customer-choice-heading" class="text-xl font-semibold">Is this an existing customer?</h2>
        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <a href="{{ route('orders.creation.customer', ['type' => 'existing', 'draft' => $submissionKey]) }}" class="rounded-xl border border-indigo-200 p-6 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-indigo-800 dark:hover:bg-indigo-950">
                <span class="block text-lg font-semibold text-indigo-700 dark:text-indigo-300">Existing customer</span>
                <span class="mt-2 block text-sm text-slate-600 dark:text-slate-400">Select a customer already saved in the system.</span>
            </a>
            <a href="{{ route('orders.creation.customer', ['type' => 'new', 'draft' => $submissionKey]) }}" class="rounded-xl border border-indigo-200 p-6 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-indigo-800 dark:hover:bg-indigo-950">
                <span class="block text-lg font-semibold text-indigo-700 dark:text-indigo-300">New customer</span>
                <span class="mt-2 block text-sm text-slate-600 dark:text-slate-400">Enter their contact information before creating the order.</span>
            </a>
        </div>
    </section>

    <form method="POST" action="{{ route('orders.creation.cancel') }}" class="mt-6 flex justify-end">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <button type="submit" class="min-h-11 rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</button>
    </form>
</x-app-layout>
