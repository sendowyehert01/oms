@props(['order'])

@php
    $items = $order->items;
    $readyCount = $items->filter(fn (\App\Models\OrderItem $item): bool => $item->isReadyForDelivery())->count();
    $trackingAllowed = in_array($order->status, ['Pending', 'In production', 'Ready for delivery'], true) && ($order->status === 'Pending' || $order->item_tracking_started_at !== null);
    $canUpdateProgress = auth()->user()->can('updateStatus', $order);
    $hasFailedSave = $errors->any() || session('error');
@endphp

<div class="mt-5">
    <p class="text-sm font-semibold">{{ $readyCount }} of {{ $items->count() }} items ready</p>
    @if (! $trackingAllowed)
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">This order has an existing status without current item tracking, or is already closed. An administrator must explicitly reopen it before changing item progress. Previous events have not been inferred.</p>
    @endif

    <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-800" role="region" aria-label="Item checklist table" tabindex="0">
        <table class="w-full min-w-160 text-left text-sm">
            <caption class="sr-only">Order items with quantities, finished-stock availability, production progress, and actions</caption>
            <thead class="border-b border-slate-200 bg-slate-50 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-400">
                <tr>
                    <th scope="col" class="px-4 py-3">Item</th>
                    <th scope="col" class="px-4 py-3 text-right">Quantity</th>
                    <th scope="col" class="px-4 py-3">Finished stock</th>
                    <th scope="col" class="px-4 py-3">Progress</th>
                    <th scope="col" class="px-4 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($items as $item)
                    @php
                        $availability = $item->stock_availability;
                        $progress = $item->production_status;
                        $needsReview = $item->ready_check_required;
                        $canStart = $progress === \App\ItemProductionStatus::Pending && in_array($availability, [\App\ItemAvailability::PartiallyAvailable, \App\ItemAvailability::Unavailable], true);
                        $canReady = $progress === \App\ItemProductionStatus::InProduction || (($progress === \App\ItemProductionStatus::Pending || $needsReview) && $availability === \App\ItemAvailability::Available);
                    @endphp
                    <tr data-checklist-item="{{ $item->id }}" class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <th scope="row" class="px-4 py-4">
                            <span class="mb-1 block text-xs font-normal text-slate-500 dark:text-slate-400">Item {{ $loop->iteration }}</span>
                            <span id="order-checklist-item-{{ $item->id }}" tabindex="-1" class="block max-w-64 scroll-mt-24 break-words font-semibold">{{ $item->product_name }}</span>
                        </th>
                        <td class="px-4 py-4 text-right whitespace-nowrap tabular-nums">{{ $item->measurement()->displayValue() }}</td>
                        <td class="px-4 py-4">{{ $availability->value }}</td>
                        <td class="px-4 py-4">
                            @if ($needsReview)
                                <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">Readiness needs review</span>
                            @else
                                <x-order-status :status="$progress->value" />
                            @endif
                            @if ($needsReview)
                                <p class="mt-2 max-w-56 text-xs text-amber-700 dark:text-amber-300">A later stock check reported a shortage. Check stock and quality again.</p>
                            @elseif ($progress === \App\ItemProductionStatus::Pending)
                                <p class="mt-2 max-w-56 text-xs text-slate-600 dark:text-slate-400">{{ $availability === \App\ItemAvailability::NotChecked ? 'Check finished-stock availability to choose the next action.' : ($canStart ? 'Production is needed to fulfill the full ordered quantity.' : 'Allocate the full quantity from available stock and confirm its quality.') }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <div class="flex min-w-36 flex-col items-start gap-2">
                                @if ($canUpdateProgress && $trackingAllowed)
                                    @if ($canStart || $canReady)
                                        <a id="progress-action-{{ $item->id }}" href="#production-item-{{ $item->id }}" data-order-step-link aria-haspopup="dialog" aria-controls="production-item-{{ $item->id }}" aria-label="{{ $canStart ? 'Start production' : ($needsReview ? 'Reconfirm readiness' : 'Confirm readiness') }} for item {{ $loop->iteration }}: {{ $item->product_name }}" class="inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-3 font-semibold text-white hover:bg-brand-700">{{ $canStart ? 'Start production' : ($needsReview ? 'Reconfirm ready' : 'Confirm ready') }}</a>
                                    @endif
                                    <a id="stock-action-{{ $item->id }}" href="#stock-check-{{ $item->id }}" data-order-step-link aria-haspopup="dialog" aria-controls="stock-check-{{ $item->id }}" aria-label="Check stock for item {{ $loop->iteration }}: {{ $item->product_name }}" @class(['inline-flex min-h-11 items-center rounded-lg px-3 font-semibold', 'bg-brand-600 text-white hover:bg-brand-700' => $availability === \App\ItemAvailability::NotChecked || ($needsReview && ! $canReady), 'border border-slate-300 text-brand-700 hover:bg-brand-50 dark:border-slate-700 dark:text-brand-300 dark:hover:bg-brand-950' => $availability !== \App\ItemAvailability::NotChecked && (! $needsReview || $canReady)])>{{ $availability === \App\ItemAvailability::NotChecked ? 'Check stock' : 'Update stock check' }}</a>
                                @endif
                                <a id="details-action-{{ $item->id }}" href="#item-details-{{ $item->id }}" data-order-step-link aria-haspopup="dialog" aria-controls="item-details-{{ $item->id }}" aria-label="View details for item {{ $loop->iteration }}: {{ $item->product_name }}" class="inline-flex min-h-11 items-center rounded-lg px-3 font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">View details</a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">No items are recorded for this order.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @foreach ($items as $item)
        @php
            $availability = $item->stock_availability;
            $progress = $item->production_status;
            $needsReview = $item->ready_check_required;
            $canStart = $progress === \App\ItemProductionStatus::Pending && in_array($availability, [\App\ItemAvailability::PartiallyAvailable, \App\ItemAvailability::Unavailable], true);
            $canReady = $progress === \App\ItemProductionStatus::InProduction || (($progress === \App\ItemProductionStatus::Pending || $needsReview) && $availability === \App\ItemAvailability::Available);
            $failedItemId = old('order_item_id');
            $isFailedItem = is_scalar($failedItemId) && (string) $failedItemId === (string) $item->id;
            $isFailedCheck = old('action') === 'check_availability' && $isFailedItem;
            $itemDescription = 'Item '.$loop->iteration.': '.$item->product_name.' · '.$item->measurement()->displayValue();
        @endphp

        <x-order-action-dialog :id="'item-details-'.$item->id" title="Item details" :description="$itemDescription">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-slate-500 dark:text-slate-400">Last finished-stock check</dt><dd class="mt-1 font-medium">{{ $availability->value }}</dd></div>
                <div><dt class="text-slate-500 dark:text-slate-400">Production progress</dt><dd class="mt-1"><x-order-status :status="$progress->value" /></dd></div>
                @if ($item->availability_checked_at)
                    <div class="sm:col-span-2"><dt class="text-slate-500 dark:text-slate-400">Checked by</dt><dd class="mt-1 break-words">{{ $item->availability_checked_by_name }} · {{ $item->availability_checked_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</dd></div>
                @endif
                @if (filled($item->availability_notes))
                    <div class="sm:col-span-2"><dt class="text-slate-500 dark:text-slate-400">Stock check notes</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $item->availability_notes }}</dd></div>
                @endif
                @if ($item->ready_confirmed_at)
                    <div class="sm:col-span-2"><dt class="text-slate-500 dark:text-slate-400">{{ $item->readiness_source === 'stock' ? 'Allocated from finished stock' : 'Production finished' }}</dt><dd class="mt-1 break-words">Confirmed by {{ $item->ready_confirmed_by_name }} on {{ $item->ready_confirmed_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}.</dd></div>
                @endif
            </dl>
            @if ($needsReview)
                <p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Readiness needs review because a later stock check reported a shortage. Confirm available stock and quality again, or reopen the order with a reason to restart production.</p>
            @endif
            <div class="mt-5 flex justify-end"><button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Close</button></div>
        </x-order-action-dialog>

        @if ($canUpdateProgress && $trackingAllowed)
            <x-order-action-dialog :id="'stock-check-'.$item->id" title="Check finished stock" :description="$itemDescription" :open-on-error="$hasFailedSave && $isFailedCheck">
                <form method="POST" action="{{ route('orders.items.production.update', ['order' => $order, 'item' => $item]) }}" data-checklist-form data-busy-form="Saving stock check..." class="space-y-4">
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
                    <p class="text-xs text-slate-500 dark:text-slate-400">Check finished goods for the full ordered quantity. Saving a stock check does not automatically finish production.</p>
                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                        <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Save stock check</button>
                    </div>
                </form>
            </x-order-action-dialog>

            @if ($canStart || $canReady)
                <x-order-action-dialog :id="'production-item-'.$item->id" :title="$canStart ? 'Start item production' : ($needsReview ? 'Reconfirm item readiness' : 'Confirm item readiness')" :description="$itemDescription" :open-on-error="$hasFailedSave && $isFailedItem && in_array(old('action'), ['start_production', 'mark_ready'], true)">
                    <form method="POST" action="{{ route('orders.items.production.update', ['order' => $order, 'item' => $item]) }}" data-checklist-form data-busy-form="Saving item progress..." class="space-y-4">
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                        <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                        <input type="hidden" name="action" value="{{ $canStart ? 'start_production' : 'mark_ready' }}">
                        <p class="text-sm text-slate-600 dark:text-slate-400">{{ $canStart ? 'Start production to fulfill the full ordered quantity. You will confirm readiness after production and quality checks are finished.' : 'Confirm this item only after the full ordered quantity is finished or allocated and its quality has been checked.' }}</p>
                        <x-form-textarea name="notes" :id="'progress-notes-'.$item->id" label="Progress notes" :value="! $isFailedCheck && $isFailedItem ? old('notes') : ''" maxlength="2000" />
                        @if ($canReady)
                            <label for="quality-checked-{{ $item->id }}" class="flex min-h-11 items-start gap-3 text-sm"><input id="quality-checked-{{ $item->id }}" type="checkbox" name="quality_checked" value="1" required @checked($isFailedItem && old('quality_checked')) class="mt-1 size-4 shrink-0 accent-brand-600">The full ordered quantity is finished or allocated and quality checked.</label>
                        @endif
                        <div class="flex flex-wrap justify-end gap-3">
                            <button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                            <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">{{ $canStart ? 'Start item production' : ($needsReview ? 'Reconfirm item readiness' : 'Mark item ready for delivery') }}</button>
                        </div>
                    </form>
                </x-order-action-dialog>
            @endif
        @endif
    @endforeach
</div>
