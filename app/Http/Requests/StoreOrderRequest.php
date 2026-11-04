<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\OrderDrafts;
use App\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\NestedRules;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $drafts = app(OrderDrafts::class);
        $submissionKey = $this->input('submission_key');
        $draft = $drafts->find($this->session(), $submissionKey);

        if ($draft !== null && $draft['customer_type'] !== null) {
            $drafts->rememberOrder($this->session(), $submissionKey, $this->only(['order_date', 'terms_of_payment', 'status', 'items']));
            $this->merge(['customer_id' => null, ...$draft['customer']]);
        } elseif (is_string($submissionKey) && Str::isUuid($submissionKey) && $this->missing('customer_id') && $this->missing('customer_name')) {
            $customerId = Order::query()->createdBy($this->user())->visibleTo($this->user())->where('submission_key', $submissionKey)->value('customer_id');

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
        return $this->user()->can('create', Order::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|NestedRules>
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
            'terms_of_payment' => ['required', 'string', 'max:255'],
            'payment_due_date' => [Rule::prohibitedIf(! $this->user()->isAdmin()), 'nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'status' => ['required', Rule::in([OrderStatus::Pending->value])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_name,quantity,unit,unit_price,material_color,size,print_design_colors,sealing,sealing_other,puncher,puncher_other,customization_notes'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.material_color' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:100'],
            'items.*.print_design_colors' => ['nullable', 'string', 'max:255'],
            'items.*.sealing' => ['nullable', 'string', Rule::in(OrderItem::SEALING_OPTIONS)],
            'items.*.sealing_other' => ['exclude_unless:items.*.sealing,Others', 'required_if:items.*.sealing,Others', 'string', 'max:255'],
            'items.*.puncher' => ['nullable', 'string', Rule::in(OrderItem::PUNCHER_OPTIONS)],
            'items.*.puncher_other' => ['exclude_unless:items.*.puncher,Others', 'required_if:items.*.puncher,Others', 'string', 'max:255'],
            'items.*.customization_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => Rule::forEach(function (mixed $value, string $attribute): array {
                $unit = $this->input(str_replace('.quantity', '.unit', $attribute), 'pcs');

                return $unit === 'kgs'
                    ? ['required', 'numeric', 'min:0.01', 'max:'.OrderItem::MAX_QUANTITY, 'regex:/^\d+(?:\.\d{1,2})?$/']
                    : ['required', 'integer', 'min:1', 'max:'.OrderItem::MAX_QUANTITY, 'regex:/^\d+$/'];
            }),
            'items.*.unit' => ['sometimes', 'required', 'string', Rule::in(OrderItem::MEASUREMENT_UNITS)],
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
            'items.*.quantity.min' => 'Enter at least :min for the selected measurement unit.',
            'items.*.quantity.integer' => 'The amount in pcs must be a whole number.',
            'items.*.quantity.max' => 'Each measurement amount must not exceed '.number_format(OrderItem::MAX_QUANTITY).'.',
            'items.*.quantity.regex' => 'Enter whole numbers for pcs or weights with no more than two decimal places for kgs.',
            'items.*.unit_price.min' => 'Each unit price must be zero or greater.',
            'items.*.unit_price.regex' => 'Enter a unit price with no more than two decimal places.',
            'items.*.sealing_other.required_if' => 'Specify the sealing type when choosing Others.',
            'items.*.puncher_other.required_if' => 'Specify the puncher type when choosing Others.',
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
            'items.*.quantity' => 'measurement amount',
            'items.*.unit' => 'measurement unit',
            'items.*.unit_price' => 'unit price',
            'items.*.material_color' => 'material/color',
            'items.*.size' => 'size and thickness',
            'items.*.print_design_colors' => 'print design and number of colors',
            'items.*.sealing' => 'sealing',
            'items.*.sealing_other' => 'other sealing type',
            'items.*.puncher' => 'puncher',
            'items.*.puncher_other' => 'other puncher type',
            'items.*.customization_notes' => 'remarks',
        ];
    }
}
