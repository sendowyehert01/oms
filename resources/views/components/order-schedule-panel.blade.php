@props(['order'])
<section aria-labelledby="schedule-heading" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 sm:p-6 dark:border-slate-800 dark:bg-slate-900">
    <h2 id="schedule-heading" class="text-lg font-semibold">Delivery schedule</h2>
    <p class="mt-2 text-sm">Target delivery date: <strong>{{ $order->target_delivery_date?->format('M j, Y') ?? 'Not scheduled' }}</strong></p>
    <p class="mt-2 text-xs text-slate-500">A delivery target is separate from Terms of Payment and payment due dates.</p>
    @can('scheduleDelivery', $order)
        @unless (in_array($order->status, ['Delivered', 'Completed'], true))
            <details class="mt-4"><summary class="cursor-pointer py-2 font-semibold text-brand-700 dark:text-brand-300">Edit delivery target</summary>
                <form method="POST" action="{{ route('orders.schedule.update', $order) }}" data-busy-form="Saving schedule..." class="mt-3 space-y-4">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                    <x-form-input name="target_delivery_date" id="schedule-target-date" label="Target delivery date" type="date" :required="false" :value="old('target_delivery_date', $order->target_delivery_date?->toDateString())" :min="$order->order_date->toDateString()" />
                    <p class="text-xs text-slate-500">Leave empty to remove the target. Changes are recorded in status history.</p>
                    <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Save delivery target</button>
                </form>
            </details>
        @endunless
    @endcan
</section>
