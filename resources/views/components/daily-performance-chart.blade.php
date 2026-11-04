@props(['performance', 'compact' => false])

@php
    $days = $performance['days'];
    $maximum = max(1, max(array_column($days, 'clients')), max(array_column($days, 'orders')));
    $chartWidth = $compact ? 520 : 960;
    $dayWidth = ($chartWidth - 60) / count($days);
    $barWidth = min(10, ($dayWidth - 4) / 2);
@endphp

<section aria-labelledby="daily-performance-heading" @class(['oms-surface rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900', 'mt-8' => ! $compact])>
    <h2 id="daily-performance-heading" class="text-xl font-semibold">Daily creation performance</h2>
    <p id="daily-performance-description" class="mt-2 text-sm text-slate-600 dark:text-slate-400">Clients and orders created over the last 30 days, {{ $performance['start'] }} to {{ $performance['end'] }} ({{ $performance['timezone'] }}). Activity uses the date each record was created.</p>
    <div class="mt-5 flex flex-wrap gap-6 text-sm">
        <span class="flex items-center gap-2"><span class="size-3 rounded bg-brand-600" aria-hidden="true"></span>Clients created</span>
        <span class="flex items-center gap-2"><span class="size-3 rounded bg-cyan-500" aria-hidden="true"></span>Orders created</span>
    </div>
    <div class="mt-5 overflow-x-auto">
        <svg viewBox="0 0 {{ $chartWidth }} 250" @class(['w-full', 'min-w-160' => ! $compact]) role="img" aria-labelledby="daily-performance-heading" aria-describedby="daily-performance-description">
            <line x1="40" y1="210" x2="{{ $chartWidth - 10 }}" y2="210" stroke="currentColor" class="text-slate-300 dark:text-slate-700" />
            <text x="4" y="25" fill="currentColor" font-size="12">{{ $maximum }}</text>
            <text x="20" y="213" fill="currentColor" font-size="12">0</text>
            @foreach ($days as $index => $day)
                @php
                    $x = 45 + $index * $dayWidth;
                    $clientHeight = $day['clients'] / $maximum * 180;
                    $orderHeight = $day['orders'] / $maximum * 180;
                @endphp
                <g class="chart-day">
                    <title>{{ $day['date'] }}: {{ $day['clients'] }} clients, {{ $day['orders'] }} orders</title>
                    <rect class="chart-bar" x="{{ $x }}" y="{{ 210 - $clientHeight }}" width="{{ $barWidth }}" height="{{ $clientHeight }}" rx="2" fill="#a70403" />
                    <rect class="chart-bar" x="{{ $x + $barWidth + 2 }}" y="{{ 210 - $orderHeight }}" width="{{ $barWidth }}" height="{{ $orderHeight }}" rx="2" fill="#06b6d4" />
                    @if ($index % 5 === 0 || $loop->last)
                        <text x="{{ $x + $barWidth }}" y="235" text-anchor="middle" fill="currentColor" font-size="11">{{ substr($day['date'], 5) }}</text>
                    @endif
                </g>
            @endforeach
        </svg>
    </div>
    @if (array_sum(array_column($days, 'clients')) + array_sum(array_column($days, 'orders')) === 0)
        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">No creation activity in this period.</p>
    @endif
    <details class="mt-4">
        <summary class="cursor-pointer py-2 text-sm font-semibold text-brand-700 dark:text-brand-300">View daily counts</summary>
        <div class="overflow-x-auto">
            <table class="mt-3 w-full text-left text-sm">
                <caption class="sr-only">Daily client and order creation counts</caption>
                <thead><tr><th scope="col" class="p-2">Date</th><th scope="col" class="p-2">Clients created</th><th scope="col" class="p-2">Orders created</th></tr></thead>
                <tbody>
                    @foreach ($days as $day)
                        <tr class="border-t border-slate-100 dark:border-slate-800"><th scope="row" class="p-2 font-normal"><time datetime="{{ $day['date'] }}">{{ $day['date'] }}</time></th><td class="p-2 tabular-nums">{{ $day['clients'] }}</td><td class="p-2 tabular-nums">{{ $day['orders'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</section>
