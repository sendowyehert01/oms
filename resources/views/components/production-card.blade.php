@props(['order', 'board', 'today'])
@php
    $blockers = $board->blockers($order);
    $late = $order->target_delivery_date && $order->target_delivery_date->toDateString() < $today && !in_array($order->status, ['Delivered', 'Completed'], true);
@endphp
<article class="oms-surface min-w-0 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
    <a href="{{ route('orders.show', $order) }}" class="block break-words font-semibold text-brand-700 focus-visible:outline-2 dark:text-brand-300">{{ app(\App\OrderEntries::class)->number($order->id, $order->order_date->toDateString()) }}</a>
    <p class="mt-1 break-words text-sm">{{ $order->customer->company_name }}</p>
    <div class="mt-3"><x-order-status :status="$order->status" /></div>
    <p class="mt-3 text-sm">{{ $order->ready_items_count }} / {{ $order->items_count }} items ready</p>
    <p class="mt-1 break-words text-xs text-slate-500">Responsible: {{ $order->creator?->name ?? 'Creator not recorded' }}</p>
    <p class="mt-2 text-xs">Target delivery: {{ $order->target_delivery_date?->format('M j, Y') ?? 'Not scheduled' }}</p>
    @if ($late)<p class="mt-2 text-xs font-semibold text-rose-700 dark:text-rose-300">Late for delivery</p>@endif
    <p class="mt-2 text-xs text-slate-500">{{ $order->stage_started_at ? $order->stage_started_at->diffForHumans(null, true).' in current stage' : 'Stage start not recorded' }}</p>
    @if ($blockers)
        <ul aria-label="Production blockers" class="mt-3 space-y-1 text-xs text-amber-700 dark:text-amber-300">
            @foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
        </ul>
    @else
        <p class="mt-3 text-xs text-slate-500">No preparation blockers</p>
    @endif
</article>
