<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @param  array<string, string>  $customerAttributes
 * @param  array<string, int|string>  $orderAttributes
 * @param  array<int, array{product_name: string, quantity: int, unit_price: string}>|null  $items
 */
function createViewOrderFixture(array $customerAttributes = [], array $orderAttributes = [], ?array $items = null): int
{
    $customerId = DB::table('customers')->insertGetId(array_replace([
        'company_name' => 'Acme Packaging',
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
    ], $customerAttributes));

    $orderId = DB::table('orders')->insertGetId(array_replace([
        'customer_id' => $customerId,
        'order_date' => '2026-10-31',
        'due_date' => '2026-11-07',
        'status' => 'In production',
    ], $orderAttributes));

    foreach ($items ?? [
        ['product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95'],
        ['product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10'],
    ] as $item) {
        DB::table('order_items')->insert(['order_id' => $orderId] + $item);
    }

    return $orderId;
}

test('each saved order has a view link with the current list search and page', function (): void {
    $orderId = createViewOrderFixture();

    $response = $this->get(route('orders.index', ['search' => 'Acme', 'page' => 99]));

    $response->assertOk()
        ->assertSee('aria-label="View order ORD-2026-0001"', false)
        ->assertSee('href="'.e(route('orders.show', ['order' => $orderId, 'search' => 'Acme', 'page' => 1])).'"', false)
        ->assertSee('Loading order details...')
        ->assertDontSee('/orders/samples/', false);
});

test('sample orders have view links preserving search and pagination', function (): void {
    $response = $this->get(route('orders.index', ['search' => 'packaging', 'page' => 2]));

    $response->assertOk()
        ->assertSee('href="'.e(route('orders.samples.show', ['order' => 1010, 'search' => 'packaging', 'page' => 2])).'"', false)
        ->assertSee('aria-label="View order ORD-2026-1010"', false);
});

test('details display the selected order customer and all its item prices subtotals and total', function (): void {
    $orderId = createViewOrderFixture();
    createViewOrderFixture(['company_name' => 'Other customer'], [], [
        ['product_name' => 'Unrelated item', 'quantity' => 10, 'unit_price' => '99.00'],
    ]);

    $response = $this->get(route('orders.show', ['order' => $orderId]));

    $response->assertOk()
        ->assertSee('ORD-2026-0001')
        ->assertSee('Acme Packaging')
        ->assertSee('Customer ID')
        ->assertSee('Alex Cruz')
        ->assertSee('123 Market Street')
        ->assertSee('09123456789')
        ->assertSee('Oct 31, 2026')
        ->assertSee('Nov 7, 2026')
        ->assertSee('In production')
        ->assertSeeInOrder(['Plastic bags', '9.95', '19.90', 'Zip bags', '3.10', '9.30', 'Total amount (PHP)', '29.20'])
        ->assertDontSee('Other customer')
        ->assertDontSee('Unrelated item')
        ->assertDontSee('Sample order.');

    expect($response['order']['customer']['id'])->toBe(1);
    expect($response['order']['items'])->toHaveCount(2);
    expect($response['order']['total_amount_in_cents'])->toBe(2920);
});

test('items from another order of the same customer are excluded', function (): void {
    $orderId = createViewOrderFixture();
    $customerId = DB::table('orders')->where('id', $orderId)->value('customer_id');
    $otherOrderId = DB::table('orders')->insertGetId([
        'customer_id' => $customerId,
        'order_date' => '2026-10-30',
        'due_date' => '2026-11-10',
    ]);
    DB::table('order_items')->insert(['order_id' => $otherOrderId, 'product_name' => 'Another order item', 'quantity' => 500, 'unit_price' => '4.00']);

    $response = $this->get(route('orders.show', ['order' => $orderId]));

    $response->assertOk()->assertSee('29.20')->assertDontSee('Another order item')->assertDontSee('2,000.00');
});

test('the back link returns to the same search and page', function (): void {
    $orderId = createViewOrderFixture();

    $response = $this->get(route('orders.show', ['order' => $orderId, 'search' => 'Acme & bags', 'page' => 3]));

    $response->assertOk()->assertSee('Back to Orders')
        ->assertSee('href="'.e(route('orders.index', ['search' => 'Acme & bags', 'page' => 3])).'"', false);
});

test('invalid list context uses safe defaults in the back link', function (array $query): void {
    $orderId = createViewOrderFixture();

    $response = $this->get(route('orders.show', ['order' => $orderId] + $query));

    $response->assertOk()->assertSee('href="'.e(route('orders.index', ['search' => '', 'page' => 1])).'"', false);
})->with([
    'no context' => [[]],
    'array values' => [['search' => ['Acme'], 'page' => [2]]],
    'negative page' => [['page' => -1]],
    'non numeric page' => [['page' => 'invalid']],
    'out of range page' => [['page' => '99999999999999999999999']],
    'untrusted return url' => [['return_url' => 'https://example.org']],
]);

test('unknown or invalid order IDs return a not found page with a back link', function (string $orderId): void {
    $response = $this->get(route('orders.show', ['order' => $orderId, 'search' => 'Acme', 'page' => 2]));

    $response->assertNotFound()
        ->assertSee('Order not found')
        ->assertSee('The requested order does not exist or is no longer available.')
        ->assertSee('href="'.e(route('orders.index', ['search' => 'Acme', 'page' => 2])).'"', false)
        ->assertDontSee('Try again');
})->with(['missing order' => '99999', 'zero ID' => '0', 'out of range ID' => '999999999999999999999999']);

test('sample details contain illustrative items that sum exactly to the list total', function (): void {
    $response = $this->get(route('orders.samples.show', ['order' => 1019, 'search' => 'Bloom', 'page' => 1]));

    $response->assertOk()
        ->assertSee('ORD-2026-1019')
        ->assertSee('Bloom Retail')
        ->assertSee('Sample order. Item details are illustrative.')
        ->assertSee('Sample custom packaging')
        ->assertSee('Sample printed labels')
        ->assertSee('1,780.37')
        ->assertSee('1,780.38')
        ->assertSee('3,560.75');
    expect($response['order']['items'])->toHaveCount(2);
    expect($response['order']['total_amount_in_cents'])->toBe(356075);
});

test('unknown sample orders return a not found page', function (): void {
    $this->get(route('orders.samples.show', ['order' => 999]))->assertNotFound()->assertSee('Order not found');
});

test('saved and sample orders with the same ID have distinct detail pages', function (): void {
    createViewOrderFixture(['company_name' => 'Actual customer'], ['id' => 1024]);

    $this->get(route('orders.show', ['order' => 1024]))->assertOk()
        ->assertSee('Actual customer')->assertSee('Plastic bags')->assertDontSee('Northstar Packaging');
    $this->get(route('orders.samples.show', ['order' => 1024]))->assertOk()
        ->assertSee('Northstar Packaging')->assertSee('Sample custom packaging')->assertDontSee('Actual customer');
});

test('the saved order route does not fall back to a sample with the same ID', function (): void {
    $this->get(route('orders.show', ['order' => 1024]))->assertNotFound()->assertSee('Order not found');
});

test('an order without items has an empty state and a zero total', function (): void {
    $orderId = createViewOrderFixture([], [], []);

    $response = $this->get(route('orders.show', ['order' => $orderId]));

    $response->assertOk()->assertSee('No items are recorded for this order.')->assertSee('0.00');
    expect($response['order']['items'])->toBeEmpty();
    expect($response['order']['total_amount_in_cents'])->toBe(0);
});

test('zero prices and fractional currency are included in an exact order total', function (): void {
    $orderId = createViewOrderFixture([], [], [
        ['product_name' => 'Small bags', 'quantity' => 3, 'unit_price' => '0.10'],
        ['product_name' => 'Labels', 'quantity' => 2, 'unit_price' => '0.20'],
        ['product_name' => 'Free sample', 'quantity' => 1, 'unit_price' => '0.00'],
    ]);

    $response = $this->get(route('orders.show', ['order' => $orderId]));

    $response->assertOk()->assertSeeInOrder(['Small bags', '0.10', '0.30', 'Labels', '0.20', '0.40', 'Free sample', '0.00', 'Total amount (PHP)', '0.70']);
    expect($response['order']['total_amount_in_cents'])->toBe(70);
});

test('the details page is read only and ignores query parameters that attempt to change order data', function (): void {
    $orderId = createViewOrderFixture();

    $response = $this->get(route('orders.show', ['order' => $orderId, 'status' => 'Completed', 'total_amount_in_cents' => 1, 'customer_id' => 999]));

    $response->assertOk()->assertSee('In production')->assertSee('29.20')
        ->assertDontSee('<form', false)->assertDontSee('<input', false)->assertDontSee('<select', false)
        ->assertDontSee('Edit order')->assertDontSee('Delete order');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'In production', 'customer_id' => 1]);
    $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'quantity' => 2, 'unit_price' => '9.95']);
});

test('customer details item names and list context are escaped', function (): void {
    $unsafeText = '<script>alert("unsafe")</script>';
    $orderId = createViewOrderFixture([
        'company_name' => $unsafeText,
        'contact_person' => $unsafeText,
        'address' => $unsafeText,
        'contact_number' => '<svg onload="alert(1)">',
    ], [], [['product_name' => $unsafeText, 'quantity' => 1, 'unit_price' => '1.00']]);

    $response = $this->get(route('orders.show', ['order' => $orderId, 'search' => $unsafeText]));

    $response->assertOk()->assertSee($unsafeText)->assertDontSee($unsafeText, false)
        ->assertSee('<svg onload="alert(1)">')->assertDontSee('<svg onload="alert(1)">', false);
});

test('database failure displays a retry and back link without exposing internal error details', function (): void {
    $orderId = createViewOrderFixture();
    Schema::drop('order_items');
    Exceptions::fake();

    $response = $this->get(route('orders.show', ['order' => $orderId, 'search' => 'Acme', 'page' => 2]));

    $response->assertServiceUnavailable()
        ->assertSee('Unable to load order')
        ->assertSee('Order details could not be loaded. Please try again.')
        ->assertSee('Try again')
        ->assertSee('href="'.e(route('orders.show', ['order' => $orderId, 'search' => 'Acme', 'page' => 2])).'"', false)
        ->assertSee('href="'.e(route('orders.index', ['search' => 'Acme', 'page' => 2])).'"', false)
        ->assertDontSee('SQLSTATE')->assertDontSee('no such table');
    Exceptions::assertReported(QueryException::class);
});

test('non numeric order IDs cannot be used to inject a query', function (): void {
    createViewOrderFixture();

    $this->get(route('orders.show', ['order' => '1 OR 1=1']))->assertNotFound()->assertDontSee('Acme Packaging');
});
