<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\OrderDrafts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectOrderCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $drafts = app(OrderDrafts::class);
        $submissionKey = $this->input('submission_key');
        $customerType = $this->input('customer_type');

        if ($drafts->find($this->session(), $submissionKey) !== null && in_array($customerType, ['existing', 'new'], true)) {
            $drafts->rememberCustomerInput($this->session(), $submissionKey, $customerType, $this->only(['customer_id', 'customer_name', 'contact_person', 'address', 'contact_number']));
        }
    }

    protected function getRedirectUrl(): string
    {
        $submissionKey = $this->input('submission_key');
        $customerType = $this->input('customer_type');

        return app(OrderDrafts::class)->find($this->session(), $submissionKey) !== null && in_array($customerType, ['existing', 'new'], true)
            ? route('orders.creation.customer', ['type' => $customerType, 'draft' => $submissionKey])
            : parent::getRedirectUrl();
    }

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
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'customer_type' => ['required', Rule::in(['existing', 'new'])],
            'customer_id' => ['exclude_unless:customer_type,existing', 'required_if:customer_type,existing', 'integer', Rule::exists(Customer::class, 'id')],
            'customer_name' => ['exclude_unless:customer_type,new', 'required_if:customer_type,new', 'string', 'max:255'],
            'contact_person' => ['exclude_unless:customer_type,new', 'required_if:customer_type,new', 'string', 'max:255'],
            'address' => ['exclude_unless:customer_type,new', 'required_if:customer_type,new', 'string', 'max:2000'],
            'contact_number' => ['exclude_unless:customer_type,new', 'required_if:customer_type,new', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_id.required_if' => 'Select a customer before continuing.',
            'customer_id.exists' => 'The selected customer is no longer available. Please select another customer.',
        ];
    }
}
