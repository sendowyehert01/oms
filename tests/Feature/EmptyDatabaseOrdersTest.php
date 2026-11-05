<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['session.driver' => 'database']);
});

test('admins see no orders when the application database is empty', function (string $environment): void {
    $admin = User::factory()->admin()->create();
    $this->app->instance('env', $environment);

    try {
        $response = $this->actingAs($admin)->get(route('orders.index'));

        $response->assertOk();
        expect($response['orders']->total())->toBe(0);
        $response->assertSee('No orders yet')
            ->assertSee('Showing 0 to 0 of 0 orders')
            ->assertDontSee('Sample orders')
            ->assertDontSee('Northstar Packaging')
            ->assertDontSee('/orders/samples/', false);
        $this->assertDatabaseEmpty('customers');
        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('order_items');
        $this->assertDatabaseEmpty('payments');
        $this->assertDatabaseEmpty('order_status_histories');
        $this->assertModelExists($admin);
    } finally {
        $this->app->instance('env', 'testing');
    }
})->with([
    'Render production' => ['production'],
    'local development' => ['local'],
    'automated tests' => ['testing'],
]);

test('clearing saved orders and customers leaves an empty list and preserves existing accounts', function (): void {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->create();
    $customer = Customer::factory()->create(['company_name' => 'Saved customer']);
    $order = Order::factory()->for($customer)->hasItems(1)->create();
    $this->actingAs($admin)->get(route('orders.index'))->assertOk()->assertSee('Saved customer');
    $this->withCookie(config('session.cookie'), session()->getId());
    $order->items()->delete();
    $order->delete();
    $customer->delete();

    $response = $this->get(route('orders.index'));

    $response->assertOk();
    expect($response['orders']->total())->toBe(0);
    $response->assertSee('No orders yet')
        ->assertSee('Showing 0 to 0 of 0 orders')
        ->assertDontSee('Saved customer')
        ->assertDontSee('Sample orders');
    $this->assertAuthenticatedAs($admin);
    $this->assertModelExists($admin);
    $this->assertModelExists($staff);
    $this->assertDatabaseEmpty('customers');
    $this->assertDatabaseEmpty('orders');
    $this->assertDatabaseEmpty('order_items');
});

test('an empty orders fragment contains no sample records or order actions', function (): void {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->withHeader('X-Orders-Fragment', '1')->get(route('orders.index'));

    $response->assertOk()
        ->assertSee('No orders yet')
        ->assertSee('Showing 0 to 0 of 0 orders')
        ->assertDontSee('data-view-order', false)
        ->assertDontSee('data-delete-order', false);
    $this->assertDatabaseEmpty('orders');
});
