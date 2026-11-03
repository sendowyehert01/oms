<x-app-layout title="Record Payment">
    <div class="mb-8">
        <p class="mb-2 text-xs font-semibold tracking-widest text-indigo-600 uppercase dark:text-indigo-400">Order payment</p>
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Record payment</h1>
        <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Enter the amount received for {{ $orderNumber }}.</p>
    </div>

    <x-order-creation-progress :step="4" />
    <x-form-errors />

    @if (session('success'))
        <p role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</p>
    @endif

    <section aria-labelledby="customer-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
        <h2 id="customer-heading" class="mb-5 text-lg font-semibold">Order summary</h2>
        <dl class="grid gap-5 break-words sm:grid-cols-3">
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Order number</dt><dd class="mt-1 font-semibold">{{ $orderNumber }}</dd></div>
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Customer</dt><dd class="mt-1 font-semibold">{{ $order->customer->company_name }}</dd></div>
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">Terms of Payment</dt><dd class="mt-1 font-medium">{{ $order->terms_of_payment ?? 'Not specified' }}</dd></div>
        </dl>
    </section>

    <x-payment-summary :summary="$summary" />

    @if ($summary['balance_in_cents'] > 0)
        <form method="POST" enctype="multipart/form-data" action="{{ route('orders.payments.store', ['order' => $order]) }}" data-record-payment data-payment-balance="{{ \App\MoneyAmount::decimal($summary['balance_in_cents']) }}" class="mt-6 space-y-6">
            @csrf
            <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
            <section aria-labelledby="payment-heading" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
                <h2 id="payment-heading" class="mb-5 text-lg font-semibold">Payment received</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-form-input name="amount" label="Amount (PHP)" type="number" :value="old('amount', '')" min="0.01" :max="\App\MoneyAmount::decimal($summary['balance_in_cents'])" step="0.01" inputmode="decimal" data-payment-amount />
                    <x-form-input name="payment_date" label="Date" type="date" :value="old('payment_date', now()->toDateString())" />
                    <div>
                        <label for="payment_type" class="mb-2 block text-sm font-medium">Payment type <span aria-hidden="true" class="text-rose-600">*</span></label>
                        <select id="payment_type" name="payment_type" required data-payment-type class="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:outline-2 focus:outline-indigo-500 dark:border-slate-700 dark:bg-slate-950" @if ($errors->has('payment_type')) aria-invalid="true" aria-describedby="payment_type-error" @endif>
                            @foreach ($paymentTypes as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_type', 'down_payment') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('payment_type')<p id="payment_type-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Downpayment leaves a balance. Fully Paid settles the exact remaining balance.</p>
                @if ($receiptUploadsAvailable)
                    <div class="mt-6">
                        <x-form-input name="receipt" label="Receipt or payment screenshot" type="file" :required="false" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" aria-describedby="receipt-help" />
                        <p id="receipt-help" class="mt-2 text-sm text-slate-500 dark:text-slate-400">Optional. JPG, PNG, or PDF, up to 5 MB. After a failed submission, select the file again.</p>
                    </div>
                @else
                    <p class="mt-6 text-sm text-slate-500 dark:text-slate-400">Receipt uploads are currently unavailable. You can still record a payment without a receipt.</p>
                @endif
            </section>
            <div class="flex flex-wrap justify-end gap-3">
                <a href="{{ route('orders.show', ['order' => $order]) }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to order</a>
                <button type="submit" data-submit-payment class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-wait disabled:opacity-60">Record payment</button>
            </div>
        </form>
    @else
        <p role="status" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">No payment is due. This order has no remaining balance.</p>
        <a href="{{ route('orders.show', ['order' => $order]) }}" class="mt-6 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to order</a>
    @endif
</x-app-layout>
