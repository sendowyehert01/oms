@props(['itemId', 'index', 'material' => []])

@php
    $material = is_array($material) ? $material : [];
    $prefix = 'material-'.$itemId.'-'.$index;
@endphp

<fieldset data-material-row class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
    <legend class="px-2 text-sm font-semibold">Material <span data-material-number>{{ is_numeric($index) ? (int) $index + 1 : '__NUMBER__' }}</span></legend>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-form-input :name="'materials['.$index.'][name]'" :id="$prefix.'-name'" :error="'materials.'.$index.'.name'" label="Material name" :value="$material['name'] ?? ''" maxlength="255" />
        <x-form-input :name="'materials['.$index.'][unit]'" :id="$prefix.'-unit'" :error="'materials.'.$index.'.unit'" label="Unit (e.g. kgs, pcs, liters)" :value="$material['unit'] ?? ''" maxlength="20" />
        <x-form-input :name="'materials['.$index.'][required_quantity]'" :id="$prefix.'-required'" :error="'materials.'.$index.'.required_quantity'" label="Required quantity" :value="$material['required_quantity'] ?? ''" inputmode="decimal" maxlength="13" />
        <x-form-input :name="'materials['.$index.'][available_quantity]'" :id="$prefix.'-available'" :error="'materials.'.$index.'.available_quantity'" label="Available quantity" :value="$material['available_quantity'] ?? ''" inputmode="decimal" maxlength="13" />
    </div>
    <button type="button" data-material-remove hidden class="mt-3 min-h-11 text-sm font-semibold text-rose-700 dark:text-rose-300">Remove material</button>
</fieldset>
