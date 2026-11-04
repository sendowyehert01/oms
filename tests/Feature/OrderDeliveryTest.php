<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\PaymentEntries;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->travelTo(Carbon::parse('2026-11-05 01:00:00', 'Asia/Manila'));
});

/** @param array<string, mixed> $overrides */
function deliveryOrder(array $overrides = []): Order
{
    return Order::factory()->has(OrderItem::factory()->readyForDelivery(), 'items')->create([
        'status' => 'Ready for delivery', 'order_date' => '2026-11-01', 'item_tracking_started_at' => now(), ...$overrides,
    ]);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function deliveryPayload(Order $order, string $action = 'deliver', array $overrides = []): array
{
    return array_replace([
        'action' => $action, 'submission_key' => (string) Str::uuid(), 'workflow_version' => $order->workflow_version,
        'delivery_date' => '2026-11-05', 'delivery_recipient' => 'Alex Cruz', 'notes' => 'All items received',
    ], $overrides);
}

function settleDeliveryOrder(Order $order): void
{
    app(PaymentEntries::class)->create($order->id, [
        'submission_key' => (string) Str::uuid(), 'amount' => '125.00', 'payment_date' => '2026-11-05', 'payment_type' => 'full_payment',
    ]);
}

test('full delivery records recipient date actor and history even while unpaid', function (): void {
    $order = deliveryOrder();
    $payload = deliveryPayload($order);
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $fresh = $order->fresh();
    expect($fresh->status)->toBe('Delivered')->and($fresh->delivery_date->toDateString())->toBe('2026-11-05')
        ->and($fresh->delivery_confirmed_by)->toBe($this->admin->id)->and($fresh->workflow_version)->toBe(1);
    expect($fresh->statusHistory()->count())->toBe(1);
    expect($fresh->statusHistory()->first()->notes)->toContain('Alex Cruz', '2026-11-05');
    $this->get(route('orders.show', $order))->assertOk()->assertSee('Full delivery confirmed')->assertSee('Alex Cruz')->assertSee('Outstanding balance');
});

test('completion works for either payment and delivery ordering and exact retries are safe', function (bool $payFirst): void {
    $order = deliveryOrder();
    if ($payFirst) {
        settleDeliveryOrder($order);
        $this->postJson(route('orders.status.update', $order), deliveryPayload($order, 'complete'))->assertUnprocessable();
    }
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order))->assertRedirect();
    if (! $payFirst) {
        $this->postJson(route('orders.status.update', $order), deliveryPayload($order->fresh(), 'complete'))->assertUnprocessable();
        settleDeliveryOrder($order);
    }
    $payload = deliveryPayload($order->fresh(), 'complete');
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    expect($order->fresh()->status)->toBe('Completed')->and($order->statusHistory()->count())->toBe(2);
})->with([true, false]);

test('a fully paid label cannot disguise an actual outstanding balance', function (): void {
    $order = deliveryOrder();
    $order->payments()->create(['payment_date' => '2026-11-05', 'amount' => '1.00', 'payment_type' => 'full_payment']);
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order))->assertRedirect();
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order->fresh(), 'complete'))->assertUnprocessable()->assertJsonValidationErrors('action');
    expect($order->fresh()->status)->toBe('Delivered');
});

test('delivery cannot bypass unfinished items shortages or an empty order', function (string $case): void {
    $order = deliveryOrder();
    match ($case) {
        'unfinished' => $order->items()->update(['production_status' => 'In production']),
        'shortage' => $order->items()->update(['ready_check_required' => true]),
        'empty' => $order->items()->delete(),
    };
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order))->assertUnprocessable();
    expect($order->fresh()->delivery_date)->toBeNull()->and($order->statusHistory()->count())->toBe(0);
})->with(['unfinished', 'shortage', 'empty']);

test('delivery validates fields and reporting timezone date boundaries', function (array $override): void {
    $order = deliveryOrder();
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order, overrides: $override))->assertUnprocessable();
    expect($order->fresh()->status)->toBe('Ready for delivery');
})->with([[['delivery_date' => '2026-11-06']], [['delivery_date' => '2026-10-31']], [['delivery_date' => '2026-02-30']], [['delivery_recipient' => '']], [['delivery_recipient' => str_repeat('a', 256)]]]);

test('staff may view delivery facts but cannot confirm or complete orders', function (bool $visible): void {
    $staff = User::factory()->create();
    $order = deliveryOrder(['created_by' => $visible ? $staff->id : $this->admin->id]);
    $this->actingAs($staff);
    foreach (['deliver', 'complete'] as $action) {
        $this->postJson(route('orders.status.update', $order), deliveryPayload($order, $action))->assertStatus($visible ? 403 : 404);
    }
    if ($visible) {
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('Confirm full delivery');
    }
})->with([true, false]);

test('legacy delivered and completed orders retain status without invented evidence', function (): void {
    $order = deliveryOrder(['status' => 'Delivered']);
    settleDeliveryOrder($order);
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order, 'complete'))->assertUnprocessable();
    $this->get(route('orders.show', $order))->assertOk()->assertSee('Historical delivery details are not recorded');
    $order->status = 'Completed';
    $order->save();
    $this->get(route('orders.show', $order))->assertOk();
    expect($order->fresh()->status)->toBe('Completed')->and($order->fresh()->delivery_date)->toBeNull();
});

test('stale and reused changed delivery requests cannot create conflicting facts', function (): void {
    $order = deliveryOrder();
    $payload = deliveryPayload($order);
    $this->postJson(route('orders.status.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order, 'complete'))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('orders.status.update', $order), [...$payload, 'delivery_recipient' => 'Different person'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    expect($order->fresh()->delivery_recipient)->toBe('Alex Cruz');
});

test('reopening clears active delivery evidence and retains its history and payments', function (): void {
    $order = deliveryOrder();
    settleDeliveryOrder($order);
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order))->assertRedirect();
    $this->postJson(route('orders.status.update', $order), deliveryPayload($order->fresh(), 'reopen', ['notes' => 'Correct delivery']))->assertRedirect();
    expect($order->fresh()->delivery_date)->toBeNull()->and($order->fresh()->status)->toBe('Pending')->and($order->payments()->count())->toBe(1);
    expect($order->statusHistory()->where('event_type', 'delivery_confirmed')->first()->notes)->toContain('Alex Cruz');
});

test('failed persistence rolls back status delivery facts and audit events together', function (): void {
    $order = deliveryOrder();
    Exceptions::fake();
    Order::updating(function (Order $saving): void {
        throw new RuntimeException('Delivery persistence failure');
    });
    $this->post(route('orders.status.update', $order), deliveryPayload($order))->assertSessionHas('error');
    expect($order->fresh()->status)->toBe('Ready for delivery')->and($order->fresh()->delivery_date)->toBeNull()->and($order->statusHistory()->count())->toBe(0);
});
