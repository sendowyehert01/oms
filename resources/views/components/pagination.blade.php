@props(['paginator'])

<div class="flex flex-col items-center justify-between gap-4 border-t border-slate-200 px-4 py-5 sm:flex-row sm:px-6 dark:border-slate-800">
    <p class="text-sm text-slate-500 dark:text-slate-400" role="status">Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} orders</p>

    @if ($paginator->total() > 0)
        <nav aria-label="Orders pagination" class="flex flex-wrap items-center justify-center gap-1">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-slate-400 dark:text-slate-600">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-slate-300 dark:hover:bg-slate-800">Previous</a>
            @endif

            @foreach ($paginator->linkCollection() as $link)
                @if ($loop->first || $loop->last)
                    @continue
                @endif

                @if ($link['active'])
                    <span aria-current="page" aria-label="Page {{ $link['label'] }}" class="inline-flex size-11 items-center justify-center rounded-lg bg-indigo-600 text-sm font-semibold text-white">{{ $link['label'] }}</span>
                @elseif ($link['url'])
                    <a href="{{ $link['url'] }}" aria-label="Go to page {{ $link['label'] }}" class="inline-flex size-11 items-center justify-center rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-slate-300 dark:hover:bg-slate-800">{{ $link['label'] }}</a>
                @else
                    <span aria-hidden="true" class="inline-flex size-11 items-center justify-center text-slate-400">{{ $link['label'] }}</span>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-slate-600 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 dark:text-slate-300 dark:hover:bg-slate-800">Next</a>
            @else
                <span aria-disabled="true" class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm text-slate-400 dark:text-slate-600">Next</span>
            @endif
        </nav>
    @endif
</div>
