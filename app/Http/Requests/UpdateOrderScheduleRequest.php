<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateOrderScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('scheduleDelivery', $this->route('order'));

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'], 'workflow_version' => ['required', 'integer', 'min:0'],
            'target_delivery_date' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$this->route('order')->order_date->toDateString()],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.show', $this->route('order'));
    }
}
