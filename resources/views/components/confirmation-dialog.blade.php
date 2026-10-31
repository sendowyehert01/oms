@props(['id', 'title', 'confirmLabel' => 'Confirm'])

<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-description" {{ $attributes->class(['m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-xl backdrop:bg-slate-950/50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100']) }}>
    <h2 id="{{ $id }}-title" class="text-xl font-semibold">{{ $title }}</h2>
    <div id="{{ $id }}-description" class="mt-4 text-sm text-slate-600 [overflow-wrap:anywhere] dark:text-slate-400">{{ $slot }}</div>
    <p data-confirmation-error role="alert" hidden class="mt-4 text-sm text-rose-600 dark:text-rose-400"></p>
    <form method="dialog" class="mt-6 flex flex-wrap justify-end gap-3">
        <button type="submit" data-confirmation-cancel autofocus class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</button>
        <button type="button" data-confirmation-confirm class="inline-flex min-h-11 items-center rounded-lg bg-rose-600 px-4 text-sm font-semibold text-white hover:bg-rose-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-600 disabled:cursor-wait disabled:opacity-60">{{ $confirmLabel }}</button>
    </form>
</dialog>
