@props(['step'])

<ol aria-label="Order creation progress" class="mb-8 grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
    @foreach (['Customer choice', 'Customer information', 'Order details', 'Payment details'] as $index => $label)
        <li @if ($step === $index + 1) aria-current="step" @endif @class(['rounded-lg border px-4 py-3', 'border-brand-300 bg-brand-50 font-semibold text-brand-800 dark:border-brand-700 dark:bg-brand-950 dark:text-brand-200' => $step === $index + 1, 'border-slate-200 text-slate-500 dark:border-slate-800 dark:text-slate-400' => $step !== $index + 1])>
            {{ $index + 1 }}. {{ $label }}
        </li>
    @endforeach
</ol>
