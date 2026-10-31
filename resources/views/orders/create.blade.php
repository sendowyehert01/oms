<x-app-layout title="Create Order">
    <div class="mb-8">
        <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Order management</p>
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Create Order</h1>
        <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Choose a customer, add items, and create their order. Fields marked * are required.</p>
    </div>

    @if ($errors->any())
        <div role="alert" class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">
            <p class="font-semibold">Please correct the following before creating the order:</p>
            <ul class="mt-2 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('orders.store') }}" data-create-order class="space-y-6">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">

        <section aria-labelledby="customer-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="customer-heading" class="mb-5 text-lg font-semibold">Customer information</h2>
            <label for="customer_id" class="mb-2 block text-sm font-medium">Customer</label>
            <select id="customer_id" name="customer_id" data-customer-select class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:outline-2 focus:outline-indigo-500 dark:border-slate-700 dark:bg-slate-950" @if ($errors->has('customer_id')) aria-invalid="true" aria-describedby="customer-error" @endif>
                <option value="">Create a new customer</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected(old('customer_id') == $customer->id)>{{ $customer->company_name }}</option>
                @endforeach
            </select>
            @error('customer_id')<p id="customer-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror

            <fieldset data-new-customer class="mt-5 grid gap-4 sm:grid-cols-2">
                <legend class="sr-only">New customer details</legend>
                <x-form-input name="customer_name" label="Customer name" :value="old('customer_name')" maxlength="255" autocomplete="organization" />
                <x-form-input name="contact_person" label="Contact person" :value="old('contact_person')" maxlength="255" autocomplete="name" />
                <x-form-input name="address" label="Address" :value="old('address')" maxlength="2000" autocomplete="street-address" />
                <x-form-input name="contact_number" label="Contact number" type="tel" :value="old('contact_number')" maxlength="30" autocomplete="tel" />
            </fieldset>
        </section>

        <section aria-labelledby="order-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="order-heading" class="mb-5 text-lg font-semibold">Order information</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-form-input name="order_date" label="Order date" type="date" :value="old('order_date', now()->toDateString())" />
                <x-form-input name="due_date" label="Due date" type="date" :value="old('due_date')" />
                <div>
                    <label for="status" class="mb-2 block text-sm font-medium">Order status <span aria-hidden="true" class="text-rose-600">*</span></label>
                    <select id="status" name="status" required class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:outline-2 focus:outline-indigo-500 dark:border-slate-700 dark:bg-slate-950" @if ($errors->has('status')) aria-invalid="true" aria-describedby="status-error" @endif>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', 'Pending') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    @error('status')<p id="status-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section aria-labelledby="items-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 id="items-heading" class="text-lg font-semibold">Order items</h2>
                <button type="button" data-add-item class="inline-flex min-h-11 items-center rounded-lg border border-indigo-200 px-4 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-950">Add item</button>
            </div>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Add up to 100 items, with quantities from 1 to 100,000 and unit prices up to PHP 999,999.99.</p>
            @php
                $oldItems = old('items', [['product_name' => '', 'quantity' => 1, 'unit_price' => '']]);
                $items = is_array($oldItems) ? $oldItems : [];
            @endphp
            <div data-order-items class="space-y-4">
                @foreach ($items as $index => $item)
                    <x-order-item :index="$index" :item="$item" />
                @endforeach
            </div>
            <p data-items-error role="alert" class="mt-3 text-sm text-rose-600 dark:text-rose-400" hidden>Add at least one item to the order.</p>
            <template data-item-template><x-order-item index="__INDEX__" /></template>
            <div class="mt-6 flex items-center justify-between border-t border-slate-200 pt-5 dark:border-slate-800">
                <span class="font-semibold">Total amount (PHP)</span>
                <output data-order-total aria-live="polite" class="text-2xl font-semibold tabular-nums">0.00</output>
            </div>
        </section>

        <div class="flex flex-wrap justify-end gap-3">
            <a href="{{ route('orders.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</a>
            <button type="submit" data-submit-order class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-wait disabled:opacity-60">Create Order</button>
        </div>
    </form>
</x-app-layout>
