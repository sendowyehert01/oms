<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
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
            'action' => ['required', Rule::in(['start_production', 'mark_ready', 'reopen'])],
            'notes' => ['required_if:action,reopen', 'nullable', 'string', 'max:2000'],
            'quality_checked' => ['required_if:action,mark_ready', 'accepted_if:action,mark_ready'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.show', ['order' => $this->route('order')]);
    }
}
