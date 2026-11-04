<?php

use App\DailyPerformance;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['app.reporting_timezone' => 'Asia/Manila']);
    $this->travelTo(new DateTimeImmutable('2026-11-04 12:00:00 UTC'));
});

test('staff dashboard shows personal totals lists statuses and account details', function (): void {
    $staff = User::factory()->create(['name' => 'Staff owner', 'email' => 'owner@example.com']);
    $other = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $ownClient = Customer::factory()->for($staff, 'creator')->create(['company_name' => 'Personal client']);
    Customer::factory()->for($staff, 'creator')->create(['company_name' => 'Without orders']);
    $privateClient = Customer::factory()->for($other, 'creator')->create(['company_name' => 'Private client']);
    Customer::factory()->create(['company_name' => 'Legacy client']);
    $ownOrder = Order::factory()->for($ownClient)->for($staff, 'creator')->create(['status' => 'In production']);
    $adminOrder = Order::factory()->for($ownClient)->for($admin, 'creator')->create(['status' => 'Completed']);
    Order::factory()->for($privateClient)->for($other, 'creator')->create();
    Order::factory()->create();

    $response = $this->actingAs($staff)->get(route('dashboard'));

    $response->assertOk()->assertSee('Staff owner')->assertSee('owner@example.com')->assertSee('Staff')
        ->assertSee('Clients you created')->assertSee('Orders you created')->assertSee('Personal client')->assertSee('Without orders')
        ->assertSee('Daily creation performance')->assertSee('Asia/Manila')
        ->assertDontSee('Private client')->assertDontSee('Legacy client')->assertDontSee('Staff accounts')
        ->assertSee('href="'.route('orders.show', $ownOrder).'"', false)
        ->assertDontSee('href="'.route('orders.show', $adminOrder).'"', false);
    expect($response['metrics']['clients'])->toBe(['total' => 2, 'with_orders' => 1, 'without_orders' => 1]);
    expect($response['metrics']['orders'])->toBe(1);
    expect(array_column($response['metrics']['statuses'], 'count'))->toBe([0, 1, 0, 0, 0]);
    expect($response['clients']->total())->toBe(2);
    expect($response['orders']->pluck('id')->all())->toBe([$ownOrder->id]);
    expect($response['performance']['days'][29])->toBe(['date' => '2026-11-04', 'clients' => 2, 'orders' => 1]);
    $this->get(route('orders.index'))->assertOk();
});

test('shared client orders count toward the order creators dashboard without counting the client as theirs', function (): void {
    $staff = User::factory()->create();
    $other = User::factory()->create();
    $client = Customer::factory()->for($other, 'creator')->create(['company_name' => 'Shared client']);
    $ownOrder = Order::factory()->for($client)->for($staff, 'creator')->hasItems(1)->create(['status' => 'Completed']);
    $otherOrder = Order::factory()->for($client)->for($other, 'creator')->hasItems(1)->create(['status' => 'Pending']);

    $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertSee('Shared client');

    expect($response['metrics']['clients'])->toBe(['total' => 0, 'with_orders' => 0, 'without_orders' => 0]);
    expect($response['metrics']['orders'])->toBe(1);
    expect(array_column($response['metrics']['statuses'], 'count'))->toBe([0, 0, 0, 0, 1]);
    expect($response['clients']->total())->toBe(0);
    expect($response['orders']->pluck('id')->all())->toBe([$ownOrder->id]);
    expect($response['performance']['days'][29])->toBe(['date' => '2026-11-04', 'clients' => 0, 'orders' => 1]);
    $this->get(route('orders.show', $otherOrder))->assertNotFound();

    $ownerDashboard = $this->actingAs($other)->get(route('dashboard'))->assertOk();
    expect($ownerDashboard['metrics']['clients']['total'])->toBe(1);
    expect($ownerDashboard['metrics']['orders'])->toBe(1);
    expect($ownerDashboard['orders']->pluck('id')->all())->toBe([$otherOrder->id]);
    $ownerOrders = $this->get(route('orders.index'))->assertOk();
    expect($ownerOrders['orders']->pluck('id')->all())->toBe([$otherOrder->id, $ownOrder->id]);
});

test('new staff has a real empty dashboard even when unassigned and other staff records exist', function (): void {
    $staff = User::factory()->create();
    Order::factory()->create();
    $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertSee('No creation activity in this period.')->assertDontSee('Example Packaging Customer');
    expect($response['metrics']['clients']['total'])->toBe(0);
    expect($response['metrics']['orders'])->toBe(0);
    expect($response['performance']['days'])->toHaveCount(30);
    expect(array_sum(array_column($response['performance']['days'], 'clients')))->toBe(0);
    expect(array_sum(array_column($response['performance']['days'], 'orders')))->toBe(0);
    $this->get(route('orders.index'))->assertOk()->assertSee('No orders yet')->assertDontSee('Sample orders');
});

test('daily activity uses creation timestamps across local midnight and period boundaries', function (): void {
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-11-03 15:59:59']);
    Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-11-03 16:00:00']);
    Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-10-05 16:00:00']);
    Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-10-05 15:59:59']);
    Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-11-04 16:00:00']);
    Order::factory()->for($client)->for($staff, 'creator')->create(['created_at' => '2026-11-03 15:59:59', 'order_date' => '2026-01-01']);
    Order::factory()->for($client)->for($staff, 'creator')->create(['created_at' => '2026-11-03 16:00:00', 'order_date' => '2026-01-01']);
    Order::factory()->for($client)->create(['created_at' => '2026-11-03 16:00:00']);

    $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk();
    $performance = $response['performance'];
    expect($performance['start'])->toBe('2026-10-06');
    expect($performance['end'])->toBe('2026-11-04');
    expect($performance['days'][0])->toBe(['date' => '2026-10-06', 'clients' => 1, 'orders' => 0]);
    expect($performance['days'][1])->toBe(['date' => '2026-10-07', 'clients' => 0, 'orders' => 0]);
    expect($performance['days'][28])->toBe(['date' => '2026-11-03', 'clients' => 1, 'orders' => 1]);
    expect($performance['days'][29])->toBe(['date' => '2026-11-04', 'clients' => 1, 'orders' => 1]);
    expect(array_sum(array_column($performance['days'], 'clients')))->toBe(3);
    expect(array_sum(array_column($performance['days'], 'orders')))->toBe(2);
});

test('reporting timezone can change without changing stored creation timestamps', function (): void {
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create(['created_at' => '2026-11-03 16:00:00']);
    config(['app.reporting_timezone' => 'UTC']);
    $summary = app(DailyPerformance::class)->summary($staff);
    expect($summary['timezone'])->toBe('UTC');
    expect($summary['days'][28])->toBe(['date' => '2026-11-03', 'clients' => 1, 'orders' => 0]);
    expect($summary['days'][29])->toBe(['date' => '2026-11-04', 'clients' => 0, 'orders' => 0]);
    expect($client->refresh()->created_at->toDateTimeString())->toBe('2026-11-03 16:00:00');
});

test('admin dashboard includes all owners and unassigned records', function (): void {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create();
    Order::factory()->for($client)->for($staff, 'creator')->create();
    Order::factory()->create();
    $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    expect($response['metrics']['clients']['total'])->toBe(2);
    expect($response['metrics']['orders'])->toBe(2);
    expect($response['clients']->total())->toBe(2);
    expect($response['orders']->total())->toBe(2);
    expect($response['performance']['days'][29])->toBe(['date' => '2026-11-04', 'clients' => 2, 'orders' => 2]);
});

test('client and order lists paginate independently without exposing other staff records', function (): void {
    $staff = User::factory()->create();
    $other = User::factory()->create();
    Customer::factory()->for($staff, 'creator')->count(7)->create();
    $client = $staff->createdClients()->firstOrFail();
    Order::factory()->for($client)->for($staff, 'creator')->count(8)->create();
    Order::factory()->for($other, 'creator')->create();
    $this->actingAs($staff);
    $first = $this->get(route('dashboard'))->assertOk();
    $second = $this->get(route('dashboard', ['clients_page' => 2, 'orders_page' => 2]))->assertOk();
    expect($first['clients'])->toHaveCount(5);
    expect($first['orders'])->toHaveCount(5);
    expect($second['clients'])->toHaveCount(2);
    expect($second['orders'])->toHaveCount(3);
    expect($second['orders']->total())->toBe(8);
    expect($first['orders']->modelKeys())->not->toContain(...$second['orders']->modelKeys());
    expect($first['clients']->modelKeys())->not->toContain(...$second['clients']->modelKeys());
});

test('dashboard query count and loaded records remain bounded as data grows', function (int $count): void {
    $staff = User::factory()->create();
    $client = Customer::factory()->for($staff, 'creator')->create();
    Order::factory()->for($client)->for($staff, 'creator')->count($count)->hasItems(2)->create();
    $this->actingAs($staff);
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $response = $this->get(route('dashboard'))->assertOk();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(16);
    expect($response['orders']->count())->toBe(min(5, $count));
    expect($response['outstanding']['orders']->count())->toBeLessThanOrEqual(5);
    foreach ($response['outstanding']['orders'] as $order) {
        expect($order->relationLoaded('items'))->toBeFalse()->and($order->relationLoaded('payments'))->toBeFalse();
    }
})->with([1, 50]);

test('dashboard escapes client account and order status text', function (): void {
    $staff = User::factory()->create(['name' => '<script>name()</script>']);
    $client = Customer::factory()->for($staff, 'creator')->create(['company_name' => '<img src=x onerror=alert(1)>', 'contact_person' => '<script>contact()</script>']);
    Order::factory()->for($client)->for($staff, 'creator')->create(['status' => '<script>status()</script>']);
    $response = $this->actingAs($staff)->get(route('dashboard'))->assertOk();
    foreach ([$staff->name, $client->company_name, $client->contact_person, '<script>status()</script>'] as $value) {
        $response->assertSee($value)->assertDontSee($value, false);
    }
});
