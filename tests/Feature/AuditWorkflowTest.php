<?php

use App\AuditRecorder;
use App\ItemAvailability;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create(['name' => 'Jem Morales']);
    $this->actingAs($this->admin);
    $this->travelTo(Carbon::parse('2026-11-06 02:00:00', 'UTC'));
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function auditOrderData(array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'customer_name' => 'Sendo Galang',
        'contact_person' => 'Sendo Galang', 'address' => '123 Market Street', 'contact_number' => '09123456789',
        'order_date' => '2026-11-06', 'terms_of_payment' => 'Net 30', 'status' => 'Pending',
        'items' => [['product_name' => 'Plastic bags', 'quantity' => '100', 'unit' => 'pcs', 'unit_price' => '1.25']],
    ], $overrides);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function auditChangeData(Order $order, string $action, array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'workflow_version' => $order->fresh()->workflow_version,
        'action' => $action, 'notes' => null,
    ], $overrides);
}

test('the complete order cycle records one uniform entry per human action', function (): void {
    $this->post(route('orders.store'), auditOrderData())->assertRedirect();
    $order = Order::query()->sole();
    $item = $order->items()->sole();
    $itemUrl = route('orders.items.production.update', ['order' => $order, 'item' => $item]);

    $this->post(route('orders.schedule.update', $order), auditChangeData($order, 'schedule_delivery', ['target_delivery_date' => '2026-11-12']))->assertRedirect();
    $this->post(route('orders.payment-due-date.update', $order), auditChangeData($order, 'set_payment_due_date', ['payment_due_date' => '2026-11-13']))->assertRedirect();
    $stockCheck = auditChangeData($order, 'check_availability', ['stock_availability' => 'Unavailable']);
    $this->post($itemUrl, $stockCheck)->assertRedirect();
    $this->post($itemUrl, $stockCheck)->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'start_production'))->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $this->post(route('orders.status.update', $order), auditChangeData($order, 'deliver', ['delivery_date' => '2026-11-06', 'delivery_recipient' => 'Sendo Galang']))->assertRedirect();
    $this->post(route('orders.payments.store', $order), [
        'submission_key' => (string) Str::uuid(), 'amount' => '125.00', 'payment_date' => '2026-11-06', 'payment_type' => 'full_payment',
    ])->assertRedirect();
    $completion = auditChangeData($order, 'complete');
    $this->post(route('orders.status.update', $order), $completion)->assertRedirect();
    $this->post(route('orders.status.update', $order), $completion)->assertRedirect();
    $this->post(route('orders.status.update', $order), auditChangeData($order, 'reopen', ['notes' => 'Correct the item allocation']))->assertRedirect();

    expect(AuditLog::query()->orderBy('id')->pluck('statement')->all())->toBe([
        'Jem Morales creates a client "Sendo Galang".',
        'Jem Morales creates an order "ORD-2026-0001".',
        'Jem Morales updates the delivery target for an order "ORD-2026-0001" from "Not scheduled" to "2026-11-12".',
        'Jem Morales updates the payment due date for an order "ORD-2026-0001" from "No due date" to "2026-11-13".',
        'Jem Morales checks stock availability for item "Plastic bags" in order "ORD-2026-0001" as "Unavailable".',
        'Jem Morales starts production for item "Plastic bags" in order "ORD-2026-0001".',
        'Jem Morales confirms readiness for item "Plastic bags" in order "ORD-2026-0001" after quality checks.',
        'Jem Morales confirms delivery of an order "ORD-2026-0001" on 2026-11-06 received by "Sendo Galang".',
        'Jem Morales records a payment of PHP 125.00 (Fully Paid) for an order "ORD-2026-0001".',
        'Jem Morales completes an order "ORD-2026-0001".',
        'Jem Morales reopens an order "ORD-2026-0001".',
    ]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Pending']);
    $this->assertDatabaseCount('payments', 1);
});

test('replaying order creation does not duplicate the client or order activity', function (): void {
    $data = auditOrderData();

    $this->post(route('orders.store'), $data)->assertRedirect();
    $this->post(route('orders.store'), $data)->assertRedirect();

    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('audit_logs', 2);
});

test('orders for an existing client do not claim to create the client again', function (): void {
    $client = Customer::factory()->create();

    $this->post(route('orders.store'), auditOrderData(['customer_id' => $client->id]))->assertRedirect();

    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order_created']);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'client_created']);
});

test('invalid order input creates neither business records nor success activity', function (): void {
    $this->postJson(route('orders.store'), auditOrderData(['items' => [['product_name' => 'Plastic bags', 'quantity' => '0', 'unit_price' => '1.25']]]))
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');

    $this->assertDatabaseEmpty('customers');
    $this->assertDatabaseEmpty('orders');
    $this->assertDatabaseEmpty('audit_logs');
});

test('order creation rolls back an earlier client audit if its items cannot be saved', function (): void {
    $this->withoutExceptionHandling();
    OrderItem::creating(function (): never {
        throw new RuntimeException('Simulated item failure');
    });

    expect(fn () => $this->post(route('orders.store'), auditOrderData()))->toThrow(RuntimeException::class, 'Simulated item failure');

    $this->assertDatabaseEmpty('customers');
    $this->assertDatabaseEmpty('orders');
    $this->assertDatabaseEmpty('order_status_histories');
    $this->assertDatabaseEmpty('audit_logs');
});

test('stock allocation and later readiness reconfirmation have distinct readable entries', function (): void {
    $order = Order::factory()->hasItems(1)->create(['order_date' => '2026-11-06']);
    $itemUrl = route('orders.items.production.update', ['order' => $order, 'item' => $order->items()->sole()]);

    $this->post($itemUrl, auditChangeData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'check_availability', ['stock_availability' => 'Partially available']))->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    $this->post($itemUrl, auditChangeData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();

    $this->assertDatabaseCount('audit_logs', 5);
    $this->assertDatabaseHas('audit_logs', ['action' => 'readiness_reconfirmed', 'statement' => 'Jem Morales reconfirms readiness for item "Plastic bags" in order "ORD-2026-0001" after quality checks.']);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'production_started']);
});

test('changed reused and stale workflow forms cannot add successful audit entries', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    [$first, $second] = $order->items()->get()->all();
    $payload = auditChangeData($order, 'check_availability', ['stock_availability' => 'Available']);
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $first]);

    $this->postJson($url, $payload)->assertRedirect();
    $this->postJson($url, $payload)->assertRedirect();
    $this->postJson($url, [...$payload, 'stock_availability' => 'Unavailable'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $second]), $payload)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson($url, auditChangeData($order, 'check_availability', ['stock_availability' => 'Unavailable', 'workflow_version' => 0]))
        ->assertUnprocessable()->assertJsonValidationErrors('workflow_version');

    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('workflow persistence failures roll back item state order history and audit entries', function (string $model): void {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items()->sole();
    Exceptions::fake();
    $model::updating(function (): never {
        throw new RuntimeException('Simulated persistence failure');
    });

    $this->post(route('orders.items.production.update', ['order' => $order, 'item' => $item]), auditChangeData($order, 'check_availability', ['stock_availability' => 'Available']))
        ->assertRedirect()->assertSessionHas('error');

    expect($item->fresh()->stock_availability)->toBe(ItemAvailability::NotChecked);
    expect($order->fresh()->workflow_version)->toBe(0);
    $this->assertDatabaseEmpty('order_status_histories');
    $this->assertDatabaseEmpty('audit_logs');
})->with([Order::class, OrderItem::class]);

test('date changes include old and new values while unchanged saves and retries add no audit', function (string $routeName, string $field, string $label, string $emptyLabel): void {
    $order = Order::factory()->hasItems(1)->create(['order_date' => '2026-11-06', $field => '2026-11-10']);

    $this->post(route($routeName, $order), auditChangeData($order, 'date_update', [$field => '2026-11-10']))->assertRedirect();
    $this->assertDatabaseEmpty('audit_logs');
    $payload = auditChangeData($order, 'date_update', [$field => '2026-11-12']);
    $this->post(route($routeName, $order), $payload)->assertRedirect();
    $this->post(route($routeName, $order), $payload)->assertRedirect();
    $this->post(route($routeName, $order), auditChangeData($order, 'date_update', [$field => null]))->assertRedirect();
    $this->post(route($routeName, $order), auditChangeData($order, 'date_update', [$field => null]))->assertRedirect();
    $this->postJson(route($routeName, $order), auditChangeData($order, 'date_update', [$field => '2026-10-01']))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(AuditLog::query()->orderBy('id')->pluck('statement')->all())->toBe([
        'Jem Morales updates '.$label.' for an order "ORD-2026-0001" from "2026-11-10" to "2026-11-12".',
        'Jem Morales updates '.$label.' for an order "ORD-2026-0001" from "2026-11-12" to "'.$emptyLabel.'".',
    ]);
})->with([
    'delivery target' => ['orders.schedule.update', 'target_delivery_date', 'the delivery target', 'Not scheduled'],
    'payment due date' => ['orders.payment-due-date.update', 'payment_due_date', 'the payment due date', 'No due date'],
]);

test('forbidden item changes and premature completion cannot create success entries', function (): void {
    $staff = User::factory()->create();
    $order = Order::factory()->hasItems(1)->create(['created_by' => $staff->id]);
    $this->actingAs($staff);

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $order->items()->sole()]), auditChangeData($order, 'check_availability', ['stock_availability' => 'Available']))->assertForbidden();
    $this->actingAs($this->admin);
    $this->postJson(route('orders.status.update', $order), auditChangeData($order, 'complete'))->assertUnprocessable();

    $this->assertDatabaseEmpty('audit_logs');
    expect($order->fresh()->workflow_version)->toBe(0);
});

test('audit snapshots and deleted order references survive actor changes and deletion', function (): void {
    $actor = User::factory()->create(['name' => 'Alex Staff']);
    $order = Order::factory()->hasItems(1)->create(['created_by' => $actor->id, 'order_date' => '2026-11-06']);
    $entry = app(AuditRecorder::class)->orderViewed($actor, $order);
    $actor->update(['name' => 'Alex Renamed']);
    $actor->delete();

    $this->deleteJson(route('orders.destroy', $order), ['confirmed' => true])->assertOk();
    $this->deleteJson(route('orders.destroy', $order), ['confirmed' => true])->assertNotFound();

    $this->assertDatabaseCount('audit_logs', 2);
    $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'actor_id' => $actor->id, 'actor_name' => 'Alex Staff', 'actor_role' => 'staff', 'order_number' => 'ORD-2026-0001']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'order_deleted', 'statement' => 'Jem Morales deletes an order "ORD-2026-0001".']);
    $this->get(route('audit-logs.index', ['actor_id' => $actor->id]))->assertOk()->assertSee('Alex Staff')->assertSee('Deleted order')
        ->assertDontSee('href="'.route('orders.show', $order).'"', false)
        ->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 1);
});

test('protected unconfirmed and forbidden deletions leave no successful deletion activity', function (): void {
    $protected = Order::factory()->hasItems(1)->hasPayments(1)->create();
    $staff = User::factory()->create();
    $unpaid = Order::factory()->hasItems(1)->create(['created_by' => $staff->id]);

    $this->deleteJson(route('orders.destroy', $protected), ['confirmed' => true])->assertConflict();
    $this->deleteJson(route('orders.destroy', $unpaid), [])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
    $this->actingAs($staff);
    $this->deleteJson(route('orders.destroy', $unpaid), ['confirmed' => true])->assertForbidden();

    $this->assertDatabaseEmpty('audit_logs');
    $this->assertModelExists($protected);
    $this->assertModelExists($unpaid);
});

test('payments record amount and type without duplicating retries or rejected balances', function (): void {
    $order = Order::factory()->hasItems(1)->create(['order_date' => '2026-11-06']);
    $data = ['submission_key' => (string) Str::uuid(), 'amount' => '25.00', 'payment_date' => '2026-11-06', 'payment_type' => 'down_payment'];
    $url = route('orders.payments.store', $order);

    $this->postJson($url, $data)->assertRedirect();
    $this->postJson($url, $data)->assertRedirect();
    $this->postJson($url, [...$data, 'amount' => '30.00'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson($url, [...$data, 'submission_key' => (string) Str::uuid(), 'amount' => '150.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson($url, [...$data, 'submission_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_type' => 'full_payment'])->assertRedirect();
    $this->postJson($url, [...$data, 'submission_key' => (string) Str::uuid(), 'amount' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('payments', 2);
    expect(AuditLog::query()->orderBy('id')->pluck('statement')->all())->toBe([
        'Jem Morales records a payment of PHP 25.00 (Downpayment) for an order "ORD-2026-0001".',
        'Jem Morales records a payment of PHP 100.00 (Fully Paid) for an order "ORD-2026-0001".',
    ]);
});

test('failed payment auditing rolls back payment data and cleans up its uploaded receipt', function (): void {
    config(['payment-receipts.storage' => 'local', 'payment-receipts.local_disk' => 'local']);
    Storage::fake('local');
    Exceptions::fake();
    $order = Order::factory()->hasItems(1)->create();
    AuditLog::creating(function (): never {
        throw new RuntimeException('Simulated audit failure');
    });

    $this->post(route('orders.payments.store', $order), [
        'submission_key' => (string) Str::uuid(), 'amount' => '25.00', 'payment_date' => '2026-11-06', 'payment_type' => 'down_payment',
        'receipt' => UploadedFile::fake()->create('receipt.pdf', 1, 'application/pdf'),
    ])->assertRedirect(route('orders.payments.create', $order))->assertSessionHas('error');

    $this->assertDatabaseEmpty('payments');
    $this->assertDatabaseEmpty('audit_logs');
    expect(Storage::disk('local')->allFiles('payment-receipts'))->toBe([]);
});
