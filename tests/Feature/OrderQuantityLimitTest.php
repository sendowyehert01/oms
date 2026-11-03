<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function startQuantityLimitOrder(TestCase $testCase): string
{
    $customer = Customer::factory()->create();
    $key = $testCase->get(route('orders.create'))->assertOk()->viewData('submissionKey');
    $testCase->withCookie(config('session.cookie'), session()->getId());
    $testCase->post(route('orders.creation.select-customer'), [
        'submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customer->id,
    ])->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);

    return $key;
}

/**
 * @param  array<int, array{product_name: string, quantity: int, unit_price: string}>  $items
 * @return array<string, mixed>
 */
function quantityLimitOrderData(string $key, array $items): array
{
    return [
        'submission_key' => $key, 'order_date' => '2026-11-03', 'terms_of_payment' => 'Net 30',
        'status' => 'Pending', 'items' => $items,
    ];
}

test('initial and template item rows show the 100 million limit', function (): void {
    $key = startQuantityLimitOrder($this);
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    $response->assertSee('quantities from 1 to 100,000,000');
    expect(substr_count($response->getContent(), 'max="100000000"'))->toBe(2);
});

test('large quantities up to 100 million save and display exact cents', function (int $quantity, int $cents, string $amount): void {
    $key = startQuantityLimitOrder($this);
    $data = quantityLimitOrderData($key, [['product_name' => 'High volume bags', 'quantity' => $quantity, 'unit_price' => '999999.99']]);
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $item = OrderItem::query()->firstOrFail();
    expect($item->quantity)->toBe($quantity);
    $details = $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()
        ->assertSee(number_format($quantity))->assertSee($amount);
    expect($details['order']['items'][0]['subtotal_in_cents'])->toBe($cents);
    expect($details['order']['total_amount_in_cents'])->toBe($cents);
    $list = $this->get(route('orders.index'))->assertOk()->assertSee($amount);
    expect($list['orders']->first()['total_amount_in_cents'])->toBe($cents);

    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 1);
})->with([
    'above previous limit' => [100001, 10_000_099_899_999, '100,000,998,999.99'],
    'one cent beyond safe integer range' => [99999999, 9_999_999_800_000_001, '99,999,998,000,000.01'],
    'exact maximum' => [100000000, 9_999_999_900_000_000, '99,999,999,000,000.00'],
]);

test('a quantity above 100 million is rejected and retained for correction', function (): void {
    $key = startQuantityLimitOrder($this);
    $this->post(route('orders.store'), quantityLimitOrderData($key, [
        ['product_name' => 'High volume bags', 'quantity' => 100000001, 'unit_price' => '1.25'],
    ]))->assertSessionHasErrors(['items.0.quantity' => 'Each item quantity must not exceed 100,000,000.']);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()
        ->assertSee('value="100000001"', false)->assertSee('Each item quantity must not exceed 100,000,000.');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('an order with 100 high volume items retains exact total cents', function (int $lastQuantity, int $totalCents, string $amount): void {
    $key = startQuantityLimitOrder($this);
    $items = array_fill(0, 100, ['product_name' => 'High volume bags', 'quantity' => 100000000, 'unit_price' => '999999.99']);
    $items[99]['quantity'] = $lastQuantity;
    $this->post(route('orders.store'), quantityLimitOrderData($key, $items))->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('order_items', 100);
    $details = $this->get(route('orders.show', ['order' => Order::query()->value('id')]))->assertOk()->assertSee($amount);
    expect($details['order']['total_amount_in_cents'])->toBe($totalCents);
    $list = $this->get(route('orders.index'))->assertOk()->assertSee($amount);
    expect($list['orders']->first()['total_amount_in_cents'])->toBe($totalCents);
})->with([
    'all quantities at maximum' => [100000000, 999_999_990_000_000_000, '9,999,999,900,000,000.00'],
    'one cent remainder' => [99999999, 999_999_989_900_000_001, '9,999,999,899,000,000.01'],
]);

test('money formatting preserves cents for ordinary and very large values', function (int $cents, string $expected): void {
    $this->blade('<x-money :cents="$cents" />', ['cents' => $cents])->assertSee($expected);
})->with([
    [0, '0.00'], [1, '0.01'], [70, '0.70'], [123456, '1,234.56'],
    [999_999_989_900_000_001, '9,999,999,899,000,000.01'],
]);
