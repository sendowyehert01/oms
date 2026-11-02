<x-app-layout :title="$type === 'existing' ? 'Select Customer' : 'New Customer'">
    <div class="mb-8">
        <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Create order</p>
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">{{ $type === 'existing' ? 'Select an existing customer' : 'New customer information' }}</h1>
        <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">{{ $type === 'existing' ? 'Find the customer and select their record to continue.' : 'Complete the customer information first. Fields marked * are required.' }}</p>
    </div>

    <x-order-creation-progress :step="2" />
    <x-form-errors />

    @if ($type === 'existing')
        <form method="GET" action="{{ route('orders.creation.customer', ['type' => 'existing']) }}" class="mb-6 flex flex-wrap items-end gap-3">
            <input type="hidden" name="draft" value="{{ $submissionKey }}">
            <div class="min-w-0 flex-1">
                <x-form-input name="search" label="Search customers" :value="$search" :required="false" maxlength="255" placeholder="Customer name, contact person, or phone" />
            </div>
            <button type="submit" class="min-h-11 rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Search</button>
        </form>
    @endif

    <form method="POST" action="{{ route('orders.creation.select-customer') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <input type="hidden" name="customer_type" value="{{ $type }}">

        @if ($type === 'new')
            <section aria-labelledby="customer-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 id="customer-heading" class="mb-5 text-lg font-semibold">Customer information</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form-input name="customer_name" label="Customer name" :value="$customerInput['customer_name'] ?? ''" maxlength="255" autocomplete="organization" />
                    <x-form-input name="contact_person" label="Contact person" :value="$customerInput['contact_person'] ?? ''" maxlength="255" autocomplete="name" />
                    <x-form-input name="address" label="Address" :value="$customerInput['address'] ?? ''" maxlength="2000" autocomplete="street-address" />
                    <x-form-input name="contact_number" label="Contact number" type="tel" :value="$customerInput['contact_number'] ?? ''" maxlength="30" autocomplete="tel" />
                </div>
            </section>
        @else
            @php
                $customerOptions = $customers?->getCollection() ?? collect();
                if ($selectedCustomer !== null && ! $customerOptions->contains('id', $selectedCustomer->id)) {
                    $customerOptions = $customerOptions->prepend($selectedCustomer);
                }
            @endphp
            @if ($customerLoadError)
                <p role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200">Customers could not be loaded. Please try searching again.</p>
            @elseif ($customers->isEmpty())
                <p class="rounded-xl border border-slate-200 bg-white p-6 text-sm dark:border-slate-800 dark:bg-slate-900">{{ $search === '' ? 'No customers have been saved yet.' : 'No customers match your search.' }} You can go back and choose New customer.</p>
            @endif
            @if ($customerOptions->isNotEmpty() && ! $customerLoadError)
                <fieldset class="space-y-3">
                    <legend class="mb-3 text-sm font-semibold">Select a customer</legend>
                    @foreach ($customerOptions as $customer)
                        <label for="customer-{{ $customer->id }}" class="flex cursor-pointer items-start gap-4 rounded-xl border border-slate-200 bg-white p-4 has-checked:border-indigo-500 has-checked:bg-indigo-50 dark:border-slate-800 dark:bg-slate-900 dark:has-checked:bg-indigo-950">
                            <input id="customer-{{ $customer->id }}" type="radio" name="customer_id" value="{{ $customer->id }}" required @checked($selectedCustomer?->id === $customer->id) class="mt-1 size-4 shrink-0 accent-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            <span class="min-w-0 break-words">
                                <span class="block font-semibold">{{ $customer->company_name }}</span>
                                <span class="mt-1 block text-sm text-slate-600 dark:text-slate-400">{{ $customer->contact_person }} · {{ $customer->contact_number }}</span>
                                <span class="mt-1 block text-sm text-slate-500 dark:text-slate-400">{{ $customer->address }} · Customer #{{ $customer->id }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
                {{ $customers->links() }}
            @endif
        @endif

        <div class="flex flex-wrap justify-end gap-3">
            <button type="submit" @disabled($type === 'existing' && ($customerLoadError || $customerOptions->isEmpty())) class="min-h-11 rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50">Continue to order details</button>
            <button type="submit" formnovalidate formaction="{{ route('orders.creation.back') }}" class="min-h-11 rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to customer choice</button>
            <button type="submit" formnovalidate formaction="{{ route('orders.creation.cancel') }}" class="min-h-11 rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</button>
        </div>
    </form>
</x-app-layout>
