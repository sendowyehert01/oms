@props(['name', 'label', 'id' => null, 'type' => 'text', 'value' => '', 'error' => null, 'required' => true])

@php
    $inputId = $id ?? $name;
    $errorKey = $error ?? $name;
@endphp

<div>
    <label for="{{ $inputId }}" class="mb-2 block text-sm font-medium">{{ $label }} @if ($required)<span aria-hidden="true" class="text-rose-600">*</span>@endif</label>
    <input
        id="{{ $inputId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ is_scalar($value) ? $value : '' }}"
        @required($required)
        @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif
        {{ $attributes->class(['min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm focus:border-indigo-500 focus:outline-2 focus:outline-indigo-500 dark:border-slate-700 dark:bg-slate-950']) }}
    >
    @error($errorKey)
        <p id="{{ $inputId }}-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
    @enderror
</div>
