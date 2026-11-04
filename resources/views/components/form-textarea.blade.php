@props(['name', 'label', 'id' => null, 'value' => '', 'error' => null, 'required' => false])

@php
    $inputId = $id ?? $name;
    $errorKey = $error ?? $name;
@endphp

<div>
    <label for="{{ $inputId }}" class="mb-2 block text-sm font-medium">{{ $label }} @if ($required)<span aria-hidden="true" class="text-rose-600">*</span>@endif</label>
    <textarea id="{{ $inputId }}" name="{{ $name }}" rows="3" @required($required)
        @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->class(['oms-input min-h-24 w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-sm dark:border-slate-700 dark:bg-slate-950']) }}
    >{{ is_scalar($value) ? $value : '' }}</textarea>
    @error($errorKey)<p id="{{ $inputId }}-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror
</div>
