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
            'action' => ['required', Rule::in(['start_production', 'mark_ready', 'reopen', 'deliver', 'complete'])],
            'delivery_date' => ['exclude_unless:action,deliver', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$this->route('order')->order_date->toDateString(), 'before_or_equal:'.now(config('app.reporting_timezone'))->toDateString()],
            'delivery_recipient' => ['exclude_unless:action,deliver', 'required', 'string', 'max:255'],
            'notes' => ['required_if:action,reopen', 'nullable', 'string', 'max:2000'],
            'quality_checked' => ['required_if:action,mark_ready', 'accepted_if:action,mark_ready'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.show', ['order' => $this->route('order')]);
    }
}
