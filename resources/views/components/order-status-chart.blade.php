@props(['statuses', 'total'])

@php
    $colors = [
        'Pending' => '#f59e0b',
        'In production' => '#6366f1',
        'Ready for delivery' => '#06b6d4',
        'Completed' => '#10b981',
        'Other status' => '#64748b',
    ];
    $stops = [];
    $cumulativeCount = 0;

    foreach ($statuses as $status) {
        if ($status['count'] === 0 || $total === 0) {
            continue;
        }

        $start = number_format($cumulativeCount / $total * 360, 6, '.', '');
        $cumulativeCount += $status['count'];
        $end = number_format($cumulativeCount / $total * 360, 6, '.', '');
        $color = $colors[$status['label']] ?? '#64748b';
        $stops[] = $color.' '.$start.'deg '.$end.'deg';
    }
@endphp

<div class="mt-8 grid items-center gap-8 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
    @if ($total > 0)
        <div
            role="img"
            aria-label="Order status pie chart for {{ number_format($total) }} orders"
            aria-describedby="order-status-legend"
            class="mx-auto aspect-square w-full max-w-72 rounded-full border border-slate-200 dark:border-slate-700"
            style="background: conic-gradient({{ implode(', ', $stops) }})"
        ></div>
    @else
        <div class="mx-auto flex aspect-square w-full max-w-72 items-center justify-center rounded-full bg-slate-100 p-8 text-center dark:bg-slate-800">
            <p class="text-sm text-slate-600 dark:text-slate-400">No orders yet. Create an order to see its status here.</p>
        </div>
    @endif

    <div class="min-w-0 overflow-x-auto">
        <table id="order-status-legend" class="w-full text-left text-sm">
            <caption class="sr-only">Order status counts and percentages</caption>
            <thead>
                <tr class="border-b border-slate-200 text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    <th scope="col" class="pb-3 font-medium">Status</th>
                    <th scope="col" class="pb-3 text-right font-medium">Orders</th>
                    <th scope="col" class="pb-3 pl-4 text-right font-medium">Share</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statuses as $status)
                    <tr class="border-b border-slate-100 last:border-0 dark:border-slate-800">
                        <th scope="row" class="py-4 font-medium">
                            <span class="flex items-center gap-3">
                                <span aria-hidden="true" class="size-3 shrink-0 rounded-full" style="background-color: {{ $colors[$status['label']] ?? '#64748b' }}"></span>
                                {{ $status['label'] }}
                            </span>
                        </th>
                        <td class="py-4 text-right tabular-nums">{{ number_format($status['count']) }}</td>
                        <td class="py-4 pl-4 text-right text-slate-600 tabular-nums dark:text-slate-400">{{ number_format($total > 0 ? $status['count'] / $total * 100 : 0, 1) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (collect($statuses)->contains('label', 'Other status'))
            <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Other status includes orders with a status outside the four standard stages.</p>
        @endif
    </div>
</div>
