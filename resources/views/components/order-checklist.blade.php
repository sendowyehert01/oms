@props(['order', 'summary'])

@php
    $items = $order->items;
    $remainingItems = $items->reject(fn (\App\Models\OrderItem $item): bool => $item->isReadyForDelivery());
    $readyCount = $items->count() - $remainingItems->count();
    $allReady = $items->isNotEmpty() && $remainingItems->isEmpty();
    $canUpdateProgress = auth()->user()->can('updateProduction', $order);
    $trackingAllowed = in_array($order->status, ['Pending', 'In production', 'Ready for delivery'], true) && ($order->status === 'Pending' || $order->item_tracking_started_at !== null);
    $canConfirmDelivery = $order->status === 'Ready for delivery' && $allReady;
    $deliveryConfirmed = $order->delivery_date !== null && filled($order->delivery_recipient) && $order->delivery_confirmed_at !== null && filled($order->delivery_confirmed_by_name);
    $isClosed = in_array($order->status, ['Delivered', 'Completed'], true);
    $needsHistoricalReview = (! $isClosed && ! $trackingAllowed && ! $canConfirmDelivery) || ($isClosed && ! $deliveryConfirmed);
    $isComplete = $order->status === 'Completed' && $deliveryConfirmed && $summary['balance_in_cents'] === 0;
    $reviewItem = $remainingItems->first(fn (\App\Models\OrderItem $item): bool => $item->ready_check_required);
    $uncheckedItem = $remainingItems->first(fn (\App\Models\OrderItem $item): bool => $item->stock_availability === \App\ItemAvailability::NotChecked);
    $productionItem = $remainingItems->first(fn (\App\Models\OrderItem $item): bool => $item->production_status === \App\ItemProductionStatus::Pending && in_array($item->stock_availability, [\App\ItemAvailability::PartiallyAvailable, \App\ItemAvailability::Unavailable], true));
    $currentStep = 1;
    $nextLinkLabel = 'Go to current step';
    $nextHref = '#order-step-prepare';

    if ($needsHistoricalReview) {
        $nextTitle = 'Review previous order progress.';
        $nextDescription = 'The saved status does not have the tracking needed to verify these steps. Review the history, then reopen with a correction reason if the work needs to be recorded again.';
        $nextHref = '#order-correction-details';
        $nextLinkLabel = 'Review correction options';
    } elseif ($isComplete) {
        $currentStep = 3;
        $nextTitle = 'Order complete.';
        $nextDescription = 'Full delivery is confirmed and the balance is settled. No further confirmations are needed.';
        $nextHref = null;
    } elseif ($isClosed && $deliveryConfirmed) {
        $currentStep = 3;
        if ($summary['balance_in_cents'] > 0) {
            $nextTitle = 'Record the remaining payment.';
            $nextDescription = $order->status === 'Completed'
                ? 'The order is marked Completed, but its balance is still outstanding. Record the remaining payment to settle it.'
                : 'Delivery is confirmed. Settle the outstanding balance, then return here to complete the order.';
            $nextHref = route('orders.payments.create', $order);
            $nextLinkLabel = 'Record payment';
        } else {
            $nextTitle = 'Complete this order.';
            $nextDescription = 'Full delivery is confirmed and there is no outstanding balance. Mark the order Completed in step 3.';
            $nextHref = '#order-step-complete';
            $nextLinkLabel = 'Go to completion';
        }
    } elseif ($items->isEmpty()) {
        $nextTitle = 'Review this order with an administrator.';
        $nextDescription = 'No items are recorded, so stock checks and delivery cannot proceed. Review the order information before continuing.';
        $nextHref = '#items-heading';
        $nextLinkLabel = 'View order items';
    } elseif ($reviewItem !== null) {
        $nextTitle = 'Review item readiness.';
        if ($reviewItem->stock_availability === \App\ItemAvailability::Available) {
            $nextDescription = 'Available stock has been checked again. Reconfirm the full quantity and quality below to restore readiness for delivery.';
            $nextHref = '#production-item-'.$reviewItem->id;
            $nextLinkLabel = 'Go to readiness confirmation';
        } else {
            $nextDescription = 'A later stock check reported a shortage. Check stock again and reconfirm the full quantity and quality, or reopen with a reason if production must restart.';
            $nextHref = '#stock-check-'.$reviewItem->id;
            $nextLinkLabel = 'Review stock check';
        }
    } elseif ($uncheckedItem !== null) {
        $nextTitle = 'Check finished-stock availability.';
        $nextDescription = 'Check each item below. Available stock needs allocation and a quality check; partial or unavailable stock needs production.';
        $nextHref = '#stock-check-'.$uncheckedItem->id;
        $nextLinkLabel = 'Go to stock check';
    } elseif ($productionItem !== null) {
        $nextTitle = $productionItem->hasAvailableMaterials() ? 'Start production for remaining items.' : 'Check raw materials for remaining items.';
        $nextDescription = 'The finished-stock checks show a shortage. Confirm every required material is available before starting production, then confirm readiness when the full quantity is finished and quality checked.';
        $nextHref = '#production-item-'.$productionItem->id;
        $nextLinkLabel = 'Go to item progress';
    } elseif (! $allReady) {
        $nextTitle = 'Confirm item readiness.';
        $nextDescription = 'Allocate available stock or finish production, then confirm the full ordered quantity and quality for each remaining item.';
        $nextHref = '#production-item-'.$remainingItems->first()->id;
        $nextLinkLabel = 'Go to item progress';
    } elseif ($canConfirmDelivery) {
        $currentStep = 2;
        $nextTitle = 'Confirm delivery after all items are received.';
        $nextDescription = 'All items are ready. Set a delivery target if useful, then record the actual delivery date and recipient in step 2.';
        $nextHref = '#delivery-confirmation';
        $nextLinkLabel = 'Go to delivery';
    } else {
        $nextTitle = 'Review recorded item progress.';
        $nextDescription = 'The items are marked ready but the order status needs review. Review the stock checks below before confirming delivery.';
        $nextHref = '#stock-check-'.$items->first()->id;
        $nextLinkLabel = 'Review stock checks';
    }

    if (! $canUpdateProgress && ! $isComplete && ! ($isClosed && $deliveryConfirmed && $summary['balance_in_cents'] > 0)) {
        $nextDescription = 'Next action for the administrator: '.$nextTitle.' '.$nextDescription;
        $nextTitle = 'Awaiting administrator.';
        $nextHref = '#order-step-'.[1 => 'prepare', 2 => 'deliver', 3 => 'complete'][$currentStep];
        $nextLinkLabel = 'View current step';
    }

    $hasFailedSave = $errors->any() || session('error');
    $prepareHasErrors = $hasFailedSave && in_array(old('action'), ['check_availability', 'check_materials', 'start_production', 'mark_ready', 'reopen'], true);
    $deliverHasErrors = $errors->hasAny(['target_delivery_date', 'delivery_date', 'delivery_recipient']) || ($hasFailedSave && in_array(old('action'), ['deliver', 'schedule_delivery'], true));
    $completeHasErrors = $hasFailedSave && old('action') === 'complete';
@endphp

<section aria-labelledby="order-checklist-heading" class="mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="order-checklist-heading" tabindex="-1" class="scroll-mt-24 text-lg font-semibold">Order checklist</h2>
        <x-order-status :status="$order->status" />
    </div>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Follow these three steps. The next action updates after each save; you can open any step to review its details.</p>
    <div class="mt-4 rounded-xl border border-brand-200 bg-brand-50 p-4 dark:border-brand-900 dark:bg-brand-950">
        <p class="text-xs font-semibold tracking-wide text-brand-700 uppercase dark:text-brand-300">{{ $isComplete ? 'All done' : 'Next step' }}</p>
        <h3 id="order-next-action" class="mt-1 font-semibold">{{ $nextTitle }}</h3>
        <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">{{ $nextDescription }}</p>
        @if ($nextHref !== null)
            <a href="{{ $nextHref }}" @if (str_starts_with($nextHref, '#')) data-order-step-link @endif class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">{{ $nextLinkLabel }}</a>
        @endif
    </div>
    <p class="mt-4 text-xs text-slate-600 dark:text-slate-400">Payments can be recorded at any time. Production and delivery may proceed before payment; the balance must be settled before completion.</p>

    <div class="mt-4 space-y-3">
        <details id="order-step-prepare" @if ($currentStep === 1 || $prepareHasErrors) open @endif class="group scroll-mt-24 rounded-xl border border-slate-200 dark:border-slate-800">
            <summary class="min-h-14 list-none rounded-xl p-4">
                <span class="flex flex-wrap items-center gap-3">
                    <span class="grow font-semibold">1. Prepare order</span>
                    <span class="text-xs text-slate-600 dark:text-slate-400">{{ $needsHistoricalReview ? 'Review needed' : $readyCount.' of '.$items->count().' items ready' }}</span>
                    <svg class="size-4 shrink-0 group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </span>
            </summary>
            <div class="border-t border-slate-200 p-4 sm:p-5 dark:border-slate-800">
                <h3 id="production-heading" tabindex="-1" class="scroll-mt-24 font-semibold">Stock and production</h3>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Check stock, start production where needed, and confirm each item is ready. The order becomes ready for delivery when every item is ready.</p>
                @cannot('updateProduction', $order)
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Administrators and Production Monitors update availability and production progress. You can follow their progress here.</p>
                @endcannot
                <x-item-production-panel :order="$order" />
                @can('updateStatus', $order)
                    @if ($order->status !== 'Pending')
                        <details id="order-correction-details" @if ($needsHistoricalReview || ($hasFailedSave && old('action') === 'reopen')) open @endif class="mt-5 scroll-mt-24 border-t border-slate-200 pt-4 dark:border-slate-800">
                            <summary class="min-h-11 py-2 text-sm font-semibold text-brand-700 dark:text-brand-300">Correct status / reopen order</summary>
                            <form method="POST" action="{{ route('orders.status.update', $order) }}" data-busy-form="Reopening..." class="mt-4 space-y-4">
                                @csrf
                                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                                <input type="hidden" name="action" value="reopen">
                                <p class="text-sm text-slate-600 dark:text-slate-400">Return this order and its items to Pending for correction. Finished-stock checks will be reset. Recorded payments and previous history are retained.</p>
                                <x-form-textarea name="notes" id="reopen-reason" label="Correction reason" :value="old('action') === 'reopen' ? old('notes') : ''" :required="true" maxlength="2000" />
                                <button type="submit" class="min-h-11 rounded-lg border border-brand-200 px-5 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Reopen as Pending</button>
                            </form>
                        </details>
                    @endif
                @endcan
            </div>
        </details>

        <details id="order-step-deliver" @if ($currentStep === 2 || $deliverHasErrors) open @endif class="group scroll-mt-24 rounded-xl border border-slate-200 dark:border-slate-800">
            <summary class="min-h-14 list-none rounded-xl p-4">
                <span class="flex flex-wrap items-center gap-3">
                    <span class="grow font-semibold">2. Deliver order</span>
                    <span class="text-xs text-slate-600 dark:text-slate-400">{{ $isClosed && $deliveryConfirmed ? 'Delivery confirmed' : ($currentStep === 2 ? 'Ready to deliver' : 'Target and receipt') }}</span>
                    <svg class="size-4 shrink-0 group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </span>
            </summary>
            <div class="border-t border-slate-200 p-4 sm:p-5 dark:border-slate-800">
                <x-order-schedule-panel :order="$order" :expanded="$currentStep === 2 && $order->target_delivery_date === null" />
                <x-delivery-panel :order="$order" :all-ready="$allReady" :delivery-confirmed="$deliveryConfirmed" />
            </div>
        </details>

        <details id="order-step-complete" @if ($currentStep === 3 || $completeHasErrors) open @endif class="group scroll-mt-24 rounded-xl border border-slate-200 dark:border-slate-800">
            <summary class="min-h-14 list-none rounded-xl p-4">
                <span class="flex flex-wrap items-center gap-3">
                    <span class="grow font-semibold">3. Complete order</span>
                    <span class="text-xs text-slate-600 dark:text-slate-400">{{ $isComplete ? 'Complete' : ($summary['balance_in_cents'] > 0 ? 'Payment remaining' : 'Balance settled') }}</span>
                    <svg class="size-4 shrink-0 group-open:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m5 8 5 5 5-5" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </span>
            </summary>
            <div class="border-t border-slate-200 p-4 sm:p-5 dark:border-slate-800">
                <x-order-completion-panel :order="$order" :summary="$summary" :delivery-confirmed="$deliveryConfirmed" />
            </div>
        </details>
    </div>
</section>
