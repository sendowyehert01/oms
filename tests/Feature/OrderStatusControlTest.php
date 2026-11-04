<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\OrderEntries;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function statusChangeData(Order $order, string $action, array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'workflow_version' => $order->workflow_version ?? 0,
        'action' => $action, 'notes' => null,
    ], $overrides);
}

/** @return array<string, mixed> */
function workflowCreateOrderData(string $status): array
{
    return [
        'submission_key' => (string) Str::uuid(), 'customer_name' => 'Workflow Client',
        'contact_person' => 'Alex Cruz', 'contact_number' => '09123456789', 'address' => '123 Market Street',
        'order_date' => '2026-11-04', 'terms_of_payment' => 'Net 30', 'status' => $status,
        'items' => [['product_name' => 'Plastic bags', 'quantity' => '10', 'unit' => 'pcs', 'unit_price' => '1.50']],
    ];
}

test('new order creation rejects a forged advanced status', function (string $status): void {
    $this->postJson(route('orders.store'), workflowCreateOrderData($status))->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with(['In production', 'Ready for delivery', 'Delivered', 'Completed']);

test('order service forces Pending and creation retries do not invent duplicate history', function (): void {
    $data = workflowCreateOrderData('Completed');
    $id = app(OrderEntries::class)->create($data, $this->admin);
    expect(app(OrderEntries::class)->create($data, $this->admin))->toBe($id);
    $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'Pending']);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('whole order actions cannot bypass per item availability and readiness checks', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'start_production'))->assertUnprocessable()->assertJsonValidationErrors('action');
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'mark_ready', ['quality_checked' => true]))->assertUnprocessable()->assertJsonValidationErrors('action');
    expect($order->fresh()->status)->toBe('Pending');
    $this->assertDatabaseCount('order_status_histories', 0);
});

test('delivery completion and out of sequence actions cannot be forged', function (string $action): void {
    $order = Order::factory()->hasItems(1)->create();
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, $action, ['quality_checked' => true]))->assertUnprocessable();
    expect($order->fresh()->status)->toBe('Pending');
    $this->assertDatabaseCount('order_status_histories', 0);
})->with(['mark_ready', 'deliver', 'complete', 'Completed']);

test('status edits are admin only even on an order staff can view', function (bool $visible): void {
    $staff = User::factory()->create();
    $order = Order::factory()->hasItems(1)->create(['created_by' => $visible ? $staff->id : $this->admin->id]);
    $this->actingAs($staff);
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'start_production'))->assertStatus($visible ? 403 : 404);
    $this->assertDatabaseCount('order_status_histories', 0);
    expect($order->fresh()->status)->toBe('Pending');
})->with([true, false]);

test('reopening requires a reason and retains payments and historical status', function (): void {
    $order = Order::factory()->hasItems(1)->hasPayments(1)->create(['status' => 'Completed']);
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'reopen'))->assertUnprocessable()->assertJsonValidationErrors('notes');
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'reopen', ['notes' => 'Customer requested a correction']))->assertRedirect();
    expect($order->fresh()->status)->toBe('Pending');
    $this->assertDatabaseCount('payments', 1);
    $history = $order->statusHistory()->first();
    expect($history->from_status)->toBe('Completed');
    expect($history->notes)->toBe('Customer requested a correction');
});

test('exact retries are idempotent but stale forms and reused changed payloads are rejected', function (): void {
    $order = Order::factory()->hasItems(1)->create(['status' => 'In production']);
    $payload = statusChangeData($order, 'reopen', ['notes' => 'Correct the order']);
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.status.update', $order), statusChangeData($order, 'reopen', ['notes' => 'Stale']))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('orders.status.update', $order), [...$payload, 'notes' => 'Changed'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    expect($order->fresh()->workflow_version)->toBe(1);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('status and history roll back together if saving the order fails', function (): void {
    $order = Order::factory()->hasItems(1)->create(['status' => 'In production']);
    Exceptions::fake();
    Order::updating(function (Order $saving) use ($order): void {
        if ($saving->id === $order->id) {
            throw new RuntimeException('Simulated persistence failure');
        }
    });
    $this->post(route('orders.status.update', $order), statusChangeData($order, 'reopen', ['notes' => 'Correction']))->assertRedirect()->assertSessionHas('error');
    expect($order->fresh()->status)->toBe('In production');
    expect($order->fresh()->workflow_version)->toBe(0);
    $this->assertDatabaseCount('order_status_histories', 0);
    Exceptions::assertReported(RuntimeException::class);
});

test('history is escaped paginated and visible to authorized staff without edit controls', function (): void {
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create();
    $order = Order::factory()->for($client)->hasItems(1)->create(['status' => 'In production']);
    OrderStatusHistory::factory()->count(12)->for($order)->create(['notes' => '<script>unsafe</script>']);
    $this->actingAs($staff);
    $this->get(route('orders.show', $order))->assertOk()->assertSee('Status history')->assertSee('<script>unsafe</script>')->assertDontSee('<script>unsafe</script>', false)->assertDontSee('name="action"', false)->assertSee('history_page=2', false);
    $this->get(route('orders.show', $order).'?history_page=2')->assertOk();
});
