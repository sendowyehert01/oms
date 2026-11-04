<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\OrderEntries;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->staff = User::factory()->create();
    $this->otherStaff = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->client = Customer::factory()->for($this->staff, 'creator')->create(['company_name' => 'Own client']);
    $this->otherClient = Customer::factory()->for($this->otherStaff, 'creator')->create(['company_name' => 'Private client']);
    $this->legacyClient = Customer::factory()->create(['company_name' => 'Legacy client']);
    $this->order = Order::factory()->for($this->client)->for($this->staff, 'creator')->hasItems(1, ['quantity' => '10', 'unit_price' => '100.00'])->create();
    $this->otherOrder = Order::factory()->for($this->otherClient)->for($this->otherStaff, 'creator')->hasItems(1)->create();
    $this->legacyOrder = Order::factory()->for($this->legacyClient)->hasItems(1)->create();
    $this->actingAs($this->staff);
});

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function authorizationOrderData(array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'customer_name' => 'New staff client',
        'contact_person' => 'Alex', 'address' => 'Market Street', 'contact_number' => '09123456789',
        'order_date' => '2026-11-04', 'terms_of_payment' => 'Cash', 'status' => 'Pending',
        'items' => [['product_name' => 'Bag', 'quantity' => 10, 'unit_price' => '100.00']],
    ], $overrides);
}

test('staff lists and searches expose only orders belonging to their clients', function (): void {
    $adminOrder = Order::factory()->for($this->client)->for($this->admin, 'creator')->hasItems(1)->create();
    $response = $this->get(route('orders.index'));
    $response->assertOk()->assertSee('Own client')->assertDontSee('Private client')->assertDontSee('Legacy client')
        ->assertDontSee('data-delete-order', false)->assertDontSee('Sample orders');
    expect($response['orders']->pluck('id')->all())->toBe([$adminOrder->id, $this->order->id]);
    $this->get(route('orders.show', $adminOrder))->assertOk()->assertSee('Own client');
    $this->get(route('orders.payments.create', $adminOrder))->assertOk();
    $this->get(route('orders.index', ['search' => 'Private']), ['X-Orders-Fragment' => '1'])
        ->assertOk()->assertSee('No matching orders')->assertDontSee('Private client');
});

test('foreign and legacy orders cannot be read paid or deleted by staff', function (string $record): void {
    $order = $this->{$record};
    $payment = Payment::factory()->for($order)->create(['receipt_storage' => 'supabase', 'receipt_bucket' => 'payment-receipts', 'receipt_path' => 'payment-receipts/11111111-1111-4111-8111-111111111111.pdf', 'receipt_name' => 'private.pdf', 'receipt_mime_type' => 'application/pdf']);
    Http::preventStrayRequests();
    $this->get(route('orders.show', $order))->assertNotFound();
    $this->get(route('orders.payments.create', $order))->assertNotFound();
    $this->post(route('orders.payments.store', $order), [])->assertNotFound();
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertNotFound();
    $this->deleteJson(route('orders.destroy', $order), ['confirmed' => true])->assertNotFound();
    $this->assertDatabaseHas('orders', ['id' => $order->id]);
    $this->assertDatabaseCount('payments', 1);
    Http::assertNothingSent();
})->with(['otherOrder', 'legacyOrder']);

test('staff may record and download their client payment but may not delete the order', function (): void {
    Storage::fake('local');
    config(['payment-receipts.storage' => 'local', 'payment-receipts.local_disk' => 'local']);
    $this->get(route('orders.payments.create', $this->order))->assertOk();
    $this->post(route('orders.payments.store', $this->order), [
        'submission_key' => (string) Str::uuid(), 'amount' => '100.00', 'payment_date' => '2026-11-04', 'payment_type' => 'down_payment',
    ])->assertRedirectToRoute('orders.show', ['order' => $this->order])->assertSessionHasNoErrors();
    $payment = $this->order->payments()->firstOrFail();
    $path = 'payment-receipts/11111111-1111-4111-8111-111111111111.pdf';
    $payment->forceFill(['receipt_storage' => 'local', 'receipt_path' => $path, 'receipt_name' => 'own.pdf', 'receipt_mime_type' => 'application/pdf'])->save();
    Storage::disk('local')->put($path, 'receipt contents');
    $this->get(route('orders.payments.receipt', ['order' => $this->order, 'payment' => $payment]))->assertOk()->assertDownload('own.pdf');
    $this->deleteJson(route('orders.destroy', $this->order), ['confirmed' => true])->assertForbidden();
    $this->assertDatabaseHas('orders', ['id' => $this->order->id]);
});

test('staff cannot use sample routes or manage staff accounts', function (): void {
    $this->get(route('orders.samples.show', ['order' => 1]))->assertNotFound();
    $this->deleteJson(route('orders.samples.destroy', ['order' => 1]), ['confirmed' => true])->assertNotFound();
    $this->get(route('staff.index'))->assertForbidden();
    $this->post(route('staff.store'), [])->assertForbidden();
});

test('a visible order cannot be used to download another orders receipt', function (): void {
    $payment = Payment::factory()->for($this->otherOrder)->create(['receipt_path' => 'payment-receipts/11111111-1111-4111-8111-111111111111.pdf']);
    Http::preventStrayRequests();
    $this->get(route('orders.payments.receipt', ['order' => $this->order, 'payment' => $payment]))->assertNotFound();
    Http::assertNothingSent();
});

test('customer selection is scoped and forged client ids cannot save an order', function (string $record): void {
    $draft = $this->get(route('orders.create'))->assertOk()['submissionKey'];
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $draft]))
        ->assertOk()->assertSee('Own client')->assertDontSee('Private client')->assertDontSee('Legacy client');
    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $draft, 'search' => 'Private']))
        ->assertOk()->assertDontSee('Private client');
    $client = $this->{$record};
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $draft, 'customer_type' => 'existing', 'customer_id' => $client->id])
        ->assertSessionHasErrors('customer_id');
    $this->post(route('orders.store'), authorizationOrderData(['customer_id' => $client->id]))->assertSessionHasErrors('customer_id');
    $this->assertDatabaseCount('orders', 3);
})->with(['otherClient', 'legacyClient']);

test('restored drafts recheck client visibility before showing details', function (): void {
    $draft = $this->get(route('orders.create'))['submissionKey'];
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $draft, 'customer_type' => 'existing', 'customer_id' => $this->client->id])->assertRedirect();
    $this->client->creator()->associate($this->otherStaff)->save();
    $this->get(route('orders.creation.details', ['draft' => $draft]))->assertRedirectToRoute('orders.creation.customer', ['type' => 'existing', 'draft' => $draft]);
    $this->post(route('orders.store'), authorizationOrderData(['submission_key' => $draft]))->assertSessionHasErrors('customer_id');
    $this->assertDatabaseCount('orders', 3);
});

test('authenticated creators are stored and submitted creator attributes are ignored', function (): void {
    $this->post(route('orders.store'), authorizationOrderData(['created_by' => $this->admin->id]))->assertRedirect();
    $newOrder = Order::query()->latest('id')->firstOrFail();
    expect($newOrder->created_by)->toBe($this->staff->id);
    expect($newOrder->customer->created_by)->toBe($this->staff->id);
    $this->actingAs($this->admin)->post(route('orders.store'), authorizationOrderData(['customer_id' => $this->client->id]))->assertRedirect();
    expect(Order::query()->latest('id')->firstOrFail()->created_by)->toBe($this->admin->id);
    expect($this->client->refresh()->created_by)->toBe($this->staff->id);
});

test('duplicate submission keys are isolated to their creator even for a visible client', function (): void {
    $adminOrder = Order::factory()->for($this->client)->for($this->admin, 'creator')->create();
    $this->post(route('orders.store'), authorizationOrderData(['submission_key' => $adminOrder->submission_key, 'customer_id' => $this->client->id]))->assertNotFound();
    $this->post(route('orders.store'), authorizationOrderData(['submission_key' => $this->otherOrder->submission_key]))->assertNotFound();
    $this->assertDatabaseCount('orders', 4);
    $key = (string) Str::uuid();
    $data = authorizationOrderData(['submission_key' => $key]);
    $this->post(route('orders.store'), $data)->assertRedirect();
    unset($data['customer_name'], $data['contact_person'], $data['address'], $data['contact_number']);
    $order = Order::query()->where('submission_key', $key)->firstOrFail();
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.payments.create', ['order' => $order]);
    $this->assertDatabaseCount('orders', 5);
});

test('order creation service rechecks ownership even when invoked without request validation', function (): void {
    expect(fn () => app(OrderEntries::class)->create(authorizationOrderData(['customer_id' => $this->otherClient->id]), $this->staff))
        ->toThrow(ModelNotFoundException::class);
    $this->assertDatabaseCount('orders', 3);
});

test('admin can see all records create for legacy clients and delete unpaid orders', function (): void {
    $this->actingAs($this->admin);
    $response = $this->get(route('orders.index'))->assertOk()->assertSee('Private client')->assertSee('Legacy client')->assertSee('data-delete-order', false);
    expect($response['orders']->total())->toBe(3);
    $this->get(route('orders.show', $this->legacyOrder))->assertOk();
    $this->get(route('orders.payments.create', $this->otherOrder))->assertOk();
    $this->post(route('orders.store'), authorizationOrderData(['customer_id' => $this->legacyClient->id]))->assertRedirect();
    expect($this->legacyClient->refresh()->created_by)->toBeNull();
    $this->deleteJson(route('orders.destroy', $this->legacyOrder), ['confirmed' => true])->assertOk();
    $this->assertDatabaseMissing('orders', ['id' => $this->legacyOrder->id]);
});

test('policies permit staff ownership actions and reserve deletion to administrators', function (): void {
    foreach ([$this->staff, $this->admin] as $user) {
        foreach ([Customer::class, Order::class] as $model) {
            expect(Gate::forUser($user)->allows('viewAny', $model))->toBeTrue();
            expect(Gate::forUser($user)->allows('create', $model))->toBeTrue();
        }
    }
    foreach (['view', 'recordPayment'] as $ability) {
        expect(Gate::forUser($this->staff)->allows($ability, $this->order))->toBeTrue();
        expect(Gate::forUser($this->staff)->inspect($ability, $this->otherOrder)->status())->toBe(404);
        expect(Gate::forUser($this->staff)->inspect($ability, $this->legacyOrder)->status())->toBe(404);
        expect(Gate::forUser($this->admin)->allows($ability, $this->legacyOrder))->toBeTrue();
    }
    expect(Gate::forUser($this->staff)->allows('delete', $this->order))->toBeFalse();
    expect(Gate::forUser($this->admin)->allows('delete', $this->otherOrder))->toBeTrue();
    expect(Gate::forUser($this->staff)->allows('view', $this->client))->toBeTrue();
    expect(Gate::forUser($this->staff)->inspect('view', $this->legacyClient)->status())->toBe(404);
    expect(Gate::forUser($this->admin)->allows('view', $this->legacyClient))->toBeTrue();
});
