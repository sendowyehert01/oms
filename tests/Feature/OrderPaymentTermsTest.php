<?php

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function startPaymentTermsOrder(TestCase $testCase): string
{
    $customer = Customer::factory()->create();
    $key = $testCase->get(route('orders.create'))->assertOk()->viewData('submissionKey');
    $testCase->withCookie(config('session.cookie'), session()->getId());
    $testCase->post(route('orders.creation.select-customer'), [
        'submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customer->id,
    ])->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);

    return $key;
}

/** @return array<string, mixed> */
function paymentTermsOrderData(string $key, mixed $terms = 'Net 30'): array
{
    return [
        'submission_key' => $key, 'order_date' => '2026-11-03', 'terms_of_payment' => $terms, 'status' => 'Pending',
        'items' => [['product_name' => 'Printed bags', 'quantity' => 2, 'unit_price' => '1.25']],
    ];
}

test('the order form replaces due date with a required payment terms text input', function (): void {
    $key = startPaymentTermsOrder($this);
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();

    $response->assertSee('Terms of Payment')->assertSee('name="terms_of_payment"', false)
        ->assertDontSee('name="due_date"', false)->assertDontSee('Due date');
    expect($response->getContent())->toMatch('/name="terms_of_payment"\s+type="text"[^>]+required[^>]+maxlength="255"/');
});

test('free text payment terms save without a due date and appear in order details', function (string $terms): void {
    $key = startPaymentTermsOrder($this);
    $data = paymentTermsOrderData($key, $terms);
    $data['due_date'] = 'a stale value from an older form';

    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $order = Order::query()->firstOrFail();
    expect($order->terms_of_payment)->toBe($terms);
    expect($order->due_date)->toBeNull();
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('order_items', 1);
    $this->get(route('orders.show', ['order' => $order->id]))->assertOk()->assertSee('Terms of Payment')
        ->assertSee($terms)->assertSee('2.50')->assertDontSee('Legacy due date');
})->with(['Cash on delivery', 'Net 30', '50% down payment, balance upon delivery', str_repeat('x', 255)]);

test('invalid payment terms are rejected without creating an order', function (mixed $terms): void {
    $key = startPaymentTermsOrder($this);
    $this->post(route('orders.store'), paymentTermsOrderData($key, $terms))
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key])->assertSessionHasErrors('terms_of_payment');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Please correct the following');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
    'array' => [['invalid']],
    'number' => [30],
    'overlong' => [str_repeat('x', 256)],
]);

test('payment terms survive back navigation and validation errors', function (): void {
    $key = startPaymentTermsOrder($this);
    $data = paymentTermsOrderData($key, '50% down payment, balance upon delivery');
    $this->post(route('orders.creation.back'), $data)->assertRedirect();
    $this->post(route('orders.creation.select-customer'), [
        'submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => Customer::query()->value('id'),
    ])->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()
        ->assertSee('value="50% down payment, balance upon delivery"', false);

    $data['items'][0]['quantity'] = 0;
    $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()
        ->assertSee('value="50% down payment, balance upon delivery"', false);
});

test('payment terms are trimmed and escaped in retained input and order details', function (): void {
    $key = startPaymentTermsOrder($this);
    $terms = '<script>alert("payment terms")</script>';
    $data = paymentTermsOrderData($key, '  '.$terms.'  ');
    $data['items'][0]['quantity'] = 0;
    $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee($terms)->assertDontSee($terms, false);

    $data['items'][0]['quantity'] = 1;
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index');
    $order = Order::query()->firstOrFail();
    expect($order->terms_of_payment)->toBe($terms);
    $this->get(route('orders.show', ['order' => $order->id]))->assertOk()->assertSee($terms)->assertDontSee($terms, false);
});

test('payment terms migration preserves historical due dates without inventing terms', function (): void {
    $migration = require database_path('migrations/2026_11_03_063534_add_terms_of_payment_to_orders_table.php');
    $migration->down();
    Schema::table('orders', function (Blueprint $table): void {
        $table->date('due_date')->nullable(false)->change();
    });
    $order = Order::factory()->make(['order_date' => '2026-10-31', 'due_date' => '2026-11-07']);
    unset($order->terms_of_payment);
    $order->save();
    $migration->up();

    $order->refresh();
    expect($order->due_date->toDateString())->toBe('2026-11-07');
    expect($order->terms_of_payment)->toBeNull();
    $this->get(route('orders.show', ['order' => $order->id]))->assertOk()->assertSee('Terms of Payment')
        ->assertSee('Not specified')->assertSee('Legacy due date')->assertSee('Nov 7, 2026');
    expect(collect(Schema::getIndexes('orders'))->pluck('name'))->toContain('orders_due_date_index');
});

test('a historical order without terms or a due date still renders', function (): void {
    $order = Order::factory()->create(['terms_of_payment' => null, 'due_date' => null]);
    $this->get(route('orders.show', ['order' => $order->id]))->assertOk()
        ->assertSee('Not specified')->assertDontSee('Legacy due date');
});

test('payment terms migration can be removed and reapplied with undated orders', function (): void {
    $order = Order::factory()->create(['due_date' => null]);
    $migration = require database_path('migrations/2026_11_03_063534_add_terms_of_payment_to_orders_table.php');
    $migration->down();
    expect(Schema::hasColumn('orders', 'terms_of_payment'))->toBeFalse();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'due_date' => null]);
    $migration->up();
    expect($order->refresh()->terms_of_payment)->toBeNull();
    expect($order->due_date)->toBeNull();
});
