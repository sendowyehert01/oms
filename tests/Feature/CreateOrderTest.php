<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validCreateOrderData(array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(),
        'customer_id' => null,
        'customer_name' => 'Acme Packaging',
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
        'order_date' => '2026-10-31',
        'terms_of_payment' => 'Net 30',
        'status' => 'Pending',
        'items' => [
            ['product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95'],
            ['product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10'],
        ],
    ], $overrides);
}

/** @param array<string, mixed> $data */
function startCreateOrderWithCustomer(TestCase $testCase, array $data): string
{
    $response = $testCase->get(route('orders.create'))->assertOk();
    $key = $response->viewData('submissionKey');
    $testCase->withCookie(config('session.cookie'), session()->getId());
    $testCase->post(route('orders.creation.select-customer'), [
        'submission_key' => $key,
        'customer_type' => 'new',
        'customer_name' => $data['customer_name'],
        'contact_person' => $data['contact_person'],
        'address' => $data['address'],
        'contact_number' => $data['contact_number'],
    ])->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);

    return $key;
}

test('the orders list starts customer choice before showing the order form and cancel actions', function (): void {
    $this->get(route('orders.index'))->assertOk()
        ->assertSee('href="'.route('orders.create').'"', false);

    $key = startCreateOrderWithCustomer($this, validCreateOrderData());
    $response = $this->get(route('orders.creation.details', ['draft' => $key]));

    $response->assertOk()
        ->assertSee('Customer name')
        ->assertSee('Contact person')
        ->assertSee('Order date')
        ->assertSee('Terms of Payment')
        ->assertDontSee('name="due_date"', false)
        ->assertSee('Unit price (PHP)')
        ->assertSee('Subtotal (PHP)')
        ->assertSee('Total amount (PHP)')
        ->assertSee('Add item')
        ->assertSee('Refresh items')
        ->assertSee('Actions')
        ->assertSee('data-item-template', false)
        ->assertSee('data-item-dialog', false)
        ->assertSee('Remove item')
        ->assertSee('formaction="'.route('orders.creation.cancel').'"', false)
        ->assertSee('Cancel');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
});

test('retained items have table actions linked to closed dialogs with all specifications outside the table', function (): void {
    $key = startCreateOrderWithCustomer($this, validCreateOrderData());
    $items = [
        2 => ['product_name' => 'Piece bags', 'quantity' => 3, 'unit' => 'pcs', 'unit_price' => '9.95'],
        7 => [
            'product_name' => 'Weight bags', 'quantity' => '2.50', 'unit' => 'kgs', 'unit_price' => '1.01',
            'material_color' => 'Transparent', 'size' => '8 x 12 / 0.03 mm', 'print_design_colors' => 'Logo / 2 colors',
            'sealing' => 'Others', 'sealing_other' => 'Custom seal', 'puncher' => 'Others',
            'puncher_other' => 'Custom punch', 'customization_notes' => 'Pack flat.',
        ],
    ];
    $this->post(route('orders.creation.back'), ['submission_key' => $key, 'terms_of_payment' => 'Net 30', 'items' => $items]);

    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//tbody[@data-items-table-body]/tr[@data-item-row]')->length)->toBe(2);
    expect($xpath->query('//tbody[@data-items-table-body]//input | //tbody[@data-items-table-body]//select | //tbody[@data-items-table-body]//textarea')->length)->toBe(0);
    expect($xpath->query('//form[@data-create-order]//form')->length)->toBe(0);
    foreach ($items as $index => $item) {
        $dialog = $document->getElementById('order-item-dialog-'.$index);
        expect($dialog)->toBeInstanceOf(DOMElement::class);
        expect($dialog->tagName)->toBe('dialog');
        expect($dialog->hasAttribute('open'))->toBeFalse();
        expect($dialog->hasAttribute('data-item-auto-open'))->toBeFalse();
        expect($xpath->query('//tr[@data-item-row="'.$index.'"]//a[@aria-controls="order-item-dialog-'.$index.'"]')->length)->toBe(1);
        expect($xpath->query('//tr[@data-item-row="'.$index.'"]//button[@data-remove-item="'.$index.'"]')->length)->toBe(1);
        foreach ($item as $field => $value) {
            $controls = $xpath->query('.//*[@name="items['.$index.']['.$field.']"]', $dialog);
            expect($controls->length)->toBe(1);
            $control = $controls->item(0);
            $actual = match ($control->tagName) {
                'select' => $xpath->query('./option[@selected]', $control)->item(0)->getAttribute('value'),
                'textarea' => $control->textContent,
                default => $control->getAttribute('value'),
            };
            expect($actual)->toBe((string) $value);
        }
    }
    $this->assertDatabaseCount('orders', 0);
});

test('item validation reopens only the affected dialog and preserves the other table rows', function (): void {
    $key = startCreateOrderWithCustomer($this, validCreateOrderData());
    $data = validCreateOrderData(['submission_key' => $key]);
    $data['items'][1] = ['product_name' => 'Invalid weight bags', 'quantity' => 0, 'unit' => 'kgs', 'unit_price' => '1.01', 'sealing' => 'Others', 'sealing_other' => ''];
    $this->post(route('orders.store'), $data)->assertSessionHasErrors(['items.1.quantity', 'items.1.sealing_other']);

    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    expect($document->getElementById('order-item-dialog-0')->hasAttribute('data-item-auto-open'))->toBeFalse();
    expect($document->getElementById('order-item-dialog-1')->hasAttribute('data-item-auto-open'))->toBeTrue();
    expect($document->getElementById('item-1-quantity')->getAttribute('aria-invalid'))->toBe('true');
    $xpath = new DOMXPath($document);
    expect($xpath->query('//select[@id="item-1-unit"]/option[@selected]')->item(0)->getAttribute('value'))->toBe('kgs');
    expect($document->getElementById('order-items-name-0')->textContent)->toBe('Plastic bags');
    expect($document->getElementById('order-items-name-1')->textContent)->toBe('Invalid weight bags');
    $this->assertDatabaseCount('orders', 0);
});

test('creating an order saves its customer and items and continues to payment with a success message', function (): void {
    $data = validCreateOrderData(['total_amount_in_cents' => 1, 'number' => 'FORGED']);

    $response = $this->post(route('orders.store'), $data);

    $response->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Order ORD-2026-0001 created successfully.');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', [
        'company_name' => 'Acme Packaging',
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
    ]);
    $this->assertDatabaseHas('orders', ['id' => 1, 'customer_id' => 1, 'order_date' => '2026-10-31', 'terms_of_payment' => 'Net 30', 'due_date' => null, 'status' => 'Pending']);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseHas('order_items', ['order_id' => 1, 'product_name' => 'Plastic bags', 'quantity' => '2 pcs', 'unit' => 'pcs', 'unit_price' => '9.95']);
    $this->assertDatabaseHas('order_items', ['order_id' => 1, 'product_name' => 'Zip bags', 'quantity' => '3 pcs', 'unit' => 'pcs', 'unit_price' => '3.10']);

    $this->get($response->headers->get('Location'))->assertOk()
        ->assertSee('created successfully.')
        ->assertSee('Record payment')->assertSee('29.20');

    $this->get(route('orders.index'))->assertOk()
        ->assertSee('Saved orders')
        ->assertSeeInOrder(['ORD-2026-0001', 'Acme Packaging', 'Oct 31, 2026', 'Pending', '29.20'])
        ->assertDontSee('FORGED')
        ->assertDontSee('Sample orders');
});

test('an existing customer can receive another order without creating or changing their customer record', function (): void {
    $this->post(route('orders.store'), validCreateOrderData())->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);
    $customerId = Customer::query()->value('id');

    $response = $this->post(route('orders.store'), validCreateOrderData([
        'customer_id' => $customerId,
        'customer_name' => 'Should not replace customer',
        'contact_person' => null,
        'address' => null,
        'contact_number' => null,
    ]));

    $response->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseHas('customers', ['id' => $customerId, 'company_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz']);
    $this->assertDatabaseHas('orders', ['id' => 2, 'customer_id' => $customerId]);
    $choice = $this->get(route('orders.create'))->assertOk();
    $this->withCookie(config('session.cookie'), session()->getId())->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $choice->viewData('submissionKey')]))
        ->assertOk()->assertSee('Acme Packaging');
});

test('resubmitting the same creation request creates only one customer order and set of items', function (): void {
    $data = validCreateOrderData();
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);

    $response = $this->post(route('orders.store'), $data);

    $response->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Order ORD-2026-0001 created successfully.');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
});

test('missing mandatory order fields show errors without saving any records', function (): void {
    $response = $this->from(route('orders.create'))->post(route('orders.store'), []);

    $response->assertRedirectToRoute('orders.create')
        ->assertSessionHasErrors(['submission_key', 'customer_name', 'contact_person', 'address', 'contact_number', 'order_date', 'terms_of_payment', 'status', 'items']);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('invalid order input returns its validation message and leaves no saved records', function (string $field, mixed $value, string $message): void {
    $data = validCreateOrderData();
    data_set($data, $field, $value);

    $response = $this->from(route('orders.create'))->post(route('orders.store'), $data);

    $response->assertRedirectToRoute('orders.create')->assertSessionHasErrors([$field => $message]);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'empty items' => ['items', [], 'Add at least one item to the order.'],
    'non array items' => ['items', 'invalid', 'The items field must be an array.'],
    'missing item name' => ['items.0.product_name', '', 'The item name field is required.'],
    'zero quantity' => ['items.0.quantity', 0, 'Enter at least 1 for the selected measurement unit.'],
    'negative quantity' => ['items.0.quantity', -1, 'Enter at least 1 for the selected measurement unit.'],
    'fractional quantity' => ['items.0.quantity', 1.5, 'The amount in pcs must be a whole number.'],
    'excessive quantity' => ['items.0.quantity', 100000001, 'Each measurement amount must not exceed 100,000,000.'],
    'missing price' => ['items.0.unit_price', '', 'The unit price field is required.'],
    'negative price' => ['items.0.unit_price', '-0.01', 'Each unit price must be zero or greater.'],
    'non numeric price' => ['items.0.unit_price', 'free', 'The unit price field must be a number.'],
    'sub cent price' => ['items.0.unit_price', '1.001', 'Enter a unit price with no more than two decimal places.'],
    'exponential price' => ['items.0.unit_price', '1e2', 'Enter a unit price with no more than two decimal places.'],
    'excessive price' => ['items.0.unit_price', '1000000.00', 'The unit price field must not be greater than 999999.99.'],
    'invalid date' => ['order_date', '2026-02-30', 'The order date field must match the format Y-m-d.'],
    'missing payment terms' => ['terms_of_payment', '', 'The terms of payment field is required.'],
    'unsupported status' => ['status', 'Paid', 'The selected status is invalid.'],
    'unknown customer' => ['customer_id', 999, 'The selected customer id is invalid.'],
    'invalid submission key' => ['submission_key', 'invalid', 'The submission key field must be a valid UUID.'],
    'blank customer' => ['customer_name', '   ', 'The customer name field is required when customer id is not present.'],
]);

test('validation preserves customer details and all item rows on the creation form', function (): void {
    $data = validCreateOrderData();
    $data['submission_key'] = startCreateOrderWithCustomer($this, $data);
    $data['items'][1]['quantity'] = 0;
    $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('items.1.quantity');

    $response = $this->get(route('orders.creation.details', ['draft' => $data['submission_key']]));

    $response->assertOk()
        ->assertSee('Acme Packaging')
        ->assertSee('value="Plastic bags"', false)
        ->assertSee('value="Zip bags"', false)
        ->assertSee('value="'.$data['submission_key'].'"', false)
        ->assertSee('Enter at least 1 for the selected measurement unit.');
});

test('malformed item rows and field values can be displayed again without a server error', function (): void {
    $this->from(route('orders.create'))->post(route('orders.store'), validCreateOrderData([
        'customer_name' => ['invalid'],
        'items' => ['invalid'],
    ]))->assertSessionHasErrors(['customer_name', 'items.0']);

    $this->withCookie(config('session.cookie'), session()->getId())->get(route('orders.create'))
        ->assertOk()->assertSee('Please correct the following');
});

test('zero prices and decimal cents produce an exact server calculated total', function (): void {
    $response = $this->post(route('orders.store'), validCreateOrderData([
        'items' => [
            ['product_name' => 'Small bags', 'quantity' => 3, 'unit_price' => '0.10'],
            ['product_name' => 'Labels', 'quantity' => 2, 'unit_price' => '0.20'],
            ['product_name' => 'Free sample', 'quantity' => 1, 'unit_price' => '0'],
        ],
    ]));

    $response->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('order_items', 3);
    $this->get(route('orders.index'))->assertOk()->assertSee('0.70');
});

test('saved orders have unique numbers and remain searchable and paginated with newest orders first', function (): void {
    for ($orderIndex = 0; $orderIndex < 12; $orderIndex++) {
        $this->post(route('orders.store'), validCreateOrderData(['customer_name' => 'Test Packaging']))
            ->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);
    }

    $this->get(route('orders.index'))->assertOk()
        ->assertSeeInOrder(['ORD-2026-0012', 'ORD-2026-0003'])
        ->assertSee('Showing 1 to 10 of 12 orders');
    $this->get(route('orders.index', ['search' => '  tEsT pAcKaGiNg  ', 'page' => 2]))->assertOk()
        ->assertSeeInOrder(['ORD-2026-0002', 'ORD-2026-0001'])
        ->assertSee('Showing 11 to 12 of 12 orders')
        ->assertDontSee('ORD-2026-0012')
        ->assertSee('href="'.e(route('orders.index', ['search' => 'tEsT pAcKaGiNg', 'page' => 1])).'"', false);
    $this->get(route('orders.index', ['search' => 'ORD-2026-0012']))->assertOk()
        ->assertSee('Test Packaging')
        ->assertSee('Showing 1 to 1 of 1 orders');
});

test('customer names and retained item names are escaped in the list and form', function (): void {
    $customerName = '<script>alert("customer")</script>';
    $itemName = '<script>alert("item")</script>';
    $this->post(route('orders.store'), validCreateOrderData(['customer_name' => $customerName]))
        ->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);
    $this->get(route('orders.index'))->assertOk()->assertSee($customerName)->assertDontSee($customerName, false);

    $data = validCreateOrderData();
    $data['submission_key'] = startCreateOrderWithCustomer($this, $data);
    $data['items'][0]['product_name'] = $itemName;
    $data['items'][0]['quantity'] = 0;
    $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');

    $this->get(route('orders.creation.details', ['draft' => $data['submission_key']]))->assertOk()->assertSee($itemName)->assertDontSee($itemName, false);
});

test('an item save failure rolls back the new customer order and items together', function (): void {
    Exceptions::fake();
    OrderItem::creating(function (OrderItem $item): never {
        throw new RuntimeException('Unable to save order items.');
    });

    $response = $this->post(route('orders.store'), validCreateOrderData());

    $response->assertServerError();
    Exceptions::assertReported(RuntimeException::class);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});
