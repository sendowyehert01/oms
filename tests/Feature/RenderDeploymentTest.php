<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('the health endpoint boots without opening the configured database connection', function (): void {
    $defaultConnection = config('database.default');
    config(['database.default' => 'unavailable']);

    try {
        $response = $this->get('/up');
    } finally {
        config(['database.default' => $defaultConnection]);
    }

    $response->assertOk();
});

test('configured proxy headers generate HTTPS links behind the Render load balancer', function (): void {
    config(['trustedproxy.proxies' => '*']);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'untrusted.example'])
        ->get('http://oms.example.test/orders');

    $response->assertOk()
        ->assertSee('action="https://oms.example.test/orders"', false)
        ->assertSee('href="https://oms.example.test/orders/create"', false)
        ->assertDontSee('untrusted.example');
});

test('unconfigured proxies cannot change the scheme of local application links', function (): void {
    config(['trustedproxy.proxies' => null]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('http://oms.example.test/orders');

    $response->assertOk()->assertSee('action="http://oms.example.test/orders"', false);
});

test('database sessions retain validation errors and entered values between form requests', function (): void {
    config(['session.driver' => 'database']);
    $this->get(route('orders.create'))->assertOk();
    $this->withCookie(config('session.cookie'), session()->getId());

    $response = $this->from(route('orders.create'))->post(route('orders.store'), [
        'submission_key' => (string) Str::uuid(),
        'customer_name' => 'Retained test customer',
    ]);

    $response->assertRedirectToRoute('orders.create')->assertSessionHasErrors(['contact_person', 'items']);
    $this->get(route('orders.create'))->assertOk()->assertSee('value="Retained test customer"', false)
        ->assertSee('Add at least one item to the order.');
    $this->assertDatabaseCount('sessions', 1);
    $this->assertDatabaseCount('orders', 0);
});

test('database sessions retain sample deletion state after JSON requests', function (): void {
    config(['session.driver' => 'database']);
    $this->get(route('orders.index'))->assertOk();
    $this->withCookie(config('session.cookie'), session()->getId());

    $response = $this->deleteJson(route('orders.samples.destroy', ['order' => 1024]), ['confirmed' => true]);

    $response->assertOk()->assertSessionHas('orders.deleted_samples', [1024]);
    $this->get(route('orders.index'))->assertOk()
        ->assertDontSee('data-order-number="ORD-2026-1024"', false)
        ->assertSee('Showing 1 to 10 of 23 orders');
    $this->get(route('orders.samples.show', ['order' => 1024]))->assertNotFound();
    $this->assertDatabaseCount('sessions', 1);
});

test('the PostgreSQL connection accepts a Render internal database URL', function (): void {
    config(['database.connections.render_pgsql' => [
        ...config('database.connections.pgsql'),
        'url' => 'postgres://test_user:test_password@postgres.internal:5432/oms_testing',
    ]]);

    $connection = DB::connection('render_pgsql');

    expect($connection->getDriverName())->toBe('pgsql');
    expect($connection->getConfig('host'))->toBe('postgres.internal');
    expect($connection->getConfig('port'))->toBe(5432);
    expect($connection->getDatabaseName())->toBe('oms_testing');
    expect($connection->getConfig('username'))->toBe('test_user');
});
