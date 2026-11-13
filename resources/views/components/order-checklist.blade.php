@props(['order', 'summary'])

@php
    $items = $order->items;
    $remainingItems = $items->reject(fn (\App\Models\OrderItem $item): bool => $item->isReadyForDelivery());
    $readyCount = $items->count() - $remainingItems->count();
    $allReady = $items->isNotEmpty() && $remainingItems->isEmpty();
    $canUpdateProgress = auth()->user()->can('updateStatus', $order);
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
        $nextTitle = 'Start production for remaining items.';
        $nextDescription = 'The stock checks show a shortage. Start production for those items, then confirm readiness when the full quantity is finished and quality checked.';
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
    $prepareHasErrors = $hasFailedSave && in_array(old('action'), ['check_availability', 'start_production', 'mark_ready', 'reopen'], true);
    $deliverHasErrors = $errors->hasAny(['target_delivery_date', 'delivery_date', 'delivery_recipient']) || ($hasFailedSave && in_array(old('action'), ['deliver', 'schedule_delivery'], true));
    $completeHasErrors = $hasFailedSave && old('action') === 'complete';
@endphp

<section data-order-checklist data-order-id="{{ $order->id }}" data-workflow-version="{{ $order->workflow_version }}" data-checklist-url="{{ route('orders.show', ['order' => $order] + request()->query()) }}" aria-labelledby="order-checklist-heading" class="mb-6 min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="order-checklist-heading" tabindex="-1" class="scroll-mt-24 text-lg font-semibold">Order checklist</h2>
        <div class="flex flex-wrap items-center gap-3">
            <span data-checklist-status><x-order-status :status="$order->status" /></span>
            <button id="order-checklist-refresh" type="button" data-checklist-refresh hidden class="inline-flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 text-sm font-semibold hover:bg-slate-100 disabled:opacity-50 dark:border-slate-700 dark:hover:bg-slate-800">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M16 7a6.5 6.5 0 1 0 .5 5M16 3v4h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <span data-checklist-refresh-label>Refresh checklist</span>
            </button>
        </div>
    </div>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Review each item in the table, then choose its next action. Forms open separately so you can focus on one item at a time.</p>
    <p data-checklist-message tabindex="-1" role="status" @if (! session('success')) hidden @endif class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</p>
    <div data-checklist-error tabindex="-1" role="alert" hidden class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200"></div>
    <div data-checklist-server-errors class="mt-4"><x-form-errors /></div>
    <noscript>
        <style>
            dialog[data-checklist-dialog] { display: block; position: static; width: 100%; max-height: none; margin: 1rem 0; }
            [data-checklist-close] { display: none; }
        </style>
        <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">Open a step to use its forms below the table.</p>
    </noscript>
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
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">Check stock, start production where needed, and confirm each item is ready. The order becomes ready for delivery when every item is ready. Choose Actions in the row for the item you want to update.</p>
                @cannot('updateStatus', $order)
                    <p class="mt-3 text-sm text-slate-600 dark:text-slate-400">Only administrators can update availability and production progress. You can follow their progress here.</p>
                @endcannot
                <x-item-production-panel :order="$order" />
                @can('updateStatus', $order)
                    @if ($order->status !== 'Pending')
                        <div class="mt-5 border-t border-slate-200 pt-4 dark:border-slate-800">
                            <a id="order-correction-action" href="#order-correction-details" data-order-step-link aria-haspopup="dialog" aria-controls="order-correction-details" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-brand-700 dark:border-slate-700 dark:text-brand-300">Correct status / reopen order</a>
                        </div>
                        <x-order-action-dialog id="order-correction-details" title="Reopen order for correction" description="Return this order and all its items to Pending. Stock checks and production progress will be reset. Recorded payments and previous history are retained." :open-on-error="$hasFailedSave && old('action') === 'reopen'">
                            <form method="POST" action="{{ route('orders.status.update', $order) }}" data-checklist-form data-busy-form="Reopening..." class="space-y-4">
                                @csrf
                                <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                                <input type="hidden" name="action" value="reopen">
                                <x-form-textarea name="notes" id="reopen-reason" label="Correction reason" :value="old('action') === 'reopen' ? old('notes') : ''" :required="true" maxlength="2000" />
                                <div class="flex flex-wrap justify-end gap-3">
                                    <button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                                    <button type="submit" class="min-h-11 rounded-lg border border-brand-200 px-5 text-sm font-semibold text-brand-700 dark:border-brand-800 dark:text-brand-300">Reopen as Pending</button>
                                </div>
                            </form>
                        </x-order-action-dialog>
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
                <x-order-schedule-panel :order="$order" />
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
