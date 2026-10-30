<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

pest()->use(DatabaseMigrations::class);

/**
 * @return array{customer_id: int, order_id: int}
 */
function createOrderSchemaFixture(): array
{
    $customerId = DB::table('customers')->insertGetId([
        'company_name' => 'Example Packaging Customer',
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
    ]);

    $orderId = DB::table('orders')->insertGetId([
        'customer_id' => $customerId,
        'order_date' => '2026-10-30',
        'due_date' => '2026-11-29',
    ]);

    return ['customer_id' => $customerId, 'order_id' => $orderId];
}

test('an order can store several packaging items and payment entries', function (): void {
    $fixture = createOrderSchemaFixture();

    DB::table('order_items')->insert([
        [
            'order_id' => $fixture['order_id'],
            'product_name' => 'Plastic bags',
            'size' => '10 x 15 inches',
            'customization_notes' => 'Blue bags with a printed logo',
            'quantity' => 1000,
            'unit_price' => '3.00',
        ],
    ]);
    DB::table('order_items')->insert([
        'order_id' => $fixture['order_id'],
        'product_name' => 'Zip bags',
        'quantity' => 500,
        'unit' => 'packs',
        'unit_price' => '4.00',
    ]);

    DB::table('payments')->insert([
        [
            'order_id' => $fixture['order_id'],
            'payment_date' => '2026-10-30',
            'amount' => '2000.00',
            'payment_type' => 'down_payment',
        ],
        [
            'order_id' => $fixture['order_id'],
            'payment_date' => '2026-11-15',
            'amount' => '3000.00',
            'payment_type' => 'full_payment',
        ],
    ]);

    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseHas('customers', [
        'id' => $fixture['customer_id'],
        'contact_number' => '09123456789',
    ]);
    $this->assertDatabaseHas('order_items', [
        'order_id' => $fixture['order_id'],
        'product_name' => 'Plastic bags',
        'size' => '10 x 15 inches',
        'customization_notes' => 'Blue bags with a printed logo',
        'unit' => 'pcs',
        'unit_price' => '3.00',
    ]);
    $this->assertDatabaseHas('order_items', [
        'order_id' => $fixture['order_id'],
        'product_name' => 'Zip bags',
        'size' => null,
        'customization_notes' => null,
        'unit' => 'packs',
    ]);
    $this->assertDatabaseHas('payments', [
        'order_id' => $fixture['order_id'],
        'amount' => '2000.00',
        'payment_type' => 'down_payment',
    ]);
    $this->assertDatabaseHas('payments', [
        'order_id' => $fixture['order_id'],
        'amount' => '3000.00',
        'payment_type' => 'full_payment',
    ]);
});

test('a customer with orders cannot be deleted', function (): void {
    $fixture = createOrderSchemaFixture();

    expect(fn (): int => DB::table('customers')->where('id', $fixture['customer_id'])->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('customers', ['id' => $fixture['customer_id']]);
    $this->assertDatabaseHas('orders', ['id' => $fixture['order_id']]);
});

test('an order with a payment cannot be deleted or lose its items', function (): void {
    $fixture = createOrderSchemaFixture();
    DB::table('order_items')->insert([
        'order_id' => $fixture['order_id'],
        'product_name' => 'Plastic bags',
        'quantity' => 100,
        'unit_price' => '1.25',
    ]);
    DB::table('payments')->insert([
        'order_id' => $fixture['order_id'],
        'payment_date' => '2026-10-30',
        'amount' => '25.00',
        'payment_type' => 'down_payment',
    ]);

    expect(fn (): int => DB::table('orders')->where('id', $fixture['order_id'])->delete())
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('orders', ['id' => $fixture['order_id']]);
    $this->assertDatabaseHas('order_items', ['order_id' => $fixture['order_id']]);
    $this->assertDatabaseHas('payments', ['order_id' => $fixture['order_id']]);
});

test('deleting an unpaid order removes only its items and retains the customer', function (): void {
    $fixture = createOrderSchemaFixture();
    $otherFixture = createOrderSchemaFixture();
    DB::table('order_items')->insert([
        [
            'order_id' => $fixture['order_id'],
            'product_name' => 'Plastic bags',
            'quantity' => 100,
            'unit_price' => '1.25',
        ],
        [
            'order_id' => $fixture['order_id'],
            'product_name' => 'Zip bags',
            'quantity' => 50,
            'unit_price' => '2.00',
        ],
        [
            'order_id' => $otherFixture['order_id'],
            'product_name' => 'Packaging rolls',
            'quantity' => 2,
            'unit_price' => '150.00',
        ],
    ]);

    DB::table('orders')->where('id', $fixture['order_id'])->delete();

    $this->assertDatabaseMissing('orders', ['id' => $fixture['order_id']]);
    $this->assertDatabaseMissing('order_items', ['order_id' => $fixture['order_id']]);
    $this->assertDatabaseHas('customers', ['id' => $fixture['customer_id']]);
    $this->assertDatabaseHas('orders', ['id' => $otherFixture['order_id']]);
    $this->assertDatabaseCount('order_items', 1);
    $this->assertDatabaseHas('order_items', ['order_id' => $otherFixture['order_id']]);
});

test('order records reject missing parent records', function (string $table, array $attributes): void {
    expect(fn (): bool => DB::table($table)->insert($attributes))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount($table, 0);
})->with([
    'order without a customer' => [
        'orders',
        ['customer_id' => 999, 'order_date' => '2026-10-30', 'due_date' => '2026-11-29'],
    ],
    'item without an order' => [
        'order_items',
        ['order_id' => 999, 'product_name' => 'Plastic bags', 'quantity' => 100, 'unit_price' => '1.25'],
    ],
    'payment without an order' => [
        'payments',
        ['order_id' => 999, 'payment_date' => '2026-10-30', 'amount' => '25.00', 'payment_type' => 'down_payment'],
    ],
]);

test('order migrations can be rolled back and reapplied', function (): void {
    $this->artisan('migrate:rollback', ['--database' => 'sqlite', '--no-interaction' => true])
        ->assertSuccessful();

    $this->assertDatabaseCount('migrations', 0);

    $this->artisan('migrate', ['--database' => 'sqlite', '--no-interaction' => true])
        ->assertSuccessful();

    $fixture = createOrderSchemaFixture();

    $this->assertDatabaseHas('orders', [
        'id' => $fixture['order_id'],
        'customer_id' => $fixture['customer_id'],
    ]);
});
