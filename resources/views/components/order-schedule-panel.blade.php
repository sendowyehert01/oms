@props(['order', 'expanded' => false])
<section aria-labelledby="schedule-heading">
    <h3 id="schedule-heading" class="font-semibold">Delivery schedule</h3>
    <p class="mt-2 text-sm">Target delivery date: <strong>{{ $order->target_delivery_date?->format('M j, Y') ?? 'Not scheduled' }}</strong></p>
    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Optional: set a target while preparing the order. A delivery target is separate from Terms of Payment and payment due dates.</p>
    @can('scheduleDelivery', $order)
        @unless (in_array($order->status, ['Delivered', 'Completed'], true))
            <details id="delivery-target-editor" @if ($expanded || $errors->has('target_delivery_date') || (($errors->any() || session('error')) && old('action') === 'schedule_delivery')) open @endif class="mt-4 scroll-mt-24"><summary class="min-h-11 py-2 font-semibold text-brand-700 dark:text-brand-300">{{ $order->target_delivery_date === null ? 'Set delivery target (optional)' : 'Edit delivery target' }}</summary>
                <form method="POST" action="{{ route('orders.schedule.update', $order) }}" data-busy-form="Saving schedule..." class="mt-3 space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <input type="hidden" name="action" value="schedule_delivery">
                    <x-form-input name="target_delivery_date" id="schedule-target-date" label="Target delivery date" type="date" :required="false" :value="old('target_delivery_date', $order->target_delivery_date?->toDateString())" :min="$order->order_date->toDateString()" />
                    <p class="text-xs text-slate-500">Leave empty to remove the target. Changes are recorded in status history.</p>
                    <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Save delivery target</button>
                </form>
            </details>
        @endunless
    @endcan
</section>
