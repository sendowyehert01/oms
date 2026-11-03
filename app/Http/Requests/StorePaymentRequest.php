<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'amount' => ['bail', 'required', 'numeric', 'min:0.01', 'regex:/^\d{1,16}(?:\.\d{1,2})?$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'payment_type' => ['required', 'string', Rule::in(array_keys(Payment::TYPES))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.min' => 'Enter an amount of at least PHP 0.01.',
            'amount.regex' => 'Enter an amount with up to 16 whole-number digits and two decimal places.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('orders.payments.create', ['order' => $this->route('order')]);
    }
}
