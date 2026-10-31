@props(['status'])

<span {{ $attributes->class([
    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
    'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $status === 'Pending',
    'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' => $status === 'In production',
    'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' => $status === 'Ready for delivery',
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $status === 'Completed',
]) }}>
    <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
    {{ $status }}
</span>
