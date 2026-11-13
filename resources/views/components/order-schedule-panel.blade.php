@props(['order'])
<section aria-labelledby="schedule-heading">
    <h3 id="schedule-heading" class="font-semibold">Delivery schedule</h3>
    <p class="mt-2 text-sm">Target delivery date: <strong>{{ $order->target_delivery_date?->format('M j, Y') ?? 'Not scheduled' }}</strong></p>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Optional: set a target while preparing the order. A delivery target is separate from Terms of Payment and payment due dates.</p>
    @can('scheduleDelivery', $order)
        @unless (in_array($order->status, ['Delivered', 'Completed'], true))
            <a id="delivery-target-action" href="#delivery-target-editor" data-order-step-link aria-haspopup="dialog" aria-controls="delivery-target-editor" class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-brand-700 dark:border-slate-700 dark:text-brand-300">{{ $order->target_delivery_date === null ? 'Set delivery target (optional)' : 'Edit delivery target' }}</a>
            <x-order-action-dialog id="delivery-target-editor" title="Delivery target" description="Set an optional target date for this order. It does not confirm delivery or change payment terms." :open-on-error="$errors->has('target_delivery_date') || (($errors->any() || session('error')) && old('action') === 'schedule_delivery')">
                <form method="POST" action="{{ route('orders.schedule.update', $order) }}" data-checklist-form data-busy-form="Saving schedule..." class="space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="schedule_delivery">
                    <x-form-input name="target_delivery_date" id="schedule-target-date" label="Target delivery date" type="date" :required="false" :value="old('target_delivery_date', $order->target_delivery_date?->toDateString())" :min="$order->order_date->toDateString()" />
                    <p class="text-xs text-slate-500">Leave empty to remove the target. Changes are recorded in status history.</p>
                    <div class="flex flex-wrap justify-end gap-3">
                        <button type="button" data-checklist-close class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Cancel</button>
                        <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Save delivery target</button>
                    </div>
                </form>
            </x-order-action-dialog>
        @endunless
    @endcan
</section>
