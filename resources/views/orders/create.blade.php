<x-app-layout title="Create Order">
    <x-page-heading title="Create Order" eyebrow="Order management" description="Add the order details and items for your customer. Fields marked * are required." />

    <x-order-creation-progress :step="3" />
    <x-form-errors message="Please correct the following before creating the order:" />

    <form method="POST" action="{{ route('orders.store') }}" data-create-order class="space-y-6">
        @csrf
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">

        <section aria-labelledby="customer-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="customer-heading" class="mb-5 text-lg font-semibold">Customer summary</h2>
            <dl class="grid gap-4 break-words sm:grid-cols-2">
                @foreach (['customer_name' => 'Customer name', 'contact_person' => 'Contact person', 'address' => 'Address', 'contact_number' => 'Contact number'] as $field => $label)
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 font-medium">{{ $customer[$field] }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section aria-labelledby="order-heading" class="oms-surface rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <h2 id="order-heading" class="mb-5 text-lg font-semibold">Order information</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-form-input name="order_date" label="Order date" type="date" :value="$orderInput['order_date'] ?? now()->toDateString()" />
                <x-form-input name="target_delivery_date" label="Target delivery date" type="date" :required="false" :value="$orderInput['target_delivery_date'] ?? ''" />
                <x-form-input name="terms_of_payment" label="Terms of Payment" :value="$orderInput['terms_of_payment'] ?? ''" maxlength="255" placeholder="e.g. Cash on delivery or Net 30" />
                @if (auth()->user()->isAdmin())
                    <x-form-input name="payment_due_date" label="Payment due date" type="date" :required="false" :value="$orderInput['payment_due_date'] ?? ''" />
                @endif
                <div>
                    <p class="mb-2 text-sm font-medium">Initial order status</p>
                    <input type="hidden" name="status" value="Pending">
                    <div class="flex min-h-12 items-center"><x-order-status status="Pending" /></div>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Production progress is updated after the order is saved.</p>
                </div>
            </div>
        </section>

        <section data-order-items-section aria-labelledby="items-heading" class="oms-surface min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 id="items-heading" tabindex="-1" class="text-lg font-semibold">Order items</h2>
                <div class="flex flex-wrap gap-3">
                    <button id="order-items-refresh" type="button" data-items-refresh hidden class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-semibold hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M16 7a6.5 6.5 0 1 0 .5 5M16 3v4h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        Refresh items
                    </button>
                    <button type="button" data-add-item aria-haspopup="dialog" hidden class="inline-flex min-h-11 items-center rounded-lg border border-brand-200 px-4 text-sm font-semibold text-brand-700 hover:bg-brand-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-brand-800 dark:text-brand-300 dark:hover:bg-brand-950">Add item</button>
                </div>
            </div>
            <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Add up to 100 items. Enter whole numbers for pcs or weights with up to two decimal places for kgs, up to {{ number_format(\App\Models\OrderItem::MAX_QUANTITY) }} per item. Unit prices can be up to PHP 999,999.99 per selected unit.</p>
            @php
                $oldItems = $orderInput['items'] ?? [['product_name' => '', 'quantity' => 1, 'unit_price' => '']];
                $items = is_array($oldItems) ? $oldItems : [];
            @endphp
            <p class="mb-4 text-sm text-slate-600 dark:text-slate-400">Review items in the table and use the row actions to edit or remove them. Adding or saving an item updates this table and the total.</p>
            <p data-items-message role="status" hidden class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200"></p>
            <noscript>
                <style>
                    dialog[data-item-dialog] { display: block; position: static; width: 100%; max-height: none; margin: 1rem 0; }
                    [data-item-close], [data-item-row-actions] { display: none; }
                </style>
                <p class="mb-4 text-sm text-slate-600 dark:text-slate-400">Enter item details in the forms below. Enable JavaScript to add more items and use the table actions.</p>
            </noscript>
            <div data-items-table-scroll role="region" aria-label="Order items table" tabindex="0" class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800">
                <table class="w-full min-w-160 text-left text-sm">
                    <caption class="sr-only">Order items with quantities, unit prices, subtotals, and actions</caption>
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-4 py-3">Item</th>
                            <th scope="col" class="px-4 py-3 text-right">Quantity</th>
                            <th scope="col" class="px-4 py-3 text-right">Unit price (PHP)</th>
                            <th scope="col" class="px-4 py-3 text-right">Subtotal (PHP)</th>
                            <th scope="col" class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody data-items-table-body class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($items as $index => $item)
                            <tr data-item-row="{{ $index }}" class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40">
                                <th scope="row" class="px-4 py-4"><span id="order-items-name-{{ $index }}" tabindex="-1" class="block max-w-64 break-words font-semibold">{{ is_scalar($item['product_name'] ?? '') ? ($item['product_name'] ?? '') : '' }}</span></th>
                                <td class="px-4 py-4 text-right whitespace-nowrap tabular-nums">{{ is_scalar($item['quantity'] ?? '') ? ($item['quantity'] ?? '') : '' }} {{ is_scalar($item['unit'] ?? 'pcs') ? ($item['unit'] ?? 'pcs') : '' }}</td>
                                <td class="px-4 py-4 text-right whitespace-nowrap tabular-nums">{{ is_scalar($item['unit_price'] ?? '') ? ($item['unit_price'] ?? '') : '' }}</td>
                                <td class="px-4 py-4 text-right whitespace-nowrap tabular-nums">0.00</td>
                                <td data-item-row-actions class="px-4 py-4">
                                    <a href="#order-item-dialog-{{ $index }}" data-edit-item="{{ $index }}" aria-haspopup="dialog" aria-controls="order-item-dialog-{{ $index }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-3 font-semibold text-white hover:bg-brand-700">Edit item</a>
                                    <button type="button" data-remove-item="{{ $index }}" class="inline-flex min-h-11 items-center rounded-lg px-3 font-medium text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950">Remove item</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">No items added. Choose Add item to start.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div data-order-items @if (! array_key_exists('items', $orderInput)) data-initial-item @endif>
                @foreach ($items as $index => $item)
                    <x-order-action-dialog :id="'order-item-dialog-'.$index" context="item" title="Edit order item" description="Update this item and its production specifications, then save to update the table." :open-on-error="$errors->hasAny(['items.'.$index, 'items.'.$index.'.*'])">
                        <x-order-item :index="$index" :item="$item" />
                        <div class="mt-5 flex flex-wrap justify-end gap-3">
                            <button type="button" data-item-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                            <button type="button" data-save-item hidden class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Save item</button>
                        </div>
                    </x-order-action-dialog>
                @endforeach
            </div>
            <p data-items-error role="alert" class="mt-3 text-sm text-rose-600 dark:text-rose-400" hidden>Add at least one item to the order.</p>
            <template data-item-template>
                <x-order-action-dialog id="order-item-dialog-__INDEX__" context="item" title="Add order item" description="Enter the item details and any production specifications, then add it to the table.">
                    <x-order-item index="__INDEX__" />
                    <div class="mt-5 flex flex-wrap justify-end gap-3">
                        <button type="button" data-item-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                        <button type="button" data-save-item hidden class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Add item</button>
                    </div>
                </x-order-action-dialog>
            </template>
            <div class="mt-6 flex items-center justify-between border-t border-slate-200 pt-5 dark:border-slate-800">
                <span class="font-semibold">Total amount (PHP)</span>
                <output data-order-total aria-live="polite" class="text-2xl font-semibold tabular-nums">0.00</output>
            </div>
        </section>

        <div class="form-actions flex flex-wrap justify-end gap-3">
            <button type="submit" data-submit-order class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:cursor-wait disabled:opacity-60">Save order and continue to payment</button>
            <button type="submit" formnovalidate formaction="{{ route('orders.creation.back') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700 dark:hover:bg-slate-800">Back to customer step</button>
            <button type="submit" formnovalidate formaction="{{ route('orders.creation.cancel') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-5 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</button>
        </div>
    </form>
</x-app-layout>
