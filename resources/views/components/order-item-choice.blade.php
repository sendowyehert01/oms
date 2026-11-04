@props(['index', 'field', 'label', 'options', 'value' => '', 'otherValue' => ''])

@php
    $inputId = 'item-'.$index.'-'.$field;
    $errorKey = 'items.'.$index.'.'.$field;
    $selectedValue = is_scalar($value) ? (string) $value : '';
@endphp

<div data-item-choice>
    <label for="{{ $inputId }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
    <select id="{{ $inputId }}" name="items[{{ $index }}][{{ $field }}]" data-choice-select
        @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        class="oms-input min-h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:outline-2 focus:outline-brand-500 dark:border-slate-700 dark:bg-slate-950">
        <option value="">Select {{ strtolower($label) }} (optional)</option>
        @foreach ($options as $option)
            <option value="{{ $option }}" @selected($selectedValue === $option)>{{ $option }}</option>
        @endforeach
    </select>
    @error($errorKey)<p id="{{ $inputId }}-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
    <div data-choice-other class="mt-3">
        <x-form-input :name="'items['.$index.']['.$field.'_other]'" :id="$inputId.'-other'" :error="$errorKey.'_other'" :label="'Other '.strtolower($label).' (required for Others)'" :value="$otherValue" :required="$selectedValue === 'Others'" maxlength="255" />
    </div>
</div>
