@props(['id', 'title', 'description', 'openOnError' => false, 'context' => 'checklist'])

<dialog id="{{ $id }}" data-{{ $context }}-dialog @if ($openOnError) data-{{ $context }}-auto-open @endif aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-description" @class(['dialog-enter m-auto max-h-[calc(100dvh_-_2rem)] w-[calc(100%_-_2rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-xl backdrop:bg-slate-950/50 sm:p-6 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100', 'max-w-lg' => $context === 'checklist', 'max-w-3xl' => $context === 'item'])>
    <div class="flex items-start justify-between gap-4">
        <h3 id="{{ $id }}-title" tabindex="-1" class="text-lg font-semibold">{{ $title }}</h3>
        <button type="button" data-{{ $context }}-close aria-label="Close {{ $title }}" class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
            <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" stroke-linecap="round" /></svg>
        </button>
    </div>
    <p id="{{ $id }}-description" class="mt-2 break-words text-sm text-slate-600 dark:text-slate-400">{{ $description }}</p>
    @if ($openOnError)
        <div data-checklist-server-errors class="mt-4"><x-form-errors /></div>
    @endif
    <div data-{{ $context }}-dialog-error tabindex="-1" role="alert" hidden class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200"></div>
    @if ($context === 'checklist')
        <button type="button" data-checklist-refresh hidden class="mt-3 min-h-11 rounded-lg border border-brand-200 px-4 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Refresh checklist</button>
    @endif
    <div class="mt-5">{{ $slot }}</div>
</dialog>
