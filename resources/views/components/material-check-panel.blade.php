@props(['order', 'item', 'trackingAllowed'])

@php
    $isFailedCheck = old('action') === 'check_materials' && is_scalar(old('order_item_id')) && (string) old('order_item_id') === (string) $item->id;
    $materials = $isFailedCheck ? old('materials', []) : ($item->material_checklist ?? []);
    $materials = is_array($materials) && $materials !== [] ? array_values($materials) : [[]];
@endphp

@if ($item->needsMaterialCheck() || $item->materials_checked_at)
    <section aria-labelledby="materials-heading-{{ $item->id }}" class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
        <h5 id="materials-heading-{{ $item->id }}" class="text-sm font-semibold">Raw materials: {{ $item->material_availability->value }}</h5>
        @if ($item->needsMaterialCheck() && $item->remainingProductionQuantity())
            <p class="mt-2 text-sm">Remaining quantity to produce: <strong>{{ $item->remainingProductionQuantity()->displayValue() }}</strong></p>
        @endif
        @if ($item->materials_checked_at)
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">Checked by {{ $item->materials_checked_by_name }} on {{ $item->materials_checked_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}.</p>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($item->material_checklist ?? [] as $material)
                    <li><strong>{{ $material['name'] }}</strong>: required {{ $material['required_quantity'] }} {{ $material['unit'] }}, available {{ $material['available_quantity'] }} {{ $material['unit'] }}.</li>
                @endforeach
            </ul>
            @if (filled($item->material_notes))<p class="mt-3 whitespace-pre-line break-words text-sm">{{ $item->material_notes }}</p>@endif
        @endif
        @if ($item->needsMaterialCheck() && ! $item->hasAvailableMaterials())
            <p role="status" class="mt-3 text-sm font-semibold text-amber-700 dark:text-amber-300">{{ $item->material_availability === \App\ItemAvailability::NotChecked ? 'Check raw materials before starting production.' : 'Waiting for materials. Every required quantity must be available before production starts.' }}</p>
        @endif
        @can('updateProduction', $order)
            @if ($trackingAllowed && $item->needsMaterialCheck())
                <details id="materials-check-{{ $item->id }}" @if (! $item->hasAvailableMaterials() || ($isFailedCheck && $errors->any())) open @endif class="mt-3">
                    <summary class="min-h-11 py-3 text-sm font-semibold text-brand-700 dark:text-brand-300">Check / update raw materials</summary>
                    <form method="POST" action="{{ route('orders.items.production.update', ['order' => $order, 'item' => $item]) }}" data-material-checklist data-busy-form="Saving material check..." class="mt-3 space-y-4">
                        @csrf
                        <input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="workflow_version" value="{{ $order->workflow_version }}">
                        <input type="hidden" name="order_item_id" value="{{ $item->id }}">
                        <input type="hidden" name="action" value="check_materials">
                        <p class="text-sm text-slate-600 dark:text-slate-400">List every material needed for the remaining production quantity. Enter quantities using the same unit for each material, with up to three decimal places.</p>
                        <div data-material-rows class="space-y-4">
                            @foreach ($materials as $index => $material)
                                <x-material-check-row :item-id="$item->id" :index="$index" :material="$material" />
                            @endforeach
                        </div>
                        <template data-material-template><x-material-check-row :item-id="$item->id" index="__INDEX__" /></template>
                        <button type="button" data-material-add hidden class="min-h-11 rounded-lg border border-slate-300 px-4 text-sm font-semibold dark:border-slate-700">Add material</button>
                        <x-form-textarea name="notes" :id="'material-notes-'.$item->id" label="Material shortage or preparation notes" :required="false" :value="$isFailedCheck ? old('notes') : $item->material_notes" maxlength="2000" />
                        <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700">Save material check</button>
                    </form>
                </details>
            @endif
        @endcan
    </section>
@endif
