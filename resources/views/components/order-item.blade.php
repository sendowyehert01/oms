@props(['index', 'item' => []])

@php($item = is_array($item) ? $item : [])

<fieldset data-order-item class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
    <legend class="px-2 text-sm font-semibold">Order item</legend>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-form-input :name="'items['.$index.'][product_name]'" :id="'item-'.$index.'-name'" :error="'items.'.$index.'.product_name'" label="Item name" :value="$item['product_name'] ?? ''" maxlength="255" />
        <x-form-input :name="'items['.$index.'][quantity]'" :id="'item-'.$index.'-quantity'" :error="'items.'.$index.'.quantity'" label="Quantity" type="number" :value="$item['quantity'] ?? 1" min="1" max="100000" step="1" data-quantity />
        <x-form-input :name="'items['.$index.'][unit_price]'" :id="'item-'.$index.'-price'" :error="'items.'.$index.'.unit_price'" label="Unit price (PHP)" type="number" :value="$item['unit_price'] ?? ''" min="0" max="999999.99" step="0.01" data-unit-price />
        <div>
            <p class="mb-2 text-sm font-medium">Subtotal (PHP)</p>
            <output data-subtotal for="item-{{ $index }}-quantity item-{{ $index }}-price" class="flex min-h-11 items-center text-sm font-semibold tabular-nums">0.00</output>
        </div>
    </div>
    <button type="button" data-remove-item class="mt-3 inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 dark:text-rose-300 dark:hover:bg-rose-950">Remove item</button>
</fieldset>
