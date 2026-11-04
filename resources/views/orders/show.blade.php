<x-app-layout title="Order Details">
    <x-page-heading title="Order details" eyebrow="Order management" :description="$order['number']">
        @unless ($isSampleData)<a href="#production-heading" class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Production</a>@endunless
        <a href="{{ $returnToOrdersUrl }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to Orders</a>
    </x-page-heading>

    @if ($isSampleData)
        <p class="mb-6 rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm text-brand-800 dark:border-brand-800 dark:bg-brand-950 dark:text-brand-200">Sample order. Item details are illustrative.</p>
    @endif

    @if (session('success'))
        <p role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</p>
    @endif

    <x-form-errors />
    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="order-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="order-heading" class="mb-5 text-lg font-semibold">Order information</h2>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Order number</dt><dd class="mt-1 font-semibold [overflow-wrap:anywhere]">{{ $order['number'] }}</dd></div>
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Order ID</dt><dd class="mt-1 font-medium">{{ $order['id'] }}</dd></div>
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Order date</dt><dd class="mt-1 font-medium"><time datetime="{{ $order['order_date'] }}">{{ \Illuminate\Support\Carbon::parse($order['order_date'])->format('M j, Y') }}</time></dd></div>
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Terms of Payment</dt><dd class="mt-1 font-medium [overflow-wrap:anywhere]">{{ $order['terms_of_payment'] ?? 'Not specified' }}</dd></div>
                @if (empty($order['terms_of_payment']) && ! empty($order['due_date']))
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Legacy due date</dt><dd class="mt-1 font-medium"><time datetime="{{ $order['due_date'] }}">{{ \Illuminate\Support\Carbon::parse($order['due_date'])->format('M j, Y') }}</time></dd></div>
                @endif
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Order status</dt><dd class="mt-2"><x-order-status :status="$order['status']" /></dd></div>
            </dl>
        </section>

        <section aria-labelledby="customer-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="customer-heading" class="mb-5 text-lg font-semibold">Customer information</h2>
            <dl class="grid gap-5 sm:grid-cols-2">
                <div><dt class="text-sm text-slate-500 dark:text-slate-400">Customer name</dt><dd class="mt-1 font-semibold [overflow-wrap:anywhere]">{{ $order['customer']['name'] }}</dd></div>
                @if ($order['customer']['id'] !== null)
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Customer ID</dt><dd class="mt-1 font-medium">{{ $order['customer']['id'] }}</dd></div>
                @endif
                @if (isset($order['customer']['contact_person']))
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Contact person</dt><dd class="mt-1 font-medium [overflow-wrap:anywhere]">{{ $order['customer']['contact_person'] }}</dd></div>
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Contact number</dt><dd class="mt-1 font-medium [overflow-wrap:anywhere]">{{ $order['customer']['contact_number'] }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-sm text-slate-500 dark:text-slate-400">Address</dt><dd class="mt-1 font-medium whitespace-pre-line [overflow-wrap:anywhere]">{{ $order['customer']['address'] }}</dd></div>
                @endif
            </dl>
        </section>
    </div>

    @if ($workflowOrder !== null)
        <x-order-schedule-panel :order="$workflowOrder" />
    @endif
    <section aria-labelledby="items-heading" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 id="items-heading" class="border-b border-slate-200 p-4 text-lg font-semibold sm:p-6 dark:border-slate-800">Order items</h2>
        <div class="overflow-x-auto" role="region" aria-label="Order items table" tabindex="0">
            <table class="min-w-150 w-full text-left text-sm">
                <caption class="sr-only">Items, quantities, prices, and subtotals for {{ $order['number'] }} in Philippine pesos</caption>
                <thead class="border-b border-slate-200 bg-slate-50/80 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                    <tr>
                        <th scope="col" class="px-6 py-4">Item name</th>
                        <th scope="col" class="px-6 py-4 text-right">Unit of Measurement</th>
                        <th scope="col" class="px-6 py-4 text-right">Unit price (PHP)</th>
                        <th scope="col" class="px-6 py-4 text-right">Subtotal (PHP)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($order['items'] as $item)
                        <tr>
                            <th scope="row" class="max-w-lg px-6 py-5 font-medium [overflow-wrap:anywhere]">
                                {{ $item['product_name'] }}
                                <dl class="mt-3 space-y-2 text-xs font-normal">
                                    @foreach (['material_color' => 'Material/Color', 'size' => 'Size and Thickness', 'print_design_colors' => 'Print Design and No. of Colors', 'sealing' => 'Sealing', 'puncher' => 'Puncher', 'customization_notes' => 'Remarks'] as $field => $label)
                                        @php
                                            $specification = $item[$field] ?? null;
                                            if (in_array($field, ['sealing', 'puncher'], true) && $specification === 'Others') {
                                                $specification = $item[$field.'_other'] ?? null;
                                            }
                                        @endphp
                                        @if (filled($specification))
                                            <div><dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line">{{ $specification }}</dd></div>
                                        @endif
                                    @endforeach
                                </dl>
                            </th>
                            <td class="px-6 py-5 text-right whitespace-nowrap tabular-nums">{{ $item['measurement'] ?? number_format($item['quantity']).' pcs' }}</td>
                            <td class="px-6 py-5 text-right whitespace-nowrap tabular-nums"><x-money :cents="$item['unit_price_in_cents']" /></td>
                            <td class="px-6 py-5 text-right font-medium whitespace-nowrap tabular-nums"><x-money :cents="$item['subtotal_in_cents']" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-500 dark:text-slate-400">No items are recorded for this order.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-5 dark:border-slate-800 dark:bg-slate-950/40">
            <p class="font-semibold">Total amount (PHP)</p>
            <p class="text-2xl font-semibold tabular-nums"><x-money :cents="$order['total_amount_in_cents']" /></p>
        </div>
    </section>
    @if ($paymentSummary !== null)
        <x-payment-summary :summary="$paymentSummary" :record-url="route('orders.payments.create', ['order' => $order['id']])" />
    @endif
    @if ($workflowOrder !== null)
        <x-order-workflow-panel :order="$workflowOrder" :history="$statusHistory" />
    @endif
</x-app-layout>
