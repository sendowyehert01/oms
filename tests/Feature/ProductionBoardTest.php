<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\OrderEntries;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    $this->travelTo(Carbon::parse('2026-11-05 01:00:00', 'Asia/Manila'));
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function schedulePayload(Order $order, array $overrides = []): array
{
    return array_replace(['submission_key' => (string) Str::uuid(), 'workflow_version' => $order->workflow_version ?? 0, 'target_delivery_date' => '2026-11-07'], $overrides);
}

test('production board requires authentication and scopes cards counts and filter choices', function (): void {
    $staff = User::factory()->create(['name' => 'Visible staff']);
    $other = User::factory()->create(['name' => 'Hidden creator']);
    $ownedClient = Customer::factory()->for($staff, 'creator')->create(['company_name' => 'Owned client']);
    $own = Order::factory()->for($ownedClient)->hasItems(1)->create(['created_by' => $staff->id]);
    Order::factory()->for($ownedClient)->hasItems(1)->create(['created_by' => $this->admin->id, 'status' => 'In production']);
    $hiddenClient = Customer::factory()->create(['company_name' => 'Hidden client']);
    $hidden = Order::factory()->for($hiddenClient)->hasItems(1)->create(['created_by' => $other->id]);
    $this->actingAs($staff);
    $response = $this->get(route('production.index'));
    $response->assertOk()->assertSee('Owned client')->assertDontSee('Hidden client')->assertDontSee('Hidden creator');
    expect($response['orders']->total())->toBe(2)->and((int) $response['counts']->sum())->toBe(2);
    $this->get(route('production.index', ['customer_id' => $hiddenClient->id]))->assertOk()->assertSee('No matching production orders')->assertDontSee('Hidden client');
    $this->get(route('production.index', ['created_by' => $other->id]))->assertOk()->assertDontSee('Hidden creator');
    $this->get(route('production.index', ['search' => app(OrderEntries::class)->number($own->id, $own->order_date->toDateString())]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->total() === 1);
    auth()->logout();
    $this->get(route('production.index'))->assertRedirect(route('login'));
});

test('filters combine status creator customer date and exact order number', function (): void {
    $staff = User::factory()->create();
    $order = Order::factory()->hasItems(1)->create(['created_by' => $staff->id, 'target_delivery_date' => '2026-11-07', 'status' => 'In production']);
    Order::factory()->hasItems(1)->create();
    $response = $this->get(route('production.index', [
        'created_by' => $staff->id, 'customer_id' => $order->customer_id, 'target_delivery_date' => '2026-11-07',
        'status' => 'In production', 'search' => app(OrderEntries::class)->number($order->id, $order->order_date->toDateString()), 'view' => 'list',
    ]));
    $response->assertOk()->assertSee('Production orders');
    expect($response['orders']->total())->toBe(1)->and((int) $response['counts']->sum())->toBe(1);
});

test('late delivery excludes delivered completed due today and unscheduled orders', function (): void {
    foreach (['Pending', 'Delivered', 'Completed'] as $status) {
        Order::factory()->hasItems(1)->create(['status' => $status, 'target_delivery_date' => '2026-11-04']);
    }
    Order::factory()->hasItems(1)->create(['target_delivery_date' => '2026-11-05']);
    Order::factory()->hasItems(1)->create();
    $response = $this->get(route('production.index', ['late' => 1]));
    $response->assertOk()->assertSee('Late for delivery');
    expect($response['orders']->total())->toBe(1);
});

test('board uses recorded stage history and exposes real readiness blockers', function (): void {
    $order = Order::factory()->hasItems(1)->create(['status' => 'In production', 'item_tracking_started_at' => now()]);
    $order->items()->update(['ready_check_required' => true]);
    OrderStatusHistory::factory()->for($order)->create(['event_type' => 'order_status_changed', 'to_status' => 'In production', 'created_at' => now()->subHours(3)]);
    OrderStatusHistory::factory()->for($order)->create(['event_type' => 'delivery_scheduled', 'to_status' => 'In production', 'created_at' => now()]);
    $response = $this->get(route('production.index'));
    $response->assertOk()->assertSee('3 hours in current stage')->assertSee('Readiness recheck required')->assertSee('Finished-stock check needed');
    expect($response['orders']->first()->stage_started_at->equalTo(now()->subHours(3)))->toBeTrue();
    OrderStatusHistory::query()->delete();
    $this->get(route('production.index'))->assertOk()->assertSee('Stage start not recorded');
});

test('board pagination uses aggregate queries without hydrating every item or history', function (): void {
    Order::factory()->hasItems(2)->count(45)->create();
    DB::enableQueryLog();
    $response = $this->get(route('production.index', ['view' => 'list']));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk()->assertSee('page=2', false);
    expect($response['orders']->count())->toBe(20)->and($response['orders']->total())->toBe(45)->and(count($queries))->toBeLessThan(16);
    foreach ($response['orders'] as $order) {
        expect($order->relationLoaded('items'))->toBeFalse()->and($order->relationLoaded('statusHistory'))->toBeFalse();
    }
    $this->get(route('production.index', ['page' => 3]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->count() === 5);
});

test('admin schedule edits are audited idempotent and protected against stale submissions', function (): void {
    $order = Order::factory()->hasItems(1)->create(['order_date' => '2026-11-01']);
    $data = schedulePayload($order);
    $this->postJson(route('orders.schedule.update', $order), $data)->assertRedirect();
    $this->postJson(route('orders.schedule.update', $order), $data)->assertRedirect();
    expect($order->fresh()->target_delivery_date->toDateString())->toBe('2026-11-07')->and($order->statusHistory()->count())->toBe(1);
    $this->postJson(route('orders.schedule.update', $order), schedulePayload($order))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('orders.schedule.update', $order), [...$data, 'target_delivery_date' => '2026-11-08'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson(route('orders.schedule.update', $order), schedulePayload($order->fresh(), ['target_delivery_date' => null]))->assertRedirect();
    expect($order->fresh()->target_delivery_date)->toBeNull()->and($order->fresh()->terms_of_payment)->toBe('Cash on delivery');
});

test('staff schedule only orders they created and cannot update production through scheduling', function (): void {
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create();
    $own = Order::factory()->for($client)->create(['created_by' => $staff->id, 'order_date' => '2026-11-01']);
    $visible = Order::factory()->for($client)->create(['created_by' => $this->admin->id]);
    $hidden = Order::factory()->create();
    $this->actingAs($staff);
    $this->postJson(route('orders.schedule.update', $own), [...schedulePayload($own), 'status' => 'Completed'])->assertRedirect();
    expect($own->fresh()->status)->toBe('Pending');
    $this->postJson(route('orders.schedule.update', $visible), schedulePayload($visible))->assertForbidden();
    $this->postJson(route('orders.schedule.update', $hidden), schedulePayload($hidden))->assertNotFound();
    $this->postJson(route('orders.status.update', $own), ['action' => 'reopen', 'notes' => 'Try bypass', ...schedulePayload($own->fresh())])->assertForbidden();
});

test('schedules reject invalid dates and changes to delivered or completed orders', function (): void {
    $order = Order::factory()->create(['order_date' => '2026-11-01']);
    $this->postJson(route('orders.schedule.update', $order), schedulePayload($order, ['target_delivery_date' => '2026-10-31']))->assertUnprocessable();
    $this->postJson(route('orders.schedule.update', $order), schedulePayload($order, ['target_delivery_date' => '2026-02-30']))->assertUnprocessable();
    foreach (['Delivered', 'Completed'] as $status) {
        $order->status = $status;
        $order->save();
        $this->postJson(route('orders.schedule.update', $order), schedulePayload($order))->assertUnprocessable();
    }
    expect($order->fresh()->target_delivery_date)->toBeNull();
});

test('creation stores a separate delivery target without altering payment terms or legacy date', function (): void {
    $data = [
        'submission_key' => (string) Str::uuid(), 'customer_name' => 'Schedule client', 'contact_person' => 'Alex',
        'contact_number' => '09123456789', 'address' => 'Test address', 'order_date' => '2026-11-01', 'terms_of_payment' => 'Net 30',
        'status' => 'Pending', 'target_delivery_date' => '2026-11-07', 'items' => [['product_name' => 'Bags', 'quantity' => '10', 'unit_price' => '1.25', 'unit' => 'pcs']],
    ];
    $this->postJson(route('orders.store'), $data)->assertRedirect();
    $order = Order::query()->first();
    expect($order->target_delivery_date->toDateString())->toBe('2026-11-07')->and($order->due_date)->toBeNull()->and($order->terms_of_payment)->toBe('Net 30');
});
