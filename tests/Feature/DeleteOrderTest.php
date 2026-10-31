<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function createDeleteOrderFixture(?int $customerId = null, ?int $orderId = null, string $customerName = 'Acme Packaging'): int
{
    $customerId ??= Customer::factory()->create([
        'company_name' => $customerName,
        'contact_person' => 'Alex Cruz',
        'address' => '123 Market Street',
        'contact_number' => '09123456789',
    ])->id;

    $order = Order::factory()->create(array_filter([
        'id' => $orderId,
        'customer_id' => $customerId,
        'order_date' => '2026-10-31',
        'due_date' => '2026-11-07',
        'status' => 'Pending',
    ], fn (int|string|null $value): bool => $value !== null));

    foreach ([
        ['product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95'],
        ['product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10'],
    ] as $item) {
        OrderItem::factory()->for($order)->create($item);
    }

    return $order->id;
}

test('saved orders have delete actions preserving the list context and an accessible confirmation dialog', function (): void {
    $orderId = createDeleteOrderFixture();

    $response = $this->get(route('orders.index', ['search' => 'Acme', 'page' => 99]));

    $response->assertOk()
        ->assertSee('data-order-number="ORD-2026-0001"', false)
        ->assertSee('data-delete-url="'.e(route('orders.destroy', ['order' => $orderId, 'search' => 'Acme', 'page' => 1])).'"', false)
        ->assertSee('aria-label="Delete order ORD-2026-0001"', false)
        ->assertSee('<dialog id="delete-order-dialog"', false)
        ->assertSee('aria-describedby="delete-order-dialog-description"', false)
        ->assertSee('Cancel')
        ->assertSee('Confirm Delete');
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
});

test('deletion returns 422 without explicit confirmation and leaves the order intact', function (array $payload): void {
    $orderId = createDeleteOrderFixture();

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), $payload);

    $response->assertUnprocessable()
        ->assertInvalid(['confirmed' => 'Confirm the order deletion before proceeding.']);
    $this->assertDatabaseHas('orders', ['id' => $orderId]);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('order_items', 2);
})->with([
    'missing confirmation' => [[]],
    'cancelled confirmation' => [['confirmed' => false]],
]);

test('confirmed deletion removes only the selected order and its items while retaining customers and other orders', function (): void {
    $orderId = createDeleteOrderFixture();
    $sharedCustomerOrderId = createDeleteOrderFixture(1);
    $otherOrderId = createDeleteOrderFixture(customerName: 'Other customer');

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), [
        'confirmed' => true,
        'order_id' => $otherOrderId,
        'customer_id' => 2,
    ]);

    $response->assertOk()->assertJsonPath('message', 'Order ORD-2026-0001 deleted successfully.');
    $this->assertDatabaseMissing('orders', ['id' => $orderId]);
    $this->assertDatabaseMissing('order_items', ['order_id' => $orderId]);
    $this->assertDatabaseCount('customers', 2);
    $this->assertDatabaseHas('customers', ['id' => 1, 'company_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz', 'address' => '123 Market Street', 'contact_number' => '09123456789']);
    $this->assertDatabaseHas('orders', ['id' => $sharedCustomerOrderId, 'customer_id' => 1, 'status' => 'Pending']);
    $this->assertDatabaseHas('orders', ['id' => $otherOrderId, 'customer_id' => 2, 'status' => 'Pending']);
    $this->assertDatabaseCount('order_items', 4);
    $this->assertDatabaseHas('order_items', ['order_id' => $sharedCustomerOrderId, 'quantity' => 2, 'unit_price' => '9.95']);
    $this->assertDatabaseHas('order_items', ['order_id' => $otherOrderId, 'quantity' => 3, 'unit_price' => '3.10']);
    expect($response->json('html'))->toContain('ORD-2026-0002', 'ORD-2026-0003', 'Showing 1 to 2 of 2 orders')->not->toContain('ORD-2026-0001');
    $this->get(route('orders.show', ['order' => $orderId]))->assertNotFound();
});

test('orders with payments return 409 and retain their order items customer and payment', function (): void {
    $orderId = createDeleteOrderFixture();
    Payment::factory()->create(['order_id' => $orderId, 'payment_date' => '2026-10-31', 'amount' => '10.00', 'payment_type' => 'Cash']);

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertConflict()->assertJsonPath('message', 'Orders with recorded payments cannot be deleted.');
    $this->assertDatabaseHas('orders', ['id' => $orderId]);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('payments', ['order_id' => $orderId, 'amount' => '10.00']);
});

test('missing invalid and sample-only saved IDs return 404 without deleting other orders', function (string $orderId): void {
    createDeleteOrderFixture();

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertNotFound();
    $this->assertDatabaseHas('orders', ['id' => 1]);
    $this->assertDatabaseCount('order_items', 2);
})->with([
    'missing ID' => ['999'],
    'sample ID' => ['1024'],
    'zero' => ['0'],
    'integer overflow' => ['9999999999999999999999'],
    'query injection' => ['1 OR 1=1'],
]);

test('repeated deletion returns 404 with clear feedback and preserves unrelated records', function (): void {
    $orderId = createDeleteOrderFixture();
    createDeleteOrderFixture(1);
    $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true])->assertOk();

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertNotFound()->assertJsonPath('message', 'The order does not exist or has already been deleted.');
    $this->assertDatabaseHas('orders', ['id' => 2]);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('customers', 1);
});

test('deleting the final saved order shows an empty saved list rather than bringing back samples', function (): void {
    $orderId = createDeleteOrderFixture();

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertOk();
    expect($response->json('html'))->toContain('No orders yet', 'Showing 0 to 0 of 0 orders')->not->toContain('ORD-2026-1024', 'data-delete-order');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->assertDatabaseCount('customers', 1);
    $this->get(route('orders.index'))->assertOk()->assertSee('Saved orders')->assertSee('No orders yet')->assertDontSee('Sample orders');
});

test('deleting the only order on the last filtered page returns the previous page and preserves search', function (): void {
    $orderId = createDeleteOrderFixture();
    for ($index = 0; $index < 10; $index++) {
        createDeleteOrderFixture(1);
    }
    createDeleteOrderFixture(customerName: 'Unrelated company');

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId, 'search' => 'Acme', 'page' => 2]), ['confirmed' => true]);

    $response->assertOk();
    expect($response->json('html'))->toContain(
        'data-list-url="'.e(route('orders.index', ['search' => 'Acme', 'page' => 1])).'"',
        'value="Acme"',
        'Showing 1 to 10 of 10 orders',
        'ORD-2026-0011',
        'ORD-2026-0002',
    )->not->toContain('ORD-2026-0001', 'Unrelated company', 'rel="next"');
    $this->assertDatabaseCount('orders', 11);
});

test('deleting the only search match shows no matching orders while unrelated orders remain', function (): void {
    $orderId = createDeleteOrderFixture();
    createDeleteOrderFixture(customerName: 'Other company');

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId, 'search' => 'Acme', 'page' => 4]), ['confirmed' => true]);

    $response->assertOk();
    expect($response->json('html'))->toContain('No matching orders', 'value="Acme"', 'Showing 0 to 0 of 0 orders')->not->toContain('Other company');
    $this->assertDatabaseHas('orders', ['id' => 2]);
    $this->assertDatabaseCount('orders', 1);
});

test('normal confirmed deletion redirects with success feedback and the current list context', function (): void {
    $orderId = createDeleteOrderFixture();

    $response = $this->delete(route('orders.destroy', ['order' => $orderId, 'search' => 'Acme', 'page' => 2]), ['confirmed' => 'yes']);

    $response->assertRedirectToRoute('orders.index', ['search' => 'Acme', 'page' => 2])
        ->assertSessionHas('success', 'Order ORD-2026-0001 deleted successfully.');
    $this->assertDatabaseMissing('orders', ['id' => $orderId]);
});

test('normal failed deletion redirects with an error instead of success', function (): void {
    $response = $this->delete(route('orders.destroy', ['order' => 999, 'search' => 'Acme', 'page' => 2]), ['confirmed' => true]);

    $response->assertRedirectToRoute('orders.index', ['search' => 'Acme', 'page' => 2])
        ->assertSessionHas('error', 'The order does not exist or has already been deleted.')
        ->assertSessionMissing('success');
});

test('sample deletion removes the selected sample from the session list and details without changing the database', function (): void {
    $this->get(route('orders.index', ['search' => 'Northstar']))->assertOk()
        ->assertSee('data-delete-url="'.e(route('orders.samples.destroy', ['order' => 1024, 'search' => 'Northstar', 'page' => 1])).'"', false);
    $this->withCookie(config('session.cookie'), session()->getId());

    $response = $this->deleteJson(route('orders.samples.destroy', ['order' => 1024, 'search' => 'Northstar']), ['confirmed' => true]);

    $response->assertOk()->assertJsonPath('message', 'Order ORD-2026-1024 deleted successfully.')
        ->assertSessionHas('orders.deleted_samples', [1024]);
    expect($response->json('html'))->toContain('ORD-2026-1020', 'Showing 1 to 5 of 5 orders')->not->toContain('ORD-2026-1024');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('order_items', 0);
    $this->get(route('orders.index'))->assertOk()->assertDontSee('data-order-number="ORD-2026-1024"', false)->assertSee('Showing 1 to 10 of 23 orders');
    $this->get(route('orders.samples.show', ['order' => 1024]))->assertNotFound();
    $this->deleteJson(route('orders.samples.destroy', ['order' => 1024]), ['confirmed' => true])->assertNotFound();
});

test('saved and sample deletions stay isolated when both sources have the same ID', function (): void {
    createDeleteOrderFixture(orderId: 1024, customerName: 'Actual customer');

    $response = $this->deleteJson(route('orders.samples.destroy', ['order' => 1024]), ['confirmed' => true]);

    $response->assertOk();
    $this->assertDatabaseHas('orders', ['id' => 1024]);
    $this->assertDatabaseCount('order_items', 2);
    expect($response->json('html'))->toContain('Actual customer', 'ORD-2026-1024');
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('orders.samples.show', ['order' => 1024]))->assertNotFound();
    $this->deleteJson(route('orders.destroy', ['order' => 1024]), ['confirmed' => true])->assertOk();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('customers', 1);
});

test('missing sample deletion returns 404 with clear feedback', function (): void {
    $this->deleteJson(route('orders.samples.destroy', ['order' => 999]), ['confirmed' => true])
        ->assertNotFound()->assertJsonPath('message', 'The order does not exist or has already been deleted.');
});

test('deleting the final sample shows an empty sample list', function (): void {
    $this->withSession(['orders.deleted_samples' => range(1001, 1023)])
        ->withCookie(config('session.cookie'), session()->getId());

    $response = $this->deleteJson(route('orders.samples.destroy', ['order' => 1024]), ['confirmed' => true]);

    $response->assertOk();
    expect($response->json('html'))->toContain('No orders yet', 'Showing 0 to 0 of 0 orders')->not->toContain('data-delete-order');
});

test('database failure returns 503 without exposing internal details or removing records', function (): void {
    $orderId = createDeleteOrderFixture();
    Schema::drop('payments');
    Exceptions::fake();

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertServiceUnavailable()->assertExactJson(['message' => 'The order could not be deleted. Please try again.']);
    Exceptions::assertReported(QueryException::class);
    $this->assertDatabaseHas('orders', ['id' => $orderId]);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('customers', 1);
});

test('a deletion failure rolls back the parent order and cascaded items together and returns 503', function (): void {
    $orderId = createDeleteOrderFixture();
    Exceptions::fake();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'delete') && str_contains($query->sql, 'orders')) {
            throw new QueryException($query->connectionName, $query->sql, $query->bindings, new RuntimeException('Simulated database failure.'));
        }
    });

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId]), ['confirmed' => true]);

    $response->assertServiceUnavailable()->assertJsonPath('message', 'The order could not be deleted. Please try again.');
    Exceptions::assertReported(QueryException::class);
    $this->assertDatabaseHas('orders', ['id' => $orderId]);
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseCount('customers', 1);
});

test('a list rendering failure after deletion still returns success with a redirect fallback', function (): void {
    $orderId = createDeleteOrderFixture();
    Exceptions::fake();
    View::composer('orders.index', function (): void {
        throw new RuntimeException('Simulated rendering failure.');
    });

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId, 'search' => 'Acme', 'page' => 2]), ['confirmed' => true]);

    $response->assertOk()->assertJsonPath('message', 'Order ORD-2026-0001 deleted successfully.')
        ->assertJsonPath('html', null)
        ->assertJsonPath('redirect_url', route('orders.index', ['search' => 'Acme', 'page' => 2]))
        ->assertSessionHas('success', 'Order ORD-2026-0001 deleted successfully.');
    Exceptions::assertReported(RuntimeException::class);
    $this->assertDatabaseMissing('orders', ['id' => $orderId]);
    $this->assertDatabaseMissing('order_items', ['order_id' => $orderId]);
    $this->assertDatabaseCount('customers', 1);
});

test('refreshed list fragments escape customer and search content', function (): void {
    $unsafeText = '<script>alert("unsafe")</script>';
    $orderId = createDeleteOrderFixture(customerName: $unsafeText);
    createDeleteOrderFixture(1);

    $response = $this->deleteJson(route('orders.destroy', ['order' => $orderId, 'search' => $unsafeText]), ['confirmed' => true]);

    $response->assertOk();
    expect($response->json('html'))->toContain(e($unsafeText))->not->toContain($unsafeText);
    $this->assertDatabaseHas('orders', ['id' => 2]);
});
