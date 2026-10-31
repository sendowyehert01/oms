<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\OrderEntries;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\OrderItemSeeder;
use Database\Seeders\OrderSeeder;
use Database\Seeders\PaymentSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('model relationships resolve only the owning customer order items and payments', function (): void {
    $customer = Customer::factory()->create();
    $order = Order::factory()->for($customer)->hasItems(2)->hasPayments(1)->create();
    Order::factory()->hasItems(1)->hasPayments(1)->create();

    $order->refresh()->load(['customer', 'items.order', 'payments.order']);

    expect($customer->orders->modelKeys())->toBe([$order->id]);
    expect($order->customer->id)->toBe($customer->id);
    expect($order->items)->toHaveCount(2);
    expect($order->payments)->toHaveCount(1);
    expect($order->items->pluck('order.id')->all())->toBe([$order->id, $order->id]);
    expect($order->payments->first()->order->id)->toBe($order->id);
});

test('date decimal and quantity casts preserve persisted values', function (): void {
    $order = Order::factory()->create(['order_date' => '2024-02-29', 'due_date' => '2024-03-07']);
    $item = OrderItem::factory()->for($order)->create(['quantity' => '3', 'unit_price' => '0.10']);
    $payment = Payment::factory()->for($order)->create(['payment_date' => '2024-03-01', 'amount' => '25.5']);

    $order->refresh();
    $item->refresh();
    $payment->refresh();

    expect($order->order_date->toDateString())->toBe('2024-02-29');
    expect($order->due_date->toDateString())->toBe('2024-03-07');
    expect($item->quantity)->toBe(3);
    expect($item->unit_price)->toBe('0.10');
    expect($payment->payment_date->toDateString())->toBe('2024-03-01');
    expect($payment->amount)->toBe('25.50');
});

test('mass assignment cannot replace model IDs or creation timestamps', function (string $modelClass): void {
    $record = $modelClass::factory()->create();
    $originalId = $record->id;
    $originalCreatedAt = $record->created_at->toDateTimeString();

    $record->fill(['id' => 999, 'created_at' => '1970-01-01 00:00:00'])->save();

    $this->assertDatabaseHas($record->getTable(), ['id' => $originalId, 'created_at' => $originalCreatedAt]);
    $this->assertDatabaseMissing($record->getTable(), ['id' => 999]);
})->with([
    'customer' => [Customer::class],
    'order' => [Order::class],
    'item' => [OrderItem::class],
    'payment' => [Payment::class],
]);

test('listing saved orders uses a constant query count as the number of orders grows', function (int $orderCount): void {
    Order::factory()->count($orderCount)->hasItems(2)->create();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->get(route('orders.index'));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()->assertSee('Saved orders')->assertDontSee('Sample orders');
    expect($response['orders']->total())->toBe($orderCount);
    expect($queries)->toHaveCount(4);
})->with([
    'single order' => [1],
    'multiple pages' => [12],
]);

test('creating an order dispatches model events for its customer order and items', function (): void {
    $customerCreated = 'eloquent.created: '.Customer::class;
    $orderCreated = 'eloquent.created: '.Order::class;
    $itemCreated = 'eloquent.created: '.OrderItem::class;
    Event::fake([$customerCreated, $orderCreated, $itemCreated]);

    $orderId = app(OrderEntries::class)->create([
        'submission_key' => (string) Str::uuid(),
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
    ]);

    Event::assertDispatched($customerCreated, 1);
    Event::assertDispatched($orderCreated, 1);
    Event::assertDispatched($itemCreated, 2);
    $this->assertDatabaseHas('orders', ['id' => $orderId, 'customer_id' => 1]);
    $this->assertDatabaseCount('order_items', 2);
});

test('confirmed deletion dispatches the order model event and preserves the customer', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    $orderDeleted = 'eloquent.deleted: '.Order::class;
    $customerDeleted = 'eloquent.deleted: '.Customer::class;
    Event::fake([$orderDeleted, $customerDeleted]);

    $response = $this->deleteJson(route('orders.destroy', ['order' => $order]), ['confirmed' => true]);

    $response->assertOk();
    Event::assertDispatched($orderDeleted, 1);
    Event::assertNotDispatched($customerDeleted);
    $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    $this->assertDatabaseMissing('order_items', ['order_id' => $order->id]);
    $this->assertDatabaseHas('customers', ['id' => $order->customer_id]);
});

test('standalone seeders create valid model relationships', function (string $seederClass, int $customerCount, int $orderCount, int $itemCount, int $paymentCount): void {
    $this->seed($seederClass);

    $this->assertDatabaseCount('customers', $customerCount);
    $this->assertDatabaseCount('orders', $orderCount);
    $this->assertDatabaseCount('order_items', $itemCount);
    $this->assertDatabaseCount('payments', $paymentCount);
})->with([
    'customers' => [CustomerSeeder::class, 5, 0, 0, 0],
    'orders with items' => [OrderSeeder::class, 5, 5, 10, 0],
    'items with orders' => [OrderItemSeeder::class, 5, 5, 5, 0],
    'payments with orders and items' => [PaymentSeeder::class, 5, 5, 10, 5],
]);
