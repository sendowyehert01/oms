<?php

use App\ItemAvailability;
use App\ItemProductionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->actingAs(User::factory()->admin()->create());
    $this->travelTo(new DateTimeImmutable('2026-11-05 08:00:00 UTC'));
});

function orderChecklistDocument(string $html): DOMDocument
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    return $document;
}

function orderChecklistElement(DOMDocument $document, string $id): DOMElement
{
    $element = $document->getElementById($id);
    expect($element)->toBeInstanceOf(DOMElement::class);

    return $element;
}

/** @param array<string, mixed> $overrides */
function readyChecklistOrder(array $overrides = []): Order
{
    return Order::factory()->has(OrderItem::factory()->readyForDelivery(), 'items')->create([
        'status' => 'Ready for delivery',
        'item_tracking_started_at' => now(),
        ...$overrides,
    ]);
}

/** @param array<string, mixed> $overrides */
function deliveredChecklistOrder(array $overrides = []): Order
{
    return readyChecklistOrder([
        'status' => 'Delivered',
        'delivery_date' => '2026-11-05',
        'delivery_recipient' => 'Alex Cruz',
        'delivery_confirmed_at' => now(),
        'delivery_confirmed_by_name' => 'Delivery Admin',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderChecklistPayload(Order $order, string $action, array $overrides = []): array
{
    return [
        'submission_key' => (string) Str::uuid(),
        'workflow_version' => $order->fresh()->workflow_version,
        'action' => $action,
        ...$overrides,
    ];
}

test('a new order lists stock actions above the reference details with forms kept in closed dialogs', function (): void {
    $order = Order::factory()->hasItems(1)->create();

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeTextInOrder(['Order checklist', 'Check finished-stock availability.', '1. Prepare order', '2. Deliver order', '3. Complete order', 'Order information']);
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'stock-check-'.$order->items->first()->id)->tagName)->toBe('dialog');
    expect(orderChecklistElement($document, 'stock-check-'.$order->items->first()->id)->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeFalse();
});

test('the next action follows the remaining item stock and production state', function (string $availability, string $progress, string $nextAction): void {
    $order = Order::factory()->has(OrderItem::factory()->state([
        'stock_availability' => $availability,
        'production_status' => $progress,
    ]), 'items')->create([
        'status' => $progress === 'In production' ? 'In production' : 'Pending',
        'item_tracking_started_at' => now(),
    ]);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk();
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-next-action')->textContent)->toBe($nextAction);
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'stock-check-'.$order->items->first()->id)->hasAttribute('open'))->toBeFalse();
})->with([
    'available stock needs allocation' => ['Available', 'Pending', 'Confirm item readiness.'],
    'partial stock needs production' => ['Partially available', 'Pending', 'Start production for remaining items.'],
    'unavailable stock needs production' => ['Unavailable', 'Pending', 'Start production for remaining items.'],
    'unfinished production needs readiness' => ['Unavailable', 'In production', 'Confirm item readiness.'],
    'stock found during production still needs readiness' => ['Available', 'In production', 'Confirm item readiness.'],
]);

test('mixed item progress directs the administrator to the next unchecked item', function (): void {
    $order = Order::factory()->create(['status' => 'In production', 'item_tracking_started_at' => now()]);
    OrderItem::factory()->for($order)->readyForDelivery()->create();
    $uncheckedItem = OrderItem::factory()->for($order)->create();
    OrderItem::factory()->for($order)->create(['stock_availability' => 'Unavailable']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Check finished-stock availability.')->assertSeeText('1 of 3 items ready')
        ->assertSee('href="#stock-check-'.$uncheckedItem->id.'"', false);
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'stock-action-'.$uncheckedItem->id)->getAttribute('aria-controls'))->toBe('stock-check-'.$uncheckedItem->id);
    expect(orderChecklistElement($document, 'stock-check-'.$uncheckedItem->id)->hasAttribute('open'))->toBeFalse();
});

test('a later shortage keeps readiness review in the preparation step', function (string $availability, string $nextTarget): void {
    $order = readyChecklistOrder();
    $order->items()->update(['stock_availability' => $availability, 'ready_check_required' => true]);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Review item readiness.')->assertDontSee('Confirm delivery of all items')
        ->assertSee('href="#'.$nextTarget.'-'.$order->items->first()->id.'"', false);
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'stock-check-'.$order->items->first()->id)->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeFalse();
})->with([
    'shortage still unresolved' => ['Unavailable', 'stock-check'],
    'stock found but quality needs reconfirmation' => ['Available', 'production-item'],
]);

test('ready items open delivery with the optional target and receipt controls together', function (): void {
    $order = readyChecklistOrder();

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Confirm delivery after all items are received.')
        ->assertSeeTextInOrder(['2. Deliver order', 'Set delivery target (optional)', 'Confirm full delivery', '3. Complete order'])
        ->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'delivery-target-editor')->tagName)->toBe('dialog');
    expect(orderChecklistElement($document, 'delivery-target-editor')->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'delivery-confirmation')->tagName)->toBe('dialog');
    expect(orderChecklistElement($document, 'delivery-confirmation')->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'schedule-target-date')->hasAttribute('required'))->toBeFalse();
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeFalse();
});

test('a recorded delivery target can be reviewed without hiding delivery confirmation', function (): void {
    $order = readyChecklistOrder(['target_delivery_date' => '2026-11-08']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Nov 8, 2026')->assertSeeText('Edit delivery target');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'delivery-target-editor')->hasAttribute('open'))->toBeFalse();
    expect(orderChecklistElement($document, 'delivery-confirmation')->hasAttribute('open'))->toBeFalse();
});

test('recorded item readiness offers delivery even without an order tracking timestamp', function (): void {
    $order = readyChecklistOrder(['item_tracking_started_at' => null]);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Confirm delivery after all items are received.')
        ->assertDontSee('Review previous order progress.');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'delivery-confirmation')->hasAttribute('open'))->toBeFalse();
    $this->assertDatabaseCount('order_status_histories', 0);
});

test('ready items with an earlier order status direct the administrator to review stock', function (string $status): void {
    $order = readyChecklistOrder(['status' => $status]);
    $item = $order->items->first();

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Review recorded item progress.')
        ->assertSee('href="#stock-check-'.$item->id.'"', false)->assertDontSee('Confirm delivery of all items');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status]);
})->with(['Pending', 'In production']);

test('confirmed delivery with a balance directs the user to record payment before completion', function (): void {
    $order = deliveredChecklistOrder();
    Payment::factory()->for($order)->create(['amount' => '25.00', 'payment_type' => 'full_payment']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Record the remaining payment.')->assertSeeText('100.00')
        ->assertSee('href="'.route('orders.payments.create', $order).'"', false)->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeFalse();
});

test('completed orders with an outstanding balance still direct the user to settle payment', function (): void {
    $order = deliveredChecklistOrder(['status' => 'Completed']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Record the remaining payment.')
        ->assertSeeText('The order is marked Completed, but its balance is still outstanding.')
        ->assertDontSee('Order complete.')->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
});

test('settling payment before delivery still directs the administrator to delivery', function (): void {
    $order = readyChecklistOrder();
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Confirm delivery after all items are received.')->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeFalse();
});

test('confirmed delivery and a settled balance offer completion in the final step', function (): void {
    $order = deliveredChecklistOrder();
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Complete this order.')->assertSeeText('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
    $xpath = new DOMXPath($document);
    expect($xpath->query('//*[@id="order-step-complete"]//form/input[@name="action" and @value="complete"]')->length)->toBe(1);
    expect($xpath->query('//*[@id="order-step-deliver"]//form/input[@name="action" and @value="complete"]')->length)->toBe(0);
});

test('verified completed orders explain that no further confirmations are needed', function (): void {
    $order = deliveredChecklistOrder(['status' => 'Completed']);
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Order complete.')->assertSeeText('No further confirmations are needed.')
        ->assertDontSee('Mark order Completed')->assertDontSee('Confirm delivery of all items');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
});

test('historical progress without tracking opens explicit correction guidance', function (string $status): void {
    $order = Order::factory()->hasItems(1)->create(['status' => $status]);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Review previous order progress.')->assertDontSee('Order complete.')
        ->assertDontSee('Mark order Completed')->assertDontSee('Confirm delivery of all items');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-correction-details')->tagName)->toBe('dialog');
    expect(orderChecklistElement($document, 'order-correction-details')->hasAttribute('open'))->toBeFalse();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => $status, 'delivery_confirmed_at' => null]);
    $this->assertDatabaseCount('order_status_histories', 0);
})->with(['In production', 'Ready for delivery', 'Delivered', 'Completed']);

test('partial delivery evidence never offers completion even when fully paid', function (array $missingEvidence): void {
    $order = deliveredChecklistOrder($missingEvidence);
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Review previous order progress.')->assertDontSee('Mark order Completed')
        ->assertDontSee('Full delivery confirmed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-correction-details')->hasAttribute('open'))->toBeFalse();
})->with([
    'missing actual date' => [['delivery_date' => null]],
    'blank recipient' => [['delivery_recipient' => ' ']],
    'missing confirmation time' => [['delivery_confirmed_at' => null]],
    'blank confirming administrator' => [['delivery_confirmed_by_name' => ' ']],
]);

test('an empty order explains why preparation cannot proceed', function (): void {
    $order = Order::factory()->create();

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('No items are recorded, so stock checks and delivery cannot proceed.')
        ->assertSee('href="#items-heading"', false)->assertDontSee('Start item production')->assertDontSee('Mark order Completed');
});

test('staff can follow progress and schedule their order while admin confirmations stay unavailable', function (): void {
    $staff = User::factory()->create();
    $order = readyChecklistOrder(['created_by' => $staff->id]);
    $this->actingAs($staff);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Awaiting administrator.')->assertSeeText('Confirm delivery after all items are received.')
        ->assertSeeText('Set delivery target (optional)')->assertDontSee('Save stock check')
        ->assertDontSee('Confirm delivery of all items')->assertDontSee('Mark order Completed')->assertDontSee('Reopen as Pending');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect($document->getElementById('delivery-confirmation'))->toBeNull();
    $xpath = new DOMXPath($document);
    expect($xpath->query('//form[@action="'.route('orders.status.update', $order).'"]')->length)->toBe(0);
});

test('staff are directed to the remaining payment after confirmed delivery', function (): void {
    $staff = User::factory()->create();
    $order = deliveredChecklistOrder(['created_by' => $staff->id]);
    $this->actingAs($staff);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Record the remaining payment.')
        ->assertSee('href="'.route('orders.payments.create', $order).'"', false)->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
});

test('fully paid delivered orders explain that staff are waiting for administrator completion', function (): void {
    $staff = User::factory()->create();
    $order = deliveredChecklistOrder(['created_by' => $staff->id]);
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);
    $this->actingAs($staff);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Awaiting administrator.')->assertSeeText('An administrator can now mark this order Completed.')
        ->assertDontSee('Mark order Completed')->assertDontSee('Reopen as Pending');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
});

test('a failed stock update reopens that stock check even when delivery is the current step', function (): void {
    $order = readyChecklistOrder();
    $item = $order->items->first();

    $this->post(route('orders.items.production.update', ['order' => $order, 'item' => $item]), orderChecklistPayload($order, 'check_availability', [
        'order_item_id' => $item->id,
        'stock_availability' => '',
    ]))->assertRedirectToRoute('orders.show', $order)->assertSessionHasErrors('stock_availability');
    $this->withCookie(config('session.cookie'), session()->getId());
    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('The stock availability field is required.');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'stock-check-'.$item->id)->hasAttribute('data-checklist-auto-open'))->toBeTrue();
    expect(orderChecklistElement($document, 'availability-'.$item->id)->getAttribute('aria-invalid'))->toBe('true');
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 0, 'status' => 'Ready for delivery']);
});

test('schedule failures open the target editor while preparation is still current', function (array $invalidInput, string $errorField): void {
    $order = Order::factory()->hasItems(1)->create(['target_delivery_date' => '2026-11-08', 'workflow_version' => 1]);

    $this->from(route('orders.show', $order))->post(route('orders.schedule.update', $order), orderChecklistPayload($order, 'schedule_delivery', [
        'target_delivery_date' => '2026-11-09',
        ...$invalidInput,
    ]))->assertRedirectToRoute('orders.show', $order)->assertSessionHasErrors($errorField);
    $this->withCookie(config('session.cookie'), session()->getId());
    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Check finished-stock availability.');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'delivery-target-editor')->hasAttribute('data-checklist-auto-open'))->toBeTrue();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 1, 'target_delivery_date' => '2026-11-08']);
})->with([
    'target before the order date' => [['target_delivery_date' => '2026-11-04'], 'target_delivery_date'],
    'another administrator changed the order' => [['workflow_version' => 0], 'workflow_version'],
]);

test('a failed reopening shows its correction reason in the preparation step', function (): void {
    $order = deliveredChecklistOrder();
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $this->post(route('orders.status.update', $order), orderChecklistPayload($order, 'reopen', ['notes' => '']))
        ->assertRedirectToRoute('orders.show', $order)->assertSessionHasErrors('notes');
    $this->withCookie(config('session.cookie'), session()->getId());
    $response = $this->get(route('orders.show', $order));

    $response->assertOk();
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-prepare')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-correction-details')->hasAttribute('data-checklist-auto-open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Delivered', 'workflow_version' => 0]);
});

test('an attempted early completion opens the completion requirements alongside delivery', function (): void {
    $order = readyChecklistOrder();
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);

    $this->from(route('orders.show', $order))->post(route('orders.status.update', $order), orderChecklistPayload($order, 'complete'))
        ->assertRedirectToRoute('orders.show', $order)->assertSessionHasErrors('action');
    $this->withCookie(config('session.cookie'), session()->getId());
    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('Full delivery must be confirmed in step 2 before completing the order.')->assertDontSee('Mark order Completed');
    $document = orderChecklistDocument($response->getContent());
    expect(orderChecklistElement($document, 'order-step-deliver')->hasAttribute('open'))->toBeTrue();
    expect(orderChecklistElement($document, 'order-step-complete')->hasAttribute('open'))->toBeTrue();
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Ready for delivery', 'workflow_version' => 0]);
});

test('guidance advances through stock production delivery payment and completion after each save', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    [$stockItem, $productionItem] = $order->items->all();
    $stockUrl = route('orders.items.production.update', ['order' => $order, 'item' => $stockItem]);
    $productionUrl = route('orders.items.production.update', ['order' => $order, 'item' => $productionItem]);
    $statusUrl = route('orders.status.update', $order);
    $showUrl = route('orders.show', $order);

    $this->get($showUrl)->assertOk()->assertSeeText('Check finished-stock availability.');
    $this->post($stockUrl, orderChecklistPayload($order, 'check_availability', ['stock_availability' => 'Available']))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSee('href="#stock-check-'.$productionItem->id.'"', false);
    $this->post($productionUrl, orderChecklistPayload($order, 'check_availability', ['stock_availability' => 'Partially available']))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('Start production for remaining items.');
    $this->post($productionUrl, orderChecklistPayload($order, 'start_production'))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('Confirm item readiness.');
    $this->post($stockUrl, orderChecklistPayload($order, 'mark_ready', ['quality_checked' => true]))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('1 of 2 items ready');
    $this->post($productionUrl, orderChecklistPayload($order, 'mark_ready', ['quality_checked' => true]))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('Confirm delivery after all items are received.');
    $this->post($statusUrl, orderChecklistPayload($order, 'deliver', [
        'delivery_date' => '2026-11-05', 'delivery_recipient' => 'Alex Cruz',
    ]))->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('Record the remaining payment.')->assertDontSee('Mark order Completed');
    $this->post(route('orders.payments.store', $order), [
        'submission_key' => (string) Str::uuid(), 'amount' => '250.00',
        'payment_date' => '2026-11-05', 'payment_type' => 'full_payment',
    ])->assertRedirectToRoute('orders.show', $order);
    $this->get($showUrl)->assertOk()->assertSeeText('Complete this order.');
    $this->post($statusUrl, orderChecklistPayload($order, 'complete'))->assertRedirectToRoute('orders.show', $order);
    $response = $this->get($showUrl);

    $response->assertOk()->assertSeeText('Order complete.');
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Completed', 'target_delivery_date' => null, 'delivery_recipient' => 'Alex Cruz']);
    $this->assertDatabaseHas('order_items', ['id' => $stockItem->id, 'stock_availability' => ItemAvailability::Available->value, 'readiness_source' => 'stock']);
    $this->assertDatabaseHas('order_items', ['id' => $productionItem->id, 'production_status' => ItemProductionStatus::ReadyForDelivery->value, 'readiness_source' => 'production']);
    $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => '250.00', 'payment_type' => 'full_payment']);
    $this->assertDatabaseCount('order_status_histories', 9);
});

test('multiple items have separate table actions and forms even when their product names match', function (): void {
    $order = Order::factory()->create();
    $stockItem = OrderItem::factory()->for($order)->create(['product_name' => 'Plastic bags', 'quantity' => '10', 'stock_availability' => 'Available']);
    $productionItem = OrderItem::factory()->for($order)->create(['product_name' => 'Plastic bags', 'quantity' => '20', 'stock_availability' => 'Unavailable']);
    $readyItem = OrderItem::factory()->for($order)->readyForDelivery()->create(['product_name' => 'Plastic bags', 'quantity' => '30']);

    $response = $this->get(route('orders.show', $order));

    $response->assertOk()->assertSeeText('1 of 3 items ready');
    $document = orderChecklistDocument($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//tr[@data-checklist-item]')->length)->toBe(3);
    expect($xpath->query('//tr[@data-checklist-item]//form')->length)->toBe(0);
    expect(orderChecklistElement($document, 'progress-action-'.$stockItem->id)->textContent)->toBe('Confirm ready');
    expect(orderChecklistElement($document, 'progress-action-'.$productionItem->id)->textContent)->toBe('Start production');
    expect($document->getElementById('progress-action-'.$readyItem->id))->toBeNull();
    foreach ([$stockItem, $productionItem, $readyItem] as $item) {
        $dialog = orderChecklistElement($document, 'stock-check-'.$item->id);
        $form = $dialog->getElementsByTagName('form')->item(0);
        expect($form->getAttribute('action'))->toBe(route('orders.items.production.update', ['order' => $order, 'item' => $item]));
        expect($xpath->query('.//input[@name="order_item_id"]', $form)->item(0)->getAttribute('value'))->toBe((string) $item->id);
        expect($dialog->hasAttribute('open'))->toBeFalse();
    }
});

test('refreshing the checklist returns its section without the surrounding page or extra view audit entries', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    $url = route('orders.show', ['order' => $order, 'search' => 'Plastic & bags', 'page' => 2]);

    $response = $this->get($url, ['X-Order-Checklist-Fragment' => '1']);

    $response->assertOk()->assertSeeText('Order checklist')->assertSeeText('0 of 2 items ready')
        ->assertSee('data-checklist-url="'.e($url).'"', false)
        ->assertDontSee('<html', false)->assertDontSee('Order information')->assertDontSee('Customer information')
        ->assertDontSee('Status history')->assertDontSee('data-order-loading', false);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->assertDatabaseEmpty('audit_logs');
});

test('a checklist refresh can include current history while preserving the selected history page', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    OrderStatusHistory::factory()->for($order)->recycle(auth()->user())->count(11)->create(['notes' => 'Recorded change']);

    $response = $this->get(route('orders.show', ['order' => $order, 'history_page' => 2]), [
        'X-Order-Checklist-Fragment' => '1', 'X-Order-Checklist-History' => '1',
    ]);

    $response->assertOk()->assertSee('data-checklist-history', false)->assertSeeText('Recorded change')
        ->assertSee('history_page=1', false)->assertDontSee('Order information');
    $document = orderChecklistDocument($response->getContent());
    expect((new DOMXPath($document))->query('//template[@data-checklist-history]//section/ol/li')->length)->toBe(1);
    $this->assertDatabaseEmpty('audit_logs');
});

test('checklist refreshes require sign in', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    auth()->logout();

    $response = $this->get(route('orders.show', $order), ['X-Order-Checklist-Fragment' => '1']);

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('checklist fragments do not expose another staffs order or allow its items to be changed', function (): void {
    $order = Order::factory()->hasItems(1, ['product_name' => 'Private order item'])->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('orders.show', $order), ['X-Order-Checklist-Fragment' => '1'])
        ->assertNotFound()->assertDontSee('Private order item');
    $this->withHeader('X-Order-Checklist-Fragment', '1')
        ->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $order->items->first()]), orderChecklistPayload($order, 'check_availability', ['stock_availability' => 'Available']))
        ->assertNotFound();

    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 0]);
    $this->assertDatabaseEmpty('order_status_histories');
});

test('staff can refresh their checklist and schedule delivery while item confirmations stay admin only', function (): void {
    $staff = User::factory()->create();
    $order = Order::factory()->hasItems(1)->for($staff, 'creator')->create();
    $this->actingAs($staff);

    $this->get(route('orders.show', $order), ['X-Order-Checklist-Fragment' => '1'])
        ->assertOk()->assertSeeText('View details')->assertSeeText('Set delivery target (optional)')
        ->assertDontSee('Save stock check')->assertDontSee('Reopen as Pending');
    $this->withHeader('X-Order-Checklist-Fragment', '1')
        ->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $order->items->first()]), orderChecklistPayload($order, 'check_availability', ['stock_availability' => 'Available']))
        ->assertForbidden();
    $response = $this->postJson(route('orders.schedule.update', $order), orderChecklistPayload($order, 'schedule_delivery', ['target_delivery_date' => '2026-11-08']));

    $response->assertOk()->assertJsonPath('message', 'Delivery schedule saved.')->assertJsonPath('workflow_version', 1);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Pending', 'target_delivery_date' => '2026-11-08', 'workflow_version' => 1]);
    $this->assertDatabaseHas('order_items', ['id' => $order->items->first()->id, 'stock_availability' => 'Not checked']);
    $this->assertDatabaseCount('order_status_histories', 1);
});

test('modal stock saves return the updated checklist version and exact retries do not duplicate the change', function (): void {
    $order = Order::factory()->hasItems(2)->create();
    $item = $order->items->first();
    $url = route('orders.items.production.update', ['order' => $order, 'item' => $item]);
    $payload = orderChecklistPayload($order, 'check_availability', ['stock_availability' => 'Available', 'notes' => 'Stock confirmed for the first item']);

    $response = $this->withHeader('X-Order-Checklist-Fragment', '1')->postJson($url, $payload);

    $response->assertOk()->assertJsonPath('message', 'Item availability and progress saved successfully.')->assertJsonPath('workflow_version', 1);
    $this->postJson($url, $payload)->assertOk()->assertJsonPath('workflow_version', 1);
    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'stock_availability' => 'Available', 'availability_notes' => 'Stock confirmed for the first item']);
    $this->assertDatabaseHas('order_items', ['id' => $order->items->last()->id, 'stock_availability' => 'Not checked']);
    $this->assertDatabaseCount('order_status_histories', 1);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->get(route('orders.show', $order))->assertOk()->assertSeeText('Confirm ready')->assertSee('data-workflow-version="1"', false);
    $this->assertDatabaseCount('audit_logs', 1);
});

test('outdated modal submissions return an error and refreshing provides the current form version', function (): void {
    $order = Order::factory()->hasItems(1)->create(['workflow_version' => 1]);
    $item = $order->items->first();

    $response = $this->withHeader('X-Order-Checklist-Fragment', '1')
        ->postJson(route('orders.items.production.update', ['order' => $order, 'item' => $item]), orderChecklistPayload($order, 'check_availability', ['workflow_version' => 0, 'stock_availability' => 'Available']));

    $response->assertUnprocessable()->assertJsonValidationErrors('workflow_version')
        ->assertJsonPath('errors.workflow_version.0', 'This order changed after you opened the form. Refresh the order checklist and review its latest progress.');
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'workflow_version' => 1]);
    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'stock_availability' => 'Not checked']);
    $this->assertDatabaseEmpty('order_status_histories');
    $this->get(route('orders.show', $order))->assertOk()->assertSee('name="workflow_version" value="1"', false);
});

test('modal delivery saves require actual delivery details and update the order without a redirect', function (): void {
    $order = readyChecklistOrder();
    $url = route('orders.status.update', $order);

    $this->withHeader('X-Order-Checklist-Fragment', '1')->postJson($url, orderChecklistPayload($order, 'deliver'))
        ->assertUnprocessable()->assertJsonValidationErrors(['delivery_date', 'delivery_recipient']);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Ready for delivery', 'workflow_version' => 0]);
    $this->assertDatabaseEmpty('order_status_histories');
    $response = $this->postJson($url, orderChecklistPayload($order, 'deliver', ['delivery_date' => '2026-11-05', 'delivery_recipient' => 'Alex Cruz']));

    $response->assertOk()->assertJsonPath('message', 'Order progress saved successfully.')->assertJsonPath('workflow_version', 1);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Delivered', 'delivery_date' => '2026-11-05', 'delivery_recipient' => 'Alex Cruz', 'workflow_version' => 1]);
    $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'event_type' => 'delivery_confirmed']);
});

test('modal completion still requires a settled balance after delivery', function (): void {
    $order = deliveredChecklistOrder();
    $url = route('orders.status.update', $order);

    $this->withHeader('X-Order-Checklist-Fragment', '1')->postJson($url, orderChecklistPayload($order, 'complete'))
        ->assertUnprocessable()->assertJsonValidationErrors('action');
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Delivered', 'workflow_version' => 0]);
    $this->assertDatabaseEmpty('order_status_histories');
    Payment::factory()->for($order)->create(['amount' => '125.00', 'payment_type' => 'full_payment']);
    $response = $this->postJson($url, orderChecklistPayload($order, 'complete'));

    $response->assertOk()->assertJsonPath('workflow_version', 1);
    $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Completed']);
    $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'event_type' => 'order_completed']);
});

test('failed modal saves return a retry message and roll back item order and audit changes', function (string $routeName, string $action, array $input, string $message): void {
    $order = Order::factory()->hasItems(1)->create(['status' => 'In production', 'item_tracking_started_at' => now(), 'target_delivery_date' => '2026-11-07']);
    $parameters = ['order' => $order];
    if ($routeName === 'orders.items.production.update') {
        $parameters['item'] = $order->items->first();
    }
    OrderStatusHistory::creating(function (): never {
        throw new RuntimeException('Private database write details');
    });

    try {
        $response = $this->withHeader('X-Order-Checklist-Fragment', '1')->postJson(route($routeName, $parameters), orderChecklistPayload($order, $action, $input));

        $response->assertServiceUnavailable()->assertJsonPath('message', $message)->assertDontSee('Private database write details');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'In production', 'workflow_version' => 0, 'target_delivery_date' => '2026-11-07']);
        $this->assertDatabaseHas('order_items', ['id' => $order->items->first()->id, 'stock_availability' => 'Not checked', 'production_status' => 'Pending']);
        $this->assertDatabaseEmpty('order_status_histories');
        $this->assertDatabaseEmpty('audit_logs');
    } finally {
        OrderStatusHistory::flushEventListeners();
    }
})->with([
    'stock update' => ['orders.items.production.update', 'check_availability', ['stock_availability' => 'Available'], 'The item progress could not be saved. Please try again.'],
    'order correction' => ['orders.status.update', 'reopen', ['notes' => 'Correct this order'], 'The status could not be saved. Please try again.'],
    'delivery schedule' => ['orders.schedule.update', 'schedule_delivery', ['target_delivery_date' => '2026-11-08'], 'The delivery schedule could not be saved. Please try again.'],
]);

test('item names and stock notes remain escaped in checklist tables and modals', function (): void {
    $order = Order::factory()->hasItems(1, ['product_name' => '<script>unsafe item</script>', 'availability_notes' => '<svg onload="alert(1)">'])->create();

    $response = $this->get(route('orders.show', $order), ['X-Order-Checklist-Fragment' => '1']);

    $response->assertOk()->assertSee('<script>unsafe item</script>')->assertSee('<svg onload="alert(1)">')
        ->assertDontSee('<script>unsafe item</script>', false)->assertDontSee('<svg onload="alert(1)">', false);
});
