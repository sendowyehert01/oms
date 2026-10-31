<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

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
        'due_date' => '2026-11-07',
        'status' => 'Pending',
        'items' => [
            ['product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95'],
            ['product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10'],
        ],
    ], $overrides);
}

test('the orders list links to a creation form with customer details items and a cancel link', function (): void {
    $this->get(route('orders.index'))->assertOk()
        ->assertSee('href="'.route('orders.create').'"', false);

    $response = $this->get(route('orders.create'));

    $response->assertOk()
        ->assertSee('Customer name')
        ->assertSee('Contact person')
        ->assertSee('Order date')
        ->assertSee('Due date')
        ->assertSee('Unit price (PHP)')
        ->assertSee('Subtotal (PHP)')
        ->assertSee('Total amount (PHP)')
        ->assertSee('Add item')
        ->assertSee('Remove item')
        ->assertSee('href="'.route('orders.index').'"', false)
        ->assertSee('Cancel');

    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
});

test('creating an order saves its customer and items and redirects to the updated list with a success message', function (): void {
    $data = validCreateOrderData(['total_amount_in_cents' => 1, 'number' => 'FORGED']);

    $response = $this->post(route('orders.store'), $data);

    $response->assertRedirectToRoute('orders.index')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Order ORD-2026-0001 created successfully.');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', [
        'company_name' => 'Acme Packaging',
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
    ]);
    $this->assertDatabaseHas('orders', ['id' => 1, 'customer_id' => 1, 'order_date' => '2026-10-31', 'due_date' => '2026-11-07', 'status' => 'Pending']);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseHas('order_items', ['order_id' => 1, 'product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95']);
    $this->assertDatabaseHas('order_items', ['order_id' => 1, 'product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10']);

    $this->get($response->headers->get('Location'))->assertOk()
        ->assertSee('created successfully.')
        ->assertSee('Saved orders')
        ->assertSeeInOrder(['ORD-2026-0001', 'Acme Packaging', 'Oct 31, 2026', 'Pending', '29.20'])
        ->assertDontSee('FORGED')
        ->assertDontSee('Sample orders');
});

test('an existing customer can receive another order without creating or changing their customer record', function (): void {
    $this->post(route('orders.store'), validCreateOrderData())->assertRedirectToRoute('orders.index');
    $customerId = DB::table('customers')->value('id');

    $response = $this->post(route('orders.store'), validCreateOrderData([
        'customer_id' => $customerId,
        'customer_name' => 'Should not replace customer',
        'contact_person' => null,
        'address' => null,
        'contact_number' => null,
    ]));

    $response->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 2);
    $this->assertDatabaseHas('customers', ['id' => $customerId, 'company_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz']);
    $this->assertDatabaseHas('orders', ['id' => 2, 'customer_id' => $customerId]);
    $this->get(route('orders.create'))->assertOk()->assertSee('Acme Packaging');
});

test('resubmitting the same creation request creates only one customer order and set of items', function (): void {
    $data = validCreateOrderData();
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index');

    $response = $this->post(route('orders.store'), $data);

    $response->assertRedirectToRoute('orders.index')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Order ORD-2026-0001 created successfully.');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
});

test('missing mandatory order fields show errors without saving any records', function (): void {
    $response = $this->from(route('orders.create'))->post(route('orders.store'), []);

    $response->assertRedirectToRoute('orders.create')
        ->assertSessionHasErrors(['submission_key', 'customer_name', 'contact_person', 'address', 'contact_number', 'order_date', 'due_date', 'status', 'items']);
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
    'zero quantity' => ['items.0.quantity', 0, 'Each item quantity must be at least 1.'],
    'negative quantity' => ['items.0.quantity', -1, 'Each item quantity must be at least 1.'],
    'fractional quantity' => ['items.0.quantity', 1.5, 'Each item quantity must be a whole number.'],
    'excessive quantity' => ['items.0.quantity', 100001, 'The quantity field must not be greater than 100000.'],
    'missing price' => ['items.0.unit_price', '', 'The unit price field is required.'],
    'negative price' => ['items.0.unit_price', '-0.01', 'Each unit price must be zero or greater.'],
    'non numeric price' => ['items.0.unit_price', 'free', 'The unit price field must be a number.'],
    'sub cent price' => ['items.0.unit_price', '1.001', 'Enter a unit price with no more than two decimal places.'],
    'exponential price' => ['items.0.unit_price', '1e2', 'Enter a unit price with no more than two decimal places.'],
    'excessive price' => ['items.0.unit_price', '1000000.00', 'The unit price field must not be greater than 999999.99.'],
    'invalid date' => ['order_date', '2026-02-30', 'The order date field must match the format Y-m-d.'],
    'due date before order' => ['due_date', '2026-10-30', 'The due date field must be a date after or equal to order date.'],
    'unsupported status' => ['status', 'Paid', 'The selected status is invalid.'],
    'unknown customer' => ['customer_id', 999, 'The selected customer id is invalid.'],
    'invalid submission key' => ['submission_key', 'invalid', 'The submission key field must be a valid UUID.'],
    'blank customer' => ['customer_name', '   ', 'The customer name field is required when customer id is not present.'],
]);

test('validation preserves customer details and all item rows on the creation form', function (): void {
    $data = validCreateOrderData();
    $data['items'][1]['quantity'] = 0;
    $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('items.1.quantity');

    $response = $this->withCookie(config('session.cookie'), session()->getId())->get(route('orders.create'));

    $response->assertOk()
        ->assertSee('value="Acme Packaging"', false)
        ->assertSee('value="Plastic bags"', false)
        ->assertSee('value="Zip bags"', false)
        ->assertSee('value="'.$data['submission_key'].'"', false)
        ->assertSee('Each item quantity must be at least 1.');
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

    $response->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('order_items', 3);
    $this->get(route('orders.index'))->assertOk()->assertSee('0.70');
});

test('saved orders have unique numbers and remain searchable and paginated with newest orders first', function (): void {
    for ($orderIndex = 0; $orderIndex < 12; $orderIndex++) {
        $this->post(route('orders.store'), validCreateOrderData(['customer_name' => 'Test Packaging']))
            ->assertRedirectToRoute('orders.index');
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
        ->assertRedirectToRoute('orders.index');
    $this->get(route('orders.index'))->assertOk()->assertSee($customerName)->assertDontSee($customerName, false);

    $data = validCreateOrderData();
    $data['items'][0]['product_name'] = $itemName;
    $data['items'][0]['quantity'] = 0;
    $this->from(route('orders.create'))->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');

    $this->get(route('orders.create'))->assertOk()->assertSee($itemName)->assertDontSee($itemName, false);
});

test('an item save failure rolls back the new customer order and items together', function (): void {
    Exceptions::fake();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'order_items')) {
            throw new RuntimeException('Unable to save order items.');
        }
    });

    $response = $this->post(route('orders.store'), validCreateOrderData());

    $response->assertServerError();
    Exceptions::assertReported(RuntimeException::class);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});
