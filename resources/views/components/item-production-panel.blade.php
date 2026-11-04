@props(['order'])

@php
    $items = $order->items;
    $readyCount = $items->filter(fn (\App\Models\OrderItem $item): bool => $item->isReadyForDelivery())->count();
    $trackingAllowed = in_array($order->status, ['Pending', 'In production', 'Ready for delivery'], true) && ($order->status === 'Pending' || $order->item_tracking_started_at !== null);
@endphp

<div class="mt-5">
    <p class="text-sm font-semibold">{{ $readyCount }} of {{ $items->count() }} items ready</p>
    @if (! $trackingAllowed)
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">This order has an existing status without current item tracking, or is already closed. An administrator must explicitly reopen it before changing item progress. Previous events have not been inferred.</p>
    @endif
    <div class="mt-4 space-y-4">
        @forelse ($items as $item)
            @php
                $availability = $item->stock_availability;
                $progress = $item->production_status;
                $needsReview = $item->ready_check_required;
                $canStart = $progress === \App\ItemProductionStatus::Pending && in_array($availability, [\App\ItemAvailability::PartiallyAvailable, \App\ItemAvailability::Unavailable], true);
                $canReady = $progress === \App\ItemProductionStatus::InProduction || (($progress === \App\ItemProductionStatus::Pending || $needsReview) && $availability === \App\ItemAvailability::Available);
                $failedItemId = old('order_item_id');
                $isFailedItem = is_scalar($failedItemId) && (string) $failedItemId === (string) $item->id;
                $isFailedCheck = old('action') === 'check_availability' && $isFailedItem;
            @endphp
            <article aria-labelledby="production-item-{{ $item->id }}" class="rounded-xl border border-slate-200 p-4 sm:p-5 dark:border-slate-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="production-item-{{ $item->id }}" class="break-words text-sm font-semibold">{{ $item->product_name }}</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $item->measurement()->displayValue() }}</p>
                    </div>
                    <x-order-status :status="$progress->value" />
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Last finished-stock check</dt><dd class="mt-1 font-medium">{{ $availability->value }}</dd></div>
                    @if ($item->availability_checked_at)
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">Checked by</dt><dd class="mt-1 break-words text-xs">{{ $item->availability_checked_by_name }} · {{ $item->availability_checked_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</dd></div>
                    @endif
                </dl>
                @if (filled($item->availability_notes))<p class="mt-3 whitespace-pre-line break-words text-sm text-slate-600 dark:text-slate-400">{{ $item->availability_notes }}</p>@endif
                @if ($item->ready_confirmed_at)
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ $item->readiness_source === 'stock' ? 'Allocated from finished stock' : 'Production finished' }} · Confirmed by {{ $item->ready_confirmed_by_name }} on {{ $item->ready_confirmed_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}.</p>
                @endif
                @if ($needsReview)
                    <p role="status" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">Readiness needs review because a later stock check reported a shortage. Confirm available stock and quality again, or reopen the order with a reason to restart production.</p>
                @elseif ($progress === \App\ItemProductionStatus::Pending)
                    <p class="mt-3 text-xs text-slate-600 dark:text-slate-400">{{ $availability === \App\ItemAvailability::NotChecked ? 'Check finished-stock availability to choose the next action.' : ($canStart ? 'Production is needed to fulfill the full ordered quantity.' : 'Allocate the full quantity from available stock and confirm its quality.') }}</p>
                @endif
                @can('updateStatus', $order)
                    @if ($trackingAllowed)
                        <details class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                            <summary class="min-h-11 py-3 text-sm font-semibold text-brand-700 dark:text-brand-300">Check / update finished stock</summary>
                            <form method="POST" action="{{ route('orders.items.production.update', ['order' => $order, 'item' => $item]) }}" data-busy-form="Saving stock check..." class="mt-3 space-y-4">
                                @csrf
                                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                                <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                                <input type="hidden" name="action" value="check_availability">
                                <div>
                                    <label for="availability-{{ $item->id }}" class="mb-2 block text-sm font-medium">Finished-stock availability <span aria-hidden="true" class="text-rose-600">*</span></label>
                                    <select id="availability-{{ $item->id }}" name="stock_availability" required @if ($isFailedCheck && $errors->has('stock_availability')) aria-invalid="true" aria-describedby="availability-{{ $item->id }}-error" @endif class="oms-input min-h-12 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950">
                                        <option value="">Choose the check result</option>
                                        @foreach ([\App\ItemAvailability::Available, \App\ItemAvailability::PartiallyAvailable, \App\ItemAvailability::Unavailable] as $option)
                                            <option value="{{ $option->value }}" @selected($isFailedCheck ? old('stock_availability') === $option->value : $availability === $option)>{{ $option->value }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isFailedCheck) @error('stock_availability')<p id="availability-{{ $item->id }}-error" class="mt-2 text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>@enderror @endif
                                </div>
                                <x-form-textarea name="notes" :id="'stock-notes-'.$item->id" label="Stock check notes" :value="$isFailedCheck ? old('notes') : $item->availability_notes" maxlength="2000" />
                                <p class="text-xs text-slate-500 dark:text-slate-400">This is a manual check of finished goods for the full ordered quantity. Saving a check does not automatically finish production.</p>
                                <button type="submit" class="min-h-11 rounded-lg border border-brand-200 px-4 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Save stock check</button>
                            </form>
                        </details>
                        @if ($canStart || $canReady)
                            <form method="POST" action="{{ route('orders.items.production.update', ['order' => $order, 'item' => $item]) }}" data-busy-form="Saving item progress..." class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                                <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                                <input type="hidden" name="action" value="{{ $canStart ? 'start_production' : 'mark_ready' }}">
                                <x-form-textarea name="notes" :id="'progress-notes-'.$item->id" label="Progress notes" :value="! $isFailedCheck && $isFailedItem ? old('notes') : ''" maxlength="2000" />
                                @if ($canReady)
                                    <label class="flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" name="quality_checked" value="1" required class="mt-1 size-4 shrink-0 accent-brand-600">The full ordered quantity is finished or allocated and quality checked.</label>
                                @endif
                                <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">{{ $canStart ? 'Start item production' : ($needsReview ? 'Reconfirm item readiness' : 'Mark item ready for delivery') }}</button>
                            </form>
                        @endif
                    @endif
                @endcan
            </article>
        @empty
            <p class="text-sm text-slate-500 dark:text-slate-400">No items are recorded for this order.</p>
        @endforelse
    </div>
</div>
