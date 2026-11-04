<?php

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('an empty dashboard shows real zero counts instead of illustrative orders', function (): void {
    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertViewIs('dashboard')
        ->assertSee('No orders yet. Create an order to see its status here.')
        ->assertSee('0.0%')->assertDontSee('conic-gradient', false)
        ->assertDontSee('Sample orders')->assertDontSee('Your account');
    expect($response['metrics']['clients'])->toBe(['total' => 0, 'with_orders' => 0, 'without_orders' => 0]);
    expect($response['metrics']['orders'])->toBe(0);
    expect(array_column($response['metrics']['statuses'], 'count'))->toBe([0, 0, 0, 0]);
    expect($response['user'])->toBeNull();
});

test('client totals count each client once and the pie represents all four order stages', function (): void {
    $repeatClient = Customer::factory()->create();
    $secondClient = Customer::factory()->create();
    Customer::factory()->count(2)->create();
    Order::factory()->for($repeatClient)->count(2)->create(['status' => 'Pending']);
    Order::factory()->for($repeatClient)->create(['status' => 'In production']);
    Order::factory()->for($secondClient)->create(['status' => 'Ready for delivery']);
    Order::factory()->for($secondClient)->create(['status' => 'Completed']);

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Total clients')->assertSee('Clients with orders')->assertSee('Clients without orders')->assertSee('Total orders')
        ->assertSee('role="img"', false)->assertSee('aria-describedby="order-status-legend"', false)
        ->assertSee('Order status counts and percentages')
        ->assertSee('conic-gradient(#f59e0b 0.000000deg 144.000000deg, #6366f1 144.000000deg 216.000000deg, #06b6d4 216.000000deg 288.000000deg, #10b981 288.000000deg 360.000000deg)', false)
        ->assertSee('40.0%')->assertSee('20.0%')->assertDontSee('Other status');
    expect($response['metrics']['clients'])->toBe(['total' => 4, 'with_orders' => 2, 'without_orders' => 2]);
    expect($response['metrics']['orders'])->toBe(5);
    expect($response['metrics']['statuses'])->toBe([
        ['label' => 'Pending', 'count' => 2],
        ['label' => 'In production', 'count' => 1],
        ['label' => 'Ready for delivery', 'count' => 1],
        ['label' => 'Completed', 'count' => 1],
    ]);
});

test('a single populated stage fills the entire pie and leaves absent stages at zero', function (string $status, string $color): void {
    Order::factory()->count(3)->create(['status' => $status]);

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertSee('100.0%')->assertSee('0.0%')
        ->assertSee('conic-gradient('.$color.' 0.000000deg 360.000000deg)', false);
    expect($response['metrics']['orders'])->toBe(3);
    expect(array_column($response['metrics']['statuses'], 'count', 'label')[$status])->toBe(3);
    expect(array_column($response['metrics']['statuses'], 'count'))->toContain(0);
})->with([
    'pending' => ['Pending', '#f59e0b'],
    'production' => ['In production', '#6366f1'],
    'delivery' => ['Ready for delivery', '#06b6d4'],
    'completed' => ['Completed', '#10b981'],
]);

test('historical statuses are grouped visibly so the chart does not omit saved orders', function (): void {
    Order::factory()->create(['status' => 'Pending']);
    Order::factory()->create(['status' => 'Cancelled']);
    Order::factory()->create(['status' => '<script>alert(1)</script>']);

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertSee('Other status')->assertSee('33.3%')->assertSee('66.7%')
        ->assertSee('#64748b 120.000000deg 360.000000deg', false)
        ->assertDontSee('Cancelled')->assertDontSee('<script>alert(1)</script>', false);
    expect($response['metrics']['orders'])->toBe(3);
    expect($response['metrics']['statuses'][4])->toBe(['label' => 'Other status', 'count' => 2]);
    expect(array_sum(array_column($response['metrics']['statuses'], 'count')))->toBe(3);
});

test('a client with no orders is supported and counted without inventing an order', function (): void {
    Customer::factory()->create();

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertSee('No orders yet.');
    expect($response['metrics']['clients'])->toBe(['total' => 1, 'with_orders' => 0, 'without_orders' => 1]);
    expect($response['metrics']['orders'])->toBe(0);
});

test('deleting a clients final order moves them into the without orders count', function (): void {
    $order = Order::factory()->hasItems(1)->create();
    $this->delete(route('orders.destroy', ['order' => $order]), ['confirmed' => true])->assertRedirect();

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    expect($response['metrics']['clients'])->toBe(['total' => 1, 'with_orders' => 0, 'without_orders' => 1]);
    expect($response['metrics']['orders'])->toBe(0);
    $this->assertDatabaseHas('customers', ['id' => $order->customer_id]);
});

test('dashboard aggregation stays bounded without hydrating clients orders or related records', function (int $orderCount): void {
    Order::factory()->count($orderCount)->hasItems(2)->hasPayments(1)->create();
    $customerRetrieved = 'eloquent.retrieved: '.Customer::class;
    $orderRetrieved = 'eloquent.retrieved: '.Order::class;
    Event::fake([$customerRetrieved, $orderRetrieved]);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $response = $this->get(route('dashboard'));
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    $response->assertOk();
    expect($response['metrics']['orders'])->toBe($orderCount);
    expect($queries)->toHaveCount(3);
    expect(implode(' ', array_column($queries, 'query')))->not->toContain('order_items', 'payments');
    Event::assertNotDispatched($customerRetrieved);
    Event::assertNotDispatched($orderRetrieved);
})->with(['one order' => 1, 'larger dataset' => 50]);

test('future authenticated user details appear safely while metrics remain independent', function (): void {
    $user = new GenericUser([
        'id' => 1,
        'name' => '<script>alert("name")</script>',
        'email' => '<img src=x onerror=alert(1)>',
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk()->assertSee('Your account')
        ->assertSee($user->name)->assertDontSee($user->name, false)
        ->assertSee($user->email)->assertDontSee($user->email, false);
    expect($response['user'])->toBe($user);
    expect($response['metrics']['orders'])->toBe(0);
});

test('unavailable business tables show a retryable error instead of misleading zero totals', function (string $table): void {
    Schema::rename($table, 'unavailable_'.$table);
    Exceptions::fake();

    try {
        $response = $this->get(route('dashboard'));

        $response->assertServiceUnavailable()->assertSee('Unable to load dashboard')
            ->assertSee('Dashboard totals could not be loaded. Please try again.')
            ->assertSee('href="'.e(route('dashboard')).'"', false)
            ->assertSee('Try again')->assertDontSee('Total clients')->assertDontSee('No orders yet.')
            ->assertDontSee('SQLSTATE')->assertDontSee('no such table');
        Exceptions::assertReported(QueryException::class);
    } finally {
        Schema::rename('unavailable_'.$table, $table);
    }
})->with(['customers', 'orders']);

test('dashboard and orders navigation identify the current page and preserve a separate home route', function (): void {
    $dashboard = $this->get(route('dashboard'));
    $orders = $this->get(route('orders.index'));

    $dashboard->assertOk()->assertSee('href="'.e(route('home')).'"', false)
        ->assertSee('href="'.e(route('orders.create')).'"', false);
    $orders->assertOk()->assertSee('href="'.e(route('dashboard')).'"', false);
    expect($dashboard->getContent())->toMatch('/href="[^"]*\/dashboard"\s+aria-current="page"/');
    expect($orders->getContent())->toMatch('/href="[^"]*\/orders"\s+aria-current="page"/');
    expect(substr_count($dashboard->getContent(), 'aria-current="page"'))->toBe(1);
    preg_match('/<nav aria-label="Main navigation"[^>]*>(.*?)<\/nav>/s', $orders->getContent(), $ordersNavigation);
    expect(substr_count($ordersNavigation[1], 'aria-current="page"'))->toBe(1);
    expect(route('home'))->not->toBe(route('dashboard'));
});
