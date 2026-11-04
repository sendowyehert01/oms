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
        Gate::authorize('updateStatus', $this->route('order'));

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'workflow_version' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['check_availability', 'start_production', 'mark_ready'])],
            'stock_availability' => ['exclude_unless:action,check_availability', 'required', Rule::enum(ItemAvailability::class)->except(ItemAvailability::NotChecked)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'quality_checked' => ['required_if:action,mark_ready', 'accepted_if:action,mark_ready'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.show', ['order' => $this->route('order')]);
    }
}
