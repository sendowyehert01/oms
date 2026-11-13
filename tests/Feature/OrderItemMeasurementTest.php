<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function startMeasurementOrder(TestCase $testCase): string
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
 * @param  array<int, array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function measurementOrderData(string $key, array $items): array
{
    return ['submission_key' => $key, 'order_date' => '2026-11-03', 'terms_of_payment' => 'Net 30', 'status' => 'Pending', 'items' => $items];
}

/** @return array<string, mixed> */
function measurementItemData(mixed $amount = '2.50', mixed $unit = 'kgs', string $price = '1.01'): array
{
    return ['product_name' => 'Measured bags', 'quantity' => $amount, 'unit' => $unit, 'unit_price' => $price];
}

test('measurement form has numeric inputs and unit selectors for initial and added rows', function (): void {
    $key = startMeasurementOrder($this);
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    $response->assertSee('Unit of Measurement')->assertSee('name="items[0][unit]"', false)
        ->assertSee('name="items[__INDEX__][unit]"', false)->assertSee('value="pcs"', false)->assertSee('value="kgs"', false)
        ->assertSee('Price per piece.')->assertSee('Order items with quantities, unit prices, subtotals, and actions');
    expect($response->getContent())->toMatch('/name="items\[0\]\[quantity\]"\s+type="number"/');
});

test('piece and weight measurements save independently as normalized text with accurate totals', function (): void {
    $key = startMeasurementOrder($this);
    $data = measurementOrderData($key, [
        2 => [...measurementItemData(100000, 'pcs', '1.25'), 'product_name' => 'Piece bags'],
        7 => [...measurementItemData('2.50', 'kgs', '1.50'), 'product_name' => 'Weight bags'],
    ]);
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('order_items', ['product_name' => 'Piece bags', 'quantity' => '100000 pcs', 'unit' => 'pcs']);
    $this->assertDatabaseHas('order_items', ['product_name' => 'Weight bags', 'quantity' => '2.5 kgs', 'unit' => 'kgs']);
    $order = Order::query()->firstOrFail();
    $details = $this->get(route('orders.show', ['order' => $order->id]))->assertOk()
        ->assertSeeInOrder(['Piece bags', '100,000 pcs', 'Weight bags', '2.5 kgs'])->assertSee('125,003.75');
    expect($details['order']['total_amount_in_cents'])->toBe(12_500_375);
    $this->get(route('orders.index'))->assertOk()->assertSee('125,003.75');
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
});

test('weight subtotals round half up to a cent and preserve exact supported values', function (mixed $amount, string $price, string $stored, int $cents, string $formatted): void {
    $key = startMeasurementOrder($this);
    $this->post(route('orders.store'), measurementOrderData($key, [measurementItemData($amount, 'kgs', $price)]))
        ->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])->assertSessionHasNoErrors();
    $item = OrderItem::query()->firstOrFail();
    expect($item->quantity)->toBe($stored);
    $details = $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()->assertSee($formatted);
    expect($details['order']['total_amount_in_cents'])->toBe($cents);
    $this->get(route('orders.index'))->assertOk()->assertSee($formatted);
})->with([
    'round a half cent up' => ['0.01', '0.50', '0.01 kgs', 1, '0.01'],
    'round below a half cent down' => ['0.01', '0.49', '0.01 kgs', 0, '0.00'],
    'normalize numeric JSON weight' => [2.5, '1.01', '2.5 kgs', 253, '2.53'],
    'normalize whole weight' => ['0002.00', '1.00', '2 kgs', 200, '2.00'],
    'maximum weight and price' => ['100000000', '999999.99', '100000000 kgs', 9_999_999_900_000_000, '99,999,999,000,000.00'],
    'large fractional weight' => ['99999999.99', '999999.99', '99999999.99 kgs', 9_999_999_899_000_000, '99,999,998,990,000.00'],
]);

test('order totals sum the rounded item subtotals', function (): void {
    $key = startMeasurementOrder($this);
    $this->post(route('orders.store'), measurementOrderData($key, [measurementItemData('0.01', 'kgs', '0.50'), measurementItemData('0.01', 'kgs', '0.50')]))
        ->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')]);
    $details = $this->get(route('orders.show', ['order' => Order::query()->value('id')]))->assertOk();
    expect($details['order']['total_amount_in_cents'])->toBe(2);
    expect(array_column($details['order']['items'], 'subtotal_in_cents'))->toBe([1, 1]);
    $this->get(route('orders.index'))->assertOk()->assertSee('0.02');
});

test('invalid measurement amounts are rejected and can be displayed again', function (mixed $amount, string $unit): void {
    $key = startMeasurementOrder($this);
    $this->post(route('orders.store'), measurementOrderData($key, [measurementItemData($amount, $unit)]))
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key])->assertSessionHasErrors('items.0.quantity');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Please correct the following');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'fractional pcs' => ['1.5', 'pcs'], 'zero pcs' => [0, 'pcs'], 'negative pcs' => [-1, 'pcs'],
    'over-limit pcs' => [100000001, 'pcs'], 'signed pcs' => ['+2', 'pcs'],
    'zero weight' => ['0', 'kgs'], 'negative weight' => ['-0.01', 'kgs'],
    'too-small weight' => ['0.001', 'kgs'], 'too-precise weight' => ['1.005', 'kgs'],
    'over-limit weight' => ['100000000.01', 'kgs'], 'nonnumeric weight' => ['abc', 'kgs'],
    'exponential weight' => ['1e2', 'kgs'], 'already combined text' => ['100 pcs', 'pcs'],
    'array amount' => [['invalid'], 'kgs'], 'blank weight' => ['', 'kgs'], 'null pcs' => [null, 'pcs'],
]);

test('missing amounts are required for each unit', function (string $unit): void {
    $key = startMeasurementOrder($this);
    $item = measurementItemData(1, $unit);
    unset($item['quantity']);
    $this->post(route('orders.store'), measurementOrderData($key, [$item]))->assertSessionHasErrors('items.0.quantity');
    $this->assertDatabaseCount('orders', 0);
})->with(['pcs', 'kgs']);

test('invalid units are rejected and retained inputs render safely', function (mixed $unit): void {
    $key = startMeasurementOrder($this);
    $this->post(route('orders.store'), measurementOrderData($key, [measurementItemData(1, $unit)]))
        ->assertSessionHasErrors('items.0.unit');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    $this->assertDatabaseCount('orders', 0);
})->with(['other' => ['packs'], 'singular' => ['kg'], 'blank' => [''], 'null' => [null], 'array' => [['pcs']]]);

test('amounts and units survive back navigation and validation errors', function (): void {
    $key = startMeasurementOrder($this);
    $data = measurementOrderData($key, [measurementItemData('2.50', 'kgs')]);
    $this->post(route('orders.creation.back'), $data)->assertRedirect();
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => Customer::query()->value('id')]);
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('value="2.50"', false);
    expect($response->getContent())->toMatch('/value="kgs"\s+selected/');
    $data['items'][0]['unit_price'] = -1;
    $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.unit_price');
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('value="2.50"', false)->assertSee('Price per kg.');
    expect($response->getContent())->toMatch('/value="kgs"\s+selected/');
});

test('older requests without a unit default to pcs', function (): void {
    $key = startMeasurementOrder($this);
    $item = measurementItemData(100000, 'pcs');
    unset($item['unit']);
    $this->post(route('orders.store'), measurementOrderData($key, [$item]))->assertRedirectToRoute('orders.payments.create', ['order' => Order::query()->latest('id')->value('id')])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('order_items', ['quantity' => '100000 pcs', 'unit' => 'pcs']);
});

test('legacy numeric measurements keep their original units and totals after migration', function (): void {
    Schema::table('order_items', function (Blueprint $table): void {
        $table->unsignedInteger('quantity')->change();
    });
    $item = OrderItem::factory()->withSpecifications()->create(['quantity' => 500, 'unit' => 'packs', 'unit_price' => '4.00']);
    $migration = require database_path('migrations/2026_11_03_081638_store_order_item_measurements_as_text.php');
    $migration->up();
    $item->refresh();
    expect($item->quantity)->toBe('500');
    expect($item->unit)->toBe('packs');
    $details = $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()->assertSee('500 packs')->assertSee('LDPE / transparent')->assertSee('2,000.00');
    expect($details['order']['total_amount_in_cents'])->toBe(200000);
});

test('rollback retains text measurements without dropping units or fractional weights', function (): void {
    $item = OrderItem::factory()->create(['quantity' => '2.5 kgs', 'unit' => 'kgs']);
    $migration = require database_path('migrations/2026_11_03_081638_store_order_item_measurements_as_text.php');
    $migration->down();
    $migration->up();
    expect($item->refresh()->quantity)->toBe('2.5 kgs');
    expect($item->measurement()->displayValue())->toBe('2.5 kgs');
});

test('legacy unit text is escaped on order details', function (): void {
    $unit = '<b>packs</b>';
    $item = OrderItem::factory()->create(['quantity' => '100', 'unit' => $unit]);
    $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()->assertSee('100 '.$unit)->assertDontSee($unit, false);
});
