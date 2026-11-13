@props(['order', 'allReady', 'deliveryConfirmed'])
<section aria-labelledby="delivery-heading" class="mt-5 border-t border-slate-200 pt-5 dark:border-slate-800">
    <h3 id="delivery-heading" class="font-semibold">Confirm delivery</h3>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-400">After all items are received, record the actual delivery date and recipient. Then settle any remaining payment and complete the order in step 3.</p>
    @if ($deliveryConfirmed)
        <p class="mt-4 font-semibold">Full delivery confirmed</p>
        <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Delivery date</dt><dd>{{ $order->delivery_date?->format('M j, Y') }}</dd></div>
            <div><dt class="text-slate-500">Received by</dt><dd class="break-words">{{ $order->delivery_recipient }}</dd></div>
            <div><dt class="text-slate-500">Confirmed by</dt><dd class="break-words">{{ $order->delivery_confirmed_by_name }}</dd></div>
            <div><dt class="text-slate-500">Confirmation time ({{ config('app.reporting_timezone') }})</dt><dd>{{ $order->delivery_confirmed_at->timezone(config('app.reporting_timezone'))->format('M j, Y g:i A') }}</dd></div>
        </dl>
        @if (filled($order->delivery_notes))<p class="mt-3 whitespace-pre-line break-words text-sm">{{ $order->delivery_notes }}</p>@endif
    @elseif (in_array($order->status, ['Delivered', 'Completed'], true))
        <p class="mt-4 text-sm text-slate-500">Historical delivery details are not recorded. The saved status has been preserved.</p>
    @endif
    @can('updateStatus', $order)
        @if ($order->status === 'Ready for delivery' && $allReady)
            <a id="delivery-confirmation-action" href="#delivery-confirmation" data-order-step-link aria-haspopup="dialog" aria-controls="delivery-confirmation" class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white hover:bg-brand-700">Confirm full delivery</a>
            <x-order-action-dialog id="delivery-confirmation" title="Confirm full delivery" description="Confirm only after the recipient has received all items in the full ordered quantities." :open-on-error="($errors->any() || session('error')) && old('action') === 'deliver'">
                <form method="POST" action="{{ route('orders.status.update', $order) }}" data-checklist-form data-busy-form="Confirming delivery..." class="space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="deliver">
                    <x-form-input name="delivery_date" type="date" label="Delivery date" :value="old('delivery_date', now(config('app.reporting_timezone'))->toDateString())" :min="$order->order_date->toDateString()" :max="now(config('app.reporting_timezone'))->toDateString()" />
                    <x-form-input name="delivery_recipient" label="Recipient" :value="old('delivery_recipient')" maxlength="255" />
                    <x-form-textarea name="notes" id="delivery-notes" label="Delivery notes" :value="old('action') === 'deliver' ? old('notes') : ''" :required="false" maxlength="2000" />
                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                        <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Confirm delivery of all items</button>
                    </div>
                </form>
            </x-order-action-dialog>
        @elseif ($order->status === 'Ready for delivery')
            <p class="mt-4 text-sm text-amber-700 dark:text-amber-300">Resolve outstanding item readiness checks before confirming delivery.</p>
        @endif
    @else
        @unless ($deliveryConfirmed)
            <p class="mt-4 text-sm text-slate-600 dark:text-slate-400">An administrator confirms full delivery after all items are ready and received.</p>
        @endunless
    @endcan
</section>
