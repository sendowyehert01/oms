<?php

namespace App\Http\Requests;

use App\ItemAvailability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateOrderItemProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('updateProduction', $this->route('order'));

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'workflow_version' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['check_availability', 'check_materials', 'start_production', 'mark_ready'])],
            'stock_availability' => ['exclude_unless:action,check_availability', 'required', Rule::enum(ItemAvailability::class)->except(ItemAvailability::NotChecked)],
            'finished_stock_quantity' => ['exclude_unless:action,check_availability', 'nullable', 'numeric', 'min:0', 'max:100000000', 'regex:/^\d{1,9}(?:\.\d{1,2})?$/'],
            'materials' => ['exclude_unless:action,check_materials', 'required', 'array', 'min:1', 'max:20'],
            'materials.*' => ['array:name,required_quantity,available_quantity,unit'],
            'materials.*.name' => ['required', 'string', 'max:255'],
            'materials.*.required_quantity' => ['required', 'numeric', 'gt:0', 'max:100000000', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'materials.*.available_quantity' => ['required', 'numeric', 'min:0', 'max:100000000', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'],
            'materials.*.unit' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quality_checked' => ['required_if:action,mark_ready', 'accepted_if:action,mark_ready'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('action') !== 'check_materials' || ! is_array($this->input('materials'))) {
            return;
        }

        $materials = array_values($this->input('materials'));
        foreach ($materials as &$material) {
            if (! is_array($material)) {
                continue;
            }
            foreach (['name', 'required_quantity', 'available_quantity', 'unit'] as $field) {
                if (is_string($material[$field] ?? null)) {
                    $material[$field] = trim($material[$field]);
                }
            }
        }
        unset($material);
        $this->merge(['materials' => $materials]);
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.show', ['order' => $this->route('order')]);
    }
}
