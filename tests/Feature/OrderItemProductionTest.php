<?php

use App\ItemAvailability;
use App\ItemProductionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create(['name' => 'Production Admin']);
    $this->actingAs($this->admin);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function itemProductionData(Order $order, string $action, array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'workflow_version' => $order->fresh()->workflow_version,
        'action' => $action, 'notes' => null,
    ], $overrides);
}

function itemProductionUrl(Order $order, OrderItem $item): string
{
    return route('orders.items.production.update', ['order' => $order, 'item' => $item]);
}

test('items default to unchecked without invented readiness or historical order status', function (): void {
    $order = Order::factory()->hasItems(2)->create(['status' => 'Ready for delivery']);
    foreach ($order->items as $item) {
        expect($item->stock_availability)->toBe(ItemAvailability::NotChecked);
        expect($item->production_status)->toBe(ItemProductionStatus::Pending);
        expect($item->ready_confirmed_at)->toBeNull();
    }
    expect($order->fresh()->status)->toBe('Ready for delivery');
    $this->assertDatabaseCount('order_status_histories', 0);
    $this->postJson(itemProductionUrl($order, $order->items->first()), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertUnprocessable()->assertJsonValidationErrors('action');
});

test('available finished stock goes directly to ready only after allocation and quality confirmation', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items->first();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available', 'notes' => 'Full quantity is in storage']))->assertRedirect();
    expect($order->fresh()->status)->toBe('Pending');
    expect($item->fresh()->production_status)->toBe(ItemProductionStatus::Pending);
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready'))->assertUnprocessable()->assertJsonValidationErrors('quality_checked');
    $payload = itemProductionData($order, 'mark_ready', ['quality_checked' => true]);
    $this->postJson(itemProductionUrl($order, $item), $payload)->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), $payload)->assertRedirect();
    expect($order->fresh()->status)->toBe('Ready for delivery');
    expect($item->fresh()->isReadyForDelivery())->toBeTrue();
    expect($item->fresh()->readiness_source)->toBe('stock');
    expect($item->fresh()->ready_confirmed_by_name)->toBe('Production Admin');
    expect($item->fresh()->availability_checked_by_name)->toBe('Production Admin');
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('order_status_histories', 3);
});

test('partial or unavailable finished stock can enter production without payment', function (string $availability): void {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items->first();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => $availability]))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertUnprocessable()->assertJsonValidationErrors('action');
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'start_production'))->assertRedirect();
    expect($order->fresh()->status)->toBe('In production');
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    expect($order->fresh()->status)->toBe('Ready for delivery');
    expect($item->fresh()->readiness_source)->toBe('production');
    $this->assertDatabaseCount('payments', 0);
})->with(['Partially available', 'Unavailable']);

test('unchecked stock and unsupported readiness cannot bypass production', function (string $action): void {
    $order = Order::factory()->hasItems(1)->create();
    $this->postJson(itemProductionUrl($order, $order->items->first()), itemProductionData($order, $action, ['quality_checked' => true]))->assertUnprocessable();
    expect($order->fresh()->status)->toBe('Pending');
    $this->assertDatabaseCount('order_status_histories', 0);
})->with(['start_production', 'mark_ready', 'deliver', 'complete']);

test('all items must be ready before the entire order becomes ready', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    [$first, $second] = $order->items->all();
    $this->postJson(itemProductionUrl($order, $first), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $first), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    expect($order->fresh()->status)->toBe('In production');
    expect($second->fresh()->production_status)->toBe(ItemProductionStatus::Pending);
    $this->postJson(itemProductionUrl($order, $second), itemProductionData($order, 'check_availability', ['stock_availability' => 'Unavailable']))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $second), itemProductionData($order, 'start_production'))->assertRedirect();
    expect($order->fresh()->status)->toBe('In production');
    $this->postJson(itemProductionUrl($order, $second), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    expect($order->fresh()->status)->toBe('Ready for delivery');
});

test('later availability checks preserve progress and require explicit readiness review after shortages', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items->first();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Unavailable']))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'start_production'))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    expect($item->fresh()->production_status)->toBe(ItemProductionStatus::InProduction);
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Partially available']))->assertRedirect();
    expect($order->fresh()->status)->toBe('Ready for delivery');
    expect($item->fresh()->production_status)->toBe(ItemProductionStatus::ReadyForDelivery);
    expect($item->fresh()->isReadyForDelivery())->toBeFalse();
    $this->get(route('orders.show', $order))->assertOk()->assertSee('Readiness needs review');
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertUnprocessable();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    expect($item->fresh()->isReadyForDelivery())->toBeFalse();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    expect($item->fresh()->isReadyForDelivery())->toBeTrue();
});

test('reopening resets item checks and progress while retaining money and audit events', function (): void {
    $order = Order::factory()->hasItems(1)->hasPayments(1)->create();
    $item = $order->items->first();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $historyCount = $order->statusHistory()->count();
    $this->postJson(route('orders.status.update', $order), itemProductionData($order, 'reopen', ['notes' => 'Stock allocation was incorrect']))->assertRedirect();
    expect($order->fresh()->status)->toBe('Pending');
    expect($order->fresh()->item_tracking_started_at)->toBeNull();
    expect($item->fresh()->stock_availability)->toBe(ItemAvailability::NotChecked);
    expect($item->fresh()->production_status)->toBe(ItemProductionStatus::Pending);
    expect($item->fresh()->ready_confirmed_at)->toBeNull();
    expect($order->statusHistory()->count())->toBeGreaterThan($historyCount);
    $this->assertDatabaseCount('payments', 1);
});

test('stock and production changes are admin only and scoped to the parent order', function (bool $visible): void {
    $staff = User::factory()->create();
    $order = Order::factory()->hasItems(1)->create(['created_by' => $visible ? $staff->id : $this->admin->id]);
    $this->actingAs($staff);
    $this->postJson(itemProductionUrl($order, $order->items->first()), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertStatus($visible ? 403 : 404);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with([true, false]);

test('cross order item IDs and closed orders cannot be mutated', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    $other = Order::factory()->hasItems(1)->create(['status' => 'Completed']);
    $this->postJson(itemProductionUrl($order, $other->items->first()), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertNotFound();
    $this->postJson(itemProductionUrl($other, $other->items->first()), itemProductionData($other, 'check_availability', ['stock_availability' => 'Available']))->assertUnprocessable();
    $this->assertDatabaseCount('order_status_histories', 0);
});

test('duplicate stock checks are idempotent and stale or changed submissions fail', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    [$first, $second] = $order->items->all();
    $payload = itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']);
    $this->postJson(itemProductionUrl($order, $first), $payload)->assertRedirect();
    $this->postJson(itemProductionUrl($order, $first), $payload)->assertRedirect();
    $this->postJson(itemProductionUrl($order, $first), [...$payload, 'stock_availability' => 'Unavailable'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson(itemProductionUrl($order, $second), $payload)->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson(itemProductionUrl($order, $second), itemProductionData($order, 'check_availability', ['stock_availability' => 'Unavailable', 'workflow_version' => 0]))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    expect($second->fresh()->stock_availability)->toBe(ItemAvailability::NotChecked);
    expect($order->fresh()->workflow_version)->toBe(1);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('failed persistence rolls back item data order version and history together', function (string $model): void {
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items->first();
    Exceptions::fake();
    $model::updating(function (): never {
        throw new RuntimeException('Simulated persistence failure');
    });
    $this->post(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirect()->assertSessionHas('error');
    expect($item->fresh()->stock_availability)->toBe(ItemAvailability::NotChecked);
    expect($order->fresh()->workflow_version)->toBe(0);
    expect($order->fresh()->item_tracking_started_at)->toBeNull();
    $this->assertDatabaseCount('order_status_histories', 0);
})->with([Order::class, OrderItem::class]);

test('production migration preserves legacy items and order status on reapplication', function (): void {
    $migration = require database_path('migrations/2026_11_04_143912_add_production_tracking_to_order_items_table.php');
    $migration->down();
    $order = Order::factory()->hasItems(1)->create(['status' => 'Completed']);
    $original = $order->items->first();
    $migration->up();
    expect($order->fresh()->status)->toBe('Completed');
    expect($original->fresh()->quantity)->toBe($original->quantity);
    expect($original->fresh()->unit_price)->toBe($original->unit_price);
    expect($original->fresh()->stock_availability)->toBe(ItemAvailability::NotChecked);
    expect($original->fresh()->production_status)->toBe(ItemProductionStatus::Pending);
});

test('production forms keep unique labels and IDs with mixed item states', function (): void {
    $order = Order::factory()->hasItems(3)->create();
    [$first, $second, $third] = $order->items->all();
    foreach ([[$first, 'Available'], [$second, 'Unavailable'], [$third, 'Available']] as [$item, $availability]) {
        $this->postJson(itemProductionUrl($order, $item), itemProductionData($order, 'check_availability', ['stock_availability' => $availability]))->assertRedirect();
    }
    $this->postJson(itemProductionUrl($order, $first), itemProductionData($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $this->postJson(itemProductionUrl($order, $second), itemProductionData($order, 'start_production'))->assertRedirect();
    $html = $this->get(route('orders.show', $order))->assertOk()->getContent();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($document);
    $ids = [];
    foreach ($xpath->query('//*[@id]') as $element) {
        $ids[] = $element->getAttribute('id');
    }
    expect(count($ids))->toBe(count(array_unique($ids)));
    foreach ($xpath->query('//select | //textarea | //input[not(@type="hidden")]') as $control) {
        $labels = $xpath->query('ancestor::label', $control)->length;
        if ($control->getAttribute('id') !== '') {
            $labels += $xpath->query('//label[@for="'.$control->getAttribute('id').'"]')->length;
        }
        expect($labels)->toBeGreaterThan(0);
    }
});
