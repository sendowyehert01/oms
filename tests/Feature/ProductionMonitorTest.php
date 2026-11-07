<?php

use App\AuditAction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use App\OrderItemWorkflow;
use App\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->freezeTime();
});

/** @return list<array{name: string, required_quantity: string, available_quantity: string, unit: string}> */
function productionMonitorMaterials(string $available = '12.500'): array
{
    return [
        ['name' => 'LDPE resin', 'required_quantity' => '12.500', 'available_quantity' => $available, 'unit' => 'kgs'],
        ['name' => 'Printing ink', 'required_quantity' => '0.125', 'available_quantity' => '0.125', 'unit' => 'liters'],
    ];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function productionMonitorPayload(Order $order, string $action, array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(),
        'workflow_version' => $order->fresh()->workflow_version,
        'action' => $action,
    ], $overrides);
}

test('admin can create a Production Monitor account that can sign in', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('staff.store'), [
        'name' => 'Production Officer', 'email' => 'production@example.test',
        'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
        'account_role' => 'production_monitor', 'role' => 'admin',
    ])->assertRedirect(route('staff.index'));

    $monitor = User::query()->where('email', 'production@example.test')->firstOrFail();
    expect($monitor->role)->toBe(UserRole::ProductionMonitor);
    expect(Hash::check('test-password-123', $monitor->password))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['subject_id' => $monitor->id, 'action' => 'staff_created']);
    $this->get(route('staff.index'))->assertOk()->assertSee('Production Monitor');
    $this->post(route('logout'));
    $this->post(route('login.store'), ['email' => 'production@example.test', 'password' => 'test-password-123'])->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('production.index'));
    $this->assertAuthenticatedAs($monitor);
});

test('admin cannot assign an unsupported or administrative role from team management', function (string $role): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->postJson(route('staff.store'), [
        'name' => 'Account', 'email' => 'account@example.test', 'password' => 'test-password-123',
        'password_confirmation' => 'test-password-123', 'account_role' => $role,
    ])->assertUnprocessable()->assertJsonValidationErrors('account_role');

    $this->assertDatabaseCount('users', 1);
})->with(['admin', 'warehouse']);

test('production role permissions are limited to viewing and updating production', function (UserRole $role, bool $canProduce, bool $canManage, bool $canFinance): void {
    $user = User::factory()->create(['role' => $role]);
    $order = Order::factory()->for($user, 'creator')->hasItems(1)->create();

    expect(Gate::forUser($user)->allows('view', $order))->toBeTrue();
    expect(Gate::forUser($user)->allows('updateProduction', $order))->toBe($canProduce);
    expect(Gate::forUser($user)->allows('updateStatus', $order))->toBe($canManage);
    expect(Gate::forUser($user)->allows('delete', $order))->toBe($canManage);
    expect(Gate::forUser($user)->allows('viewFinancials', $order))->toBe($canFinance);
    expect(Gate::forUser($user)->allows('recordPayment', $order))->toBe($canFinance);
    expect(Gate::forUser($user)->allows('create', Order::class))->toBe($canFinance);
    expect(Gate::forUser($user)->allows('scheduleDelivery', $order))->toBe($canFinance);
    expect(Gate::forUser($user)->allows('manage-staff'))->toBe($canManage);
    expect(Gate::forUser($user)->allows('export-data'))->toBe($canManage);
    expect(Gate::forUser($user)->allows('view-audit-logs'))->toBe($canManage);
})->with([
    'admin' => [UserRole::Admin, true, true, true],
    'staff' => [UserRole::Staff, false, false, true],
    'production monitor' => [UserRole::ProductionMonitor, true, false, false],
]);

test('monitor sees production orders across staff without financial details', function (): void {
    $monitor = User::factory()->productionMonitor()->create();
    $order = Order::factory()->for(User::factory(), 'creator')->hasItems(1, ['unit_price' => '9876.54', 'material_color' => 'LDPE transparent', 'size' => '8 x 12 inches'])->create();
    OrderStatusHistory::factory()->for($order)->create(['event_type' => 'payment_due_date_changed', 'notes' => 'Private payment deadline']);
    $this->actingAs($monitor);

    $this->get(route('orders.index'))->assertRedirect(route('production.index'));
    $this->get(route('production.index'))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->total() === 1)->assertSee('Production Monitor')->assertDontSee('Balances')->assertDontSee('Staff accounts');
    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertViewIs('production.show')->assertSee('LDPE transparent')->assertSee('8 x 12 inches')
        ->assertDontSee('Unit price')->assertDontSee('9876')->assertDontSee('Terms of Payment')->assertDontSee('Record payment')->assertDontSee('Outstanding balance')->assertDontSee('Private payment deadline');
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $monitor->id, 'order_id' => $order->id, 'action' => 'order_viewed']);
});

test('monitor receives 403 for financial and administrative routes even on their own order', function (string $method, string $routeName): void {
    $monitor = User::factory()->productionMonitor()->create();
    $order = Order::factory()->for($monitor, 'creator')->hasItems(1)->create();
    $payment = Payment::factory()->for($order)->create(['receipt_path' => 'receipt.pdf']);
    $this->actingAs($monitor);

    $this->{$method}(route($routeName, ['order' => $order, 'payment' => $payment, 'type' => 'orders']), [])->assertForbidden();

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Pending', 'workflow_version' => 0]);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('users', 1);
})->with([
    'balances' => ['get', 'balances.index'],
    'create order page' => ['get', 'orders.create'],
    'create order' => ['post', 'orders.store'],
    'payment page' => ['get', 'orders.payments.create'],
    'payment creation' => ['post', 'orders.payments.store'],
    'receipt' => ['get', 'orders.payments.receipt'],
    'payment due date' => ['post', 'orders.payment-due-date.update'],
    'delivery schedule' => ['post', 'orders.schedule.update'],
    'order status' => ['post', 'orders.status.update'],
    'order deletion' => ['delete', 'orders.destroy'],
    'accounts' => ['get', 'staff.index'],
    'account creation' => ['post', 'staff.store'],
    'exports' => ['get', 'exports.index'],
    'audit logs' => ['get', 'audit-logs.index'],
]);

test('monitor fulfills mixed stock and production items only after materials and quality checks', function (): void {
    $monitor = User::factory()->productionMonitor()->create(['name' => 'Production Officer']);
    $order = Order::factory()->hasItems(2, ['quantity' => '1000 pcs'])->create();
    [$stockItem, $productionItem] = $order->items->all();
    $stockUrl = route('orders.items.production.update', ['order' => $order, 'item' => $stockItem]);
    $productionUrl = route('orders.items.production.update', ['order' => $order, 'item' => $productionItem]);
    $this->actingAs($monitor);

    $this->postJson($stockUrl, productionMonitorPayload($order, 'check_availability', ['stock_availability' => 'Available', 'finished_stock_quantity' => '1000']))->assertRedirect();
    $this->postJson($stockUrl, productionMonitorPayload($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();
    $this->postJson($productionUrl, productionMonitorPayload($order, 'check_availability', ['stock_availability' => 'Partially available', 'finished_stock_quantity' => '300']))->assertRedirect();
    $this->get(route('orders.show', $order))->assertOk()->assertSee('Remaining quantity to produce:')->assertSee('700 pcs');
    $this->postJson($productionUrl, productionMonitorPayload($order, 'start_production'))->assertUnprocessable()->assertJsonValidationErrors(['action' => 'Check raw materials and confirm every required quantity is available before starting production.']);
    $this->postJson($productionUrl, productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials('10'), 'notes' => 'Resin shortage']))->assertRedirect();
    $this->postJson($productionUrl, productionMonitorPayload($order, 'start_production'))->assertUnprocessable();
    $this->postJson($productionUrl, productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials(), 'notes' => 'Materials for the remaining 700 pieces']))->assertRedirect();
    $this->postJson($productionUrl, productionMonitorPayload($order, 'start_production'))->assertRedirect();
    $this->postJson($productionUrl, productionMonitorPayload($order, 'mark_ready'))->assertUnprocessable()->assertJsonValidationErrors('quality_checked');
    $this->postJson($productionUrl, productionMonitorPayload($order, 'mark_ready', ['quality_checked' => true]))->assertRedirect();

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Ready for delivery']);
    $this->assertDatabaseHas('order_items', ['id' => $productionItem->id, 'readiness_source' => 'production', 'materials_checked_by' => $monitor->id, 'ready_confirmed_by' => $monitor->id]);
    expect($productionItem->fresh()->material_checklist)->toBe(productionMonitorMaterials());
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $monitor->id, 'actor_role' => 'production_monitor', 'action' => AuditAction::MaterialsChecked->value]);
    $this->assertDatabaseHas('order_status_histories', ['order_item_id' => $productionItem->id, 'event_type' => 'materials_checked', 'to_status' => 'Partially available']);
    $this->assertDatabaseCount('payments', 0);
});

test('finished stock quantities cannot contradict the selected availability', function (string $availability, string $quantity): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1)->create();
    $item = $order->items->first();

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $item]), productionMonitorPayload($order, 'check_availability', ['stock_availability' => $availability, 'finished_stock_quantity' => $quantity]))
        ->assertUnprocessable()->assertJsonValidationErrors('finished_stock_quantity');

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'stock_availability' => 'Not checked', 'finished_stock_quantity' => null]);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with([
    'available but short' => ['Available', '99'],
    'partial but none' => ['Partially available', '0'],
    'unavailable but counted' => ['Unavailable', '1'],
]);

test('material check validation rejects invalid rows without saving progress', function (array $overrides, string $error): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    $materials = [array_replace(productionMonitorMaterials()[0], $overrides)];

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $item]), productionMonitorPayload($order, 'check_materials', ['materials' => $materials]))->assertUnprocessable()->assertJsonValidationErrors($error);

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'material_availability' => 'Not checked', 'materials_checked_at' => null]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 0]);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with([
    'blank name' => [['name' => ' '], 'materials.0.name'],
    'zero required quantity' => [['required_quantity' => '0'], 'materials.0.required_quantity'],
    'negative availability' => [['available_quantity' => '-1'], 'materials.0.available_quantity'],
    'excess precision' => [['required_quantity' => '0.0001'], 'materials.0.required_quantity'],
    'scientific notation' => [['available_quantity' => '1e2'], 'materials.0.available_quantity'],
    'blank unit' => [['unit' => ' '], 'materials.0.unit'],
    'unexpected field' => [['materials_checked_by' => 123], 'materials.0'],
    'name too long' => [['name' => str_repeat('a', 256)], 'materials.0.name'],
    'unit too long' => [['unit' => str_repeat('a', 21)], 'materials.0.unit'],
    'required quantity too large' => [['required_quantity' => '100000001'], 'materials.0.required_quantity'],
    'available quantity too large' => [['available_quantity' => '100000001'], 'materials.0.available_quantity'],
]);

test('material check submissions are scoped idempotent and protected against stale forms', function (): void {
    $monitor = User::factory()->productionMonitor()->create();
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    $otherItem = OrderItem::factory()->create();
    $this->actingAs($monitor);
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);
    $payload = productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()]);

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $otherItem]), $payload)->assertNotFound();
    $this->postJson($url, $payload)->assertRedirect();
    $this->postJson($url, $payload)->assertRedirect();
    $this->postJson($url, array_replace($payload, ['submission_key' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson($url, array_replace($payload, ['notes' => 'Changed retry']))->assertUnprocessable()->assertJsonValidationErrors('submission_key');

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 1]);
    $this->assertDatabaseCount('order_status_histories', 1);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('a changed finished-stock check resets materials and retains their previous evidence', function (): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Partially available', 'finished_stock_quantity' => '30'])->create();
    $item = $order->items->first();
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);

    $this->postJson($url, productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()]))->assertRedirect();
    $this->postJson($url, productionMonitorPayload($order, 'check_availability', ['stock_availability' => 'Partially available', 'finished_stock_quantity' => '20']))->assertRedirect();

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'material_checklist' => null, 'material_availability' => 'Not checked', 'materials_checked_at' => null]);
    $this->assertDatabaseHas('order_status_histories', ['order_item_id' => $item->id, 'event_type' => 'materials_reset']);
    expect($order->statusHistory()->where('event_type', 'materials_checked')->firstOrFail()->notes)->toContain('LDPE resin: required 12.500 kgs, available 12.500 kgs.');
});

test('materials cannot be changed after production starts or on closed orders', function (string $status, string $productionStatus): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable', 'production_status' => $productionStatus])->create(['status' => $status, 'item_tracking_started_at' => now()]);
    $item = $order->items->first();

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $item]), productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()]))->assertUnprocessable()->assertJsonValidationErrors('action');

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status, 'workflow_version' => 0]);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with([
    'production started' => ['In production', 'In production'],
    'delivered' => ['Delivered', 'Ready for delivery'],
    'completed' => ['Completed', 'Ready for delivery'],
]);

test('material queues show unchecked and short orders without changing staff visibility', function (): void {
    $monitor = User::factory()->productionMonitor()->create();
    $staff = User::factory()->create();
    $unchecked = Order::factory()->for($staff, 'creator')->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $short = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable', 'material_availability' => 'Partially available'])->create();
    $this->actingAs($monitor);

    $this->get(route('production.index', ['queue' => 'needs_check']))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$unchecked->id])->assertSee('Raw-material check needed');
    $this->get(route('production.index', ['queue' => 'waiting_materials']))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$short->id])->assertSee('Waiting for materials');
    $this->actingAs($staff)->get(route('production.index', ['queue' => 'waiting_materials']))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->isEmpty());
});

test('raw-material names and notes are escaped and invalid material forms preserve input', function (): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);
    $unsafe = '<script>alert(1)</script>';
    $materials = productionMonitorMaterials();
    $materials[0]['name'] = $unsafe;

    $this->post($url, productionMonitorPayload($order, 'check_materials', ['materials' => $materials, 'notes' => $unsafe]))->assertRedirect();
    $this->get(route('orders.show', $order))->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false);
    $materials[0]['available_quantity'] = '-1';
    $this->from(route('orders.show', $order))->post($url, productionMonitorPayload($order, 'check_materials', ['materials' => $materials, 'order_item_id' => $item->id]))->assertRedirect(route('orders.show', $order))->assertSessionHasErrors('materials.0.available_quantity');
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('orders.show', $order))->assertOk()->assertSee('value="-1"', false)->assertDontSee($unsafe, false);
});

test('production workflow authorizes the role even when called without an HTTP request', function (): void {
    $staff = User::factory()->create();
    $order = Order::factory()->for($staff, 'creator')->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();

    expect(fn () => app(OrderItemWorkflow::class)->update($order, $item, $staff, productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()])))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('order_status_histories', 0);
});

test('reopening clears material checks without losing their history', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);

    $this->post($url, productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()]))->assertRedirect();
    $this->post($url, productionMonitorPayload($order, 'start_production'))->assertRedirect();
    $this->post(route('orders.status.update', $order), productionMonitorPayload($order, 'reopen', ['notes' => 'Production quantities changed']))->assertRedirect();

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'production_status' => 'Pending', 'material_checklist' => null, 'material_availability' => 'Not checked', 'materials_checked_by' => null]);
    $this->assertDatabaseHas('order_status_histories', ['order_item_id' => $item->id, 'event_type' => 'materials_checked']);
    $this->assertDatabaseHas('order_status_histories', ['order_item_id' => $item->id, 'event_type' => 'materials_reset']);
});

test('empty or excessive material lists are rejected', function (int $count): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();

    $this->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $item]), productionMonitorPayload($order, 'check_materials', ['materials' => array_fill(0, $count, productionMonitorMaterials()[0])]))->assertUnprocessable()->assertJsonValidationErrors('materials');

    $this->assertDatabaseCount('order_status_histories', 0);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 0]);
})->with(['no materials' => [0], 'over twenty materials' => [21]]);

test('material availability and checker identity cannot be forged', function (): void {
    $monitor = User::factory()->productionMonitor()->create();
    $this->actingAs($monitor);
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    $materials = [['name' => 'LDPE resin', 'required_quantity' => '10', 'available_quantity' => '0', 'unit' => 'kgs']];
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);

    $this->postJson($url, productionMonitorPayload($order, 'check_materials', ['materials' => $materials, 'material_availability' => 'Available', 'materials_checked_by' => 999, 'materials_checked_by_name' => 'Fake administrator']))->assertRedirect();
    $this->postJson($url, productionMonitorPayload($order, 'start_production'))->assertUnprocessable();

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'material_availability' => 'Unavailable', 'materials_checked_by' => $monitor->id, 'materials_checked_by_name' => $monitor->name]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Pending']);
});

test('a failed material save rolls back its audit history and workflow version', function (): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $order = Order::factory()->hasItems(1, ['stock_availability' => 'Unavailable'])->create();
    $item = $order->items->first();
    Exceptions::fake();
    OrderItem::updating(function (): never {
        throw new RuntimeException('Material save failed');
    });

    $this->post(route('orders.items.production.update', ['order' => $order, 'item' => $item]), productionMonitorPayload($order, 'check_materials', ['materials' => productionMonitorMaterials()]))->assertRedirect(route('orders.show', $order))->assertSessionHas('error');

    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'material_checklist' => null, 'materials_checked_at' => null]);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 0, 'item_tracking_started_at' => null]);
    $this->assertDatabaseCount('order_status_histories', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Material save failed');
});

test('the material migration preserves historical production without inventing material checks', function (): void {
    $order = Order::factory()->hasItems(1, ['production_status' => 'In production'])->create(['status' => 'In production']);
    $item = $order->items->first();
    $migration = require database_path('migrations/2026_11_07_064816_add_material_checks_to_order_items_table.php');

    $migration->down();
    $migration->up();

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'In production']);
    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'production_status' => 'In production', 'material_availability' => 'Not checked', 'materials_checked_at' => null]);
    $this->assertDatabaseCount('order_status_histories', 0);
});

test('production and ready queues exclude closed and unverified historical orders', function (): void {
    $this->actingAs(User::factory()->productionMonitor()->create());
    $production = Order::factory()->hasItems(1, ['production_status' => 'In production'])->create(['status' => 'In production', 'item_tracking_started_at' => now()]);
    $ready = Order::factory()->has(OrderItem::factory()->readyForDelivery(), 'items')->create(['status' => 'Ready for delivery']);
    Order::factory()->hasItems(1)->create(['status' => 'Ready for delivery']);
    Order::factory()->hasItems(1, ['production_status' => 'In production'])->create(['status' => 'Completed']);

    $this->get(route('production.index', ['queue' => 'in_production']))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$production->id]);
    $this->get(route('production.index', ['queue' => 'ready']))->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->pluck('id')->all() === [$ready->id]);
});
