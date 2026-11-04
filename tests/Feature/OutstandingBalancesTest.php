<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\OutstandingBalances;
use App\PaymentEntries;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
function balanceOrder(array $overrides = []): Order
{
    return Order::factory()->hasItems(1)->create(['order_date' => '2026-11-01', ...$overrides]);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function dueDatePayload(Order $order, array $overrides = []): array
{
    return array_replace(['submission_key' => (string) Str::uuid(), 'workflow_version' => $order->workflow_version ?? 0, 'payment_due_date' => '2026-11-04'], $overrides);
}

test('balances aggregate remaining amounts per customer and prioritize overdue collection', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Overdue customer']);
    $partial = balanceOrder(['customer_id' => $customer->id, 'payment_due_date' => '2026-11-04', 'status' => 'Delivered']);
    $partial->payments()->create(['amount' => '50.00', 'payment_date' => '2026-11-04', 'payment_type' => 'down_payment']);
    balanceOrder(['customer_id' => $customer->id, 'payment_due_date' => '2026-11-06']);
    $paid = balanceOrder();
    $paid->payments()->create(['amount' => '125.00', 'payment_date' => '2026-11-04', 'payment_type' => 'full_payment']);
    $response = $this->get(route('balances.index'));
    $response->assertOk()->assertSee('Overdue customer')->assertSee('Delivered');
    expect($response['data']['outstanding_in_cents'])->toBe(20000)->and($response['data']['orders_count'])->toBe(2)->and($response['data']['overdue_count'])->toBe(1);
    $total = $response['data']['customers']->first();
    expect($total->outstanding_in_cents)->toBe(20000)->and($total->unpaid_orders_count)->toBe(2)->and($response['data']['orders']->first()->id)->toBe($partial->id);
});

test('reporting timezone distinguishes overdue due today future and missing dates', function (): void {
    foreach (['2026-11-04', '2026-11-05', '2026-11-06', null] as $due) {
        balanceOrder(['payment_due_date' => $due]);
    }
    $summary = app(OutstandingBalances::class)->summary($this->admin);
    expect($summary['today'])->toBe('2026-11-05')->and($summary['overdue_count'])->toBe(1);
    foreach (['overdue', 'today', 'unscheduled'] as $filter) {
        $response = $this->get(route('balances.index', ['due' => $filter]));
        $response->assertOk();
        expect($response['data']['orders_count'])->toBe(1);
    }
});

test('staff customer aggregates include only accessible orders and no hidden links', function (): void {
    $staff = User::factory()->create();
    $shared = Customer::factory()->create(['company_name' => 'Shared customer']);
    balanceOrder(['customer_id' => $shared->id, 'created_by' => $staff->id]);
    balanceOrder(['customer_id' => $shared->id, 'created_by' => $this->admin->id]);
    $owned = Customer::factory()->for($staff, 'creator')->create();
    balanceOrder(['customer_id' => $owned->id, 'created_by' => $this->admin->id]);
    $hidden = Customer::factory()->create(['company_name' => 'Hidden collection customer']);
    balanceOrder(['customer_id' => $hidden->id]);
    $this->actingAs($staff);
    $response = $this->get(route('balances.index'));
    $response->assertOk()->assertSee('These are not global customer balances')->assertDontSee('Hidden collection customer');
    expect($response['data']['orders_count'])->toBe(2)->and($response['data']['outstanding_in_cents'])->toBe(25000);
    expect($response['data']['customers']->firstWhere('id', $shared->id)->outstanding_in_cents)->toBe(12500);
    $this->get(route('balances.index', ['customer_id' => $hidden->id]))->assertOk()->assertSee('No outstanding balances')->assertDontSee('Hidden collection customer');
});

test('real payments immediately update customer totals overdue counts and dashboard panels', function (): void {
    $order = balanceOrder(['payment_due_date' => '2026-11-04']);
    expect(app(OutstandingBalances::class)->summary($this->admin)['overdue_count'])->toBe(1);
    app(PaymentEntries::class)->create($order->id, ['submission_key' => (string) Str::uuid(), 'amount' => '50.00', 'payment_date' => '2026-11-05', 'payment_type' => 'down_payment']);
    $response = $this->get(route('dashboard'));
    $response->assertOk()->assertSee('Outstanding balances');
    expect($response['outstanding']['outstanding_in_cents'])->toBe(7500);
    app(PaymentEntries::class)->create($order->id, ['submission_key' => (string) Str::uuid(), 'amount' => '75.00', 'payment_date' => '2026-11-05', 'payment_type' => 'full_payment']);
    expect(app(OutstandingBalances::class)->summary($this->admin)['orders_count'])->toBe(0)->and(app(OutstandingBalances::class)->summary($this->admin)['overdue_count'])->toBe(0);
});

test('database subtotals match exact per-item rounding for pieces weights and large quantities', function (string $quantity, string $price): void {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create(['quantity' => $quantity, 'unit_price' => $price]);
    $expected = $item->subtotalInCents();
    $item->refresh();
    $order->items()->create(['product_name' => 'Rounding line', 'quantity' => '0.01 kgs', 'unit_price' => '0.50']);
    $expected++;
    $summary = app(OutstandingBalances::class)->summary($this->admin);
    expect($summary['outstanding_in_cents'])->toBe($expected);
})->with([
    ['3 pcs', '1.25'], ['2.35 kgs', '1.25'], ['0.01 kgs', '0.50'], ['99999999.99 kgs', '999999.99'],
    ['100000000 pcs', '999999.99'], ['1 pcs', '0.00'], ['100', '1.25'], ['99999999.97 kgs', '999999.97'],
]);

test('customer and order pagination remain bounded and filters affect totals', function (): void {
    $client = Customer::factory()->create(['company_name' => 'Repeat collection client']);
    Order::factory()->for($client)->hasItems(1)->count(22)->create();
    DB::enableQueryLog();
    $response = $this->get(route('balances.index', ['search' => 'Repeat collection']));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk()->assertSee('balance_orders_page=2', false);
    expect($response['data']['orders']->count())->toBe(10)->and($response['data']['orders_count'])->toBe(22)->and(count($queries))->toBeLessThan(12);
    foreach ($response['data']['orders'] as $order) {
        expect($order->relationLoaded('items'))->toBeFalse()->and($order->relationLoaded('payments'))->toBeFalse();
    }
    $this->get(route('balances.index', ['balance_orders_page' => 3]))->assertOk()->assertViewHas('data', fn ($data) => $data['orders']->count() === 2);
});

test('admin due date edits retain terms and are audited idempotent and revision checked', function (): void {
    $order = balanceOrder(['terms_of_payment' => 'Net 30', 'due_date' => '2026-11-30']);
    $payload = dueDatePayload($order);
    $this->postJson(route('orders.payment-due-date.update', $order), $payload)->assertRedirect();
    $this->postJson(route('orders.payment-due-date.update', $order), $payload)->assertRedirect();
    expect($order->fresh()->payment_due_date->toDateString())->toBe('2026-11-04')->and($order->fresh()->terms_of_payment)->toBe('Net 30')->and($order->fresh()->due_date->toDateString())->toBe('2026-11-30');
    expect($order->statusHistory()->count())->toBe(1);
    $this->postJson(route('orders.payment-due-date.update', $order), dueDatePayload($order))->assertUnprocessable()->assertJsonValidationErrors('workflow_version');
    $this->postJson(route('orders.payment-due-date.update', $order), [...$payload, 'payment_due_date' => '2026-11-07'])->assertUnprocessable()->assertJsonValidationErrors('submission_key');
    $this->postJson(route('orders.payment-due-date.update', $order), dueDatePayload($order->fresh(), ['payment_due_date' => null]))->assertRedirect();
    expect($order->fresh()->payment_due_date)->toBeNull();
});

test('staff cannot edit due dates even for their own orders and hidden orders stay hidden', function (bool $visible): void {
    $staff = User::factory()->create();
    $order = balanceOrder(['created_by' => $visible ? $staff->id : $this->admin->id]);
    $this->actingAs($staff);
    $this->postJson(route('orders.payment-due-date.update', $order), dueDatePayload($order))->assertStatus($visible ? 403 : 404);
    if ($visible) {
        $this->get(route('orders.show', $order))->assertOk()->assertDontSee('Edit payment due date');
    }
    expect($order->fresh()->payment_due_date)->toBeNull();
})->with([true, false]);

test('payment due dates validate and remain optional without parsing credit terms', function (): void {
    $order = balanceOrder();
    foreach (['2026-10-31', '2026-02-30', 'Net 30'] as $date) {
        $this->postJson(route('orders.payment-due-date.update', $order), dueDatePayload($order, ['payment_due_date' => $date]))->assertUnprocessable()->assertJsonValidationErrors('payment_due_date');
    }
    $this->get(route('orders.show', $order))->assertOk()->assertSee('No payment due date recorded');
    expect($order->fresh()->payment_due_date)->toBeNull();
});

test('creation accepts admin due dates and rejects forged staff due dates', function (): void {
    $data = [
        'submission_key' => (string) Str::uuid(), 'customer_name' => 'Credit client', 'contact_person' => 'Alex',
        'contact_number' => '09123456789', 'address' => 'Test address', 'order_date' => '2026-11-01', 'terms_of_payment' => 'Net 30',
        'status' => 'Pending', 'payment_due_date' => '2026-11-30', 'items' => [['product_name' => 'Bags', 'quantity' => '10', 'unit_price' => '1.25', 'unit' => 'pcs']],
    ];
    $this->postJson(route('orders.store'), $data)->assertRedirect();
    expect(Order::query()->first()->payment_due_date->toDateString())->toBe('2026-11-30');
    $this->actingAs(User::factory()->create());
    $this->postJson(route('orders.store'), [...$data, 'submission_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('payment_due_date');
});

test('collection pages require sign in', function (): void {
    auth()->logout();
    $this->get(route('balances.index'))->assertRedirect(route('login'));
});

test('customer pagination prioritizes overdue customers ahead of larger current balances', function (): void {
    $overdue = balanceOrder(['payment_due_date' => '2026-11-04']);
    Order::factory()->hasItems(3)->count(11)->create(['payment_due_date' => '2026-11-20']);
    $response = $this->get(route('balances.index'));
    $response->assertOk()->assertSee('balance_customers_page=2', false);
    expect($response['data']['customers']->total())->toBe(12)->and($response['data']['customers']->count())->toBe(10)
        ->and($response['data']['customers']->first()->id)->toBe($overdue->customer_id);
});

test('failed due date persistence rolls back the date and audit event together', function (): void {
    $order = balanceOrder();
    Exceptions::fake();
    Order::updating(function (Order $saving): void {
        throw new RuntimeException('Due date persistence failure');
    });
    $this->post(route('orders.payment-due-date.update', $order), dueDatePayload($order))->assertSessionHas('error');
    expect($order->fresh()->payment_due_date)->toBeNull()->and($order->statusHistory()->count())->toBe(0);
});
