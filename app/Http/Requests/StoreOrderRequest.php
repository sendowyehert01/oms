<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Order;
use App\OrderDrafts;
use App\OrderEntries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $drafts = app(OrderDrafts::class);
        $submissionKey = $this->input('submission_key');
        $draft = $drafts->find($this->session(), $submissionKey);

        if ($draft !== null && $draft['customer_type'] !== null) {
            $drafts->rememberOrder($this->session(), $submissionKey, $this->only(['order_date', 'due_date', 'status', 'items']));
            $this->merge(['customer_id' => null, ...$draft['customer']]);
        } elseif (is_string($submissionKey) && Str::isUuid($submissionKey) && $this->missing('customer_id') && $this->missing('customer_name')) {
            $customerId = Order::query()->where('submission_key', $submissionKey)->value('customer_id');

            if ($customerId !== null) {
                $this->merge(['customer_id' => $customerId]);
            }
        }
    }

    protected function getRedirectUrl(): string
    {
        $submissionKey = $this->input('submission_key');

        return app(OrderDrafts::class)->find($this->session(), $submissionKey) !== null
            ? route('orders.creation.details', ['draft' => $submissionKey])
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
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')],
            'customer_name' => [Rule::excludeIf($this->filled('customer_id')), 'required_without:customer_id', 'string', 'max:255'],
            'contact_person' => [Rule::excludeIf($this->filled('customer_id')), 'required_without:customer_id', 'string', 'max:255'],
            'address' => [Rule::excludeIf($this->filled('customer_id')), 'required_without:customer_id', 'string', 'max:2000'],
            'contact_number' => [Rule::excludeIf($this->filled('customer_id')), 'required_without:customer_id', 'string', 'max:30'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'status' => ['required', Rule::in(OrderEntries::STATUSES)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_name,quantity,unit_price'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item to the order.',
            'items.min' => 'Add at least one item to the order.',
            'items.*.quantity.min' => 'Each item quantity must be at least 1.',
            'items.*.quantity.integer' => 'Each item quantity must be a whole number.',
            'items.*.unit_price.min' => 'Each unit price must be zero or greater.',
            'items.*.unit_price.regex' => 'Enter a unit price with no more than two decimal places.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'customer_name' => 'customer name',
            'items.*.product_name' => 'item name',
            'items.*.quantity' => 'quantity',
            'items.*.unit_price' => 'unit price',
        ];
    }
}
