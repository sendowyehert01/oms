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
    <div class="mt-6 border-t border-slate-200 pt-5 dark:border-slate-800">
        <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">Production specifications are optional. Choose Others to enter a custom sealing or puncher type.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-form-input :name="'items['.$index.'][material_color]'" :id="'item-'.$index.'-material-color'" :error="'items.'.$index.'.material_color'" label="Material/Color" :value="$item['material_color'] ?? ''" :required="false" maxlength="255" />
            <x-form-input :name="'items['.$index.'][size]'" :id="'item-'.$index.'-size'" :error="'items.'.$index.'.size'" label="Size and Thickness" :value="$item['size'] ?? ''" :required="false" maxlength="100" />
            <div class="sm:col-span-2">
                <x-form-input :name="'items['.$index.'][print_design_colors]'" :id="'item-'.$index.'-print-design-colors'" :error="'items.'.$index.'.print_design_colors'" label="Print Design and No. of Colors" :value="$item['print_design_colors'] ?? ''" :required="false" maxlength="255" />
            </div>
            <x-order-item-choice :index="$index" field="sealing" label="Sealing" :options="\App\Models\OrderItem::SEALING_OPTIONS" :value="$item['sealing'] ?? ''" :other-value="$item['sealing_other'] ?? ''" />
            <x-order-item-choice :index="$index" field="puncher" label="Puncher" :options="\App\Models\OrderItem::PUNCHER_OPTIONS" :value="$item['puncher'] ?? ''" :other-value="$item['puncher_other'] ?? ''" />
            <div class="sm:col-span-2">
                <x-form-textarea :name="'items['.$index.'][customization_notes]'" :id="'item-'.$index.'-remarks'" :error="'items.'.$index.'.customization_notes'" label="Remarks" :value="$item['customization_notes'] ?? ''" maxlength="2000" />
            </div>
        </div>
    </div>
    <button type="button" data-remove-item class="mt-3 inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-rose-700 hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 dark:text-rose-300 dark:hover:bg-rose-950">Remove item</button>
</fieldset>
