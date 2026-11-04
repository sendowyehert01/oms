@props(['name', 'label', 'id' => null, 'type' => 'text', 'value' => '', 'error' => null, 'required' => true, 'errorBag' => 'default'])

@php
    $inputId = $id ?? $name;
    $errorKey = $error ?? $name;
    $hasError = $errors->getBag($errorBag)->has($errorKey);
    $describedBy = trim(($attributes->get('aria-describedby') ?? '').' '.($hasError ? $inputId.'-error' : ''));
@endphp

<div>
    <label for="{{ $inputId }}" class="mb-2 block text-sm font-medium">{{ $label }} @if ($required)<span aria-hidden="true" class="text-rose-600">*</span>@endif</label>
    <div @class(['password-control' => $type === 'password'])>
        <input
            id="{{ $inputId }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ is_scalar($value) ? $value : '' }}"
            @required($required)
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->except('aria-describedby')->class(['oms-input min-h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-950']) }}
        >
        @if ($type === 'password')
            <button type="button" hidden data-password-toggle data-password-label="{{ $label }}" aria-label="Show {{ $label }}" aria-controls="{{ $inputId }}" aria-pressed="false" class="password-toggle">Show</button>
        @endif
    </div>
    @error($errorKey, $errorBag)
        <p id="{{ $inputId }}-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
    @enderror
</div>
