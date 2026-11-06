<?php

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('tab switches are recorded once while refreshes filters and forbidden visits do not add switches', function (): void {
    $actor = User::factory()->create(['name' => 'Jem Morales']);
    $this->actingAs($actor);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('orders.index'))->assertOk();
    $this->get(route('orders.index', ['page' => 2, 'search' => 'Plastic']))->assertOk();
    $this->get(route('production.index'))->assertOk();
    $this->get(route('production.index', ['view' => 'list']))->assertOk();
    $this->get(route('balances.index'))->assertOk();
    $this->get(route('staff.index'))->assertForbidden();
    $this->get(route('audit-logs.index'))->assertForbidden();
    $this->get(route('orders.index'))->assertOk();

    expect(AuditLog::query()->orderBy('id')->pluck('statement')->all())->toBe([
        'Jem Morales switches to the "Dashboard" tab.',
        'Jem Morales switches to the "Orders" tab.',
        'Jem Morales switches to the "Production" tab.',
        'Jem Morales switches to the "Balances" tab.',
        'Jem Morales switches to the "Orders" tab.',
    ]);
});

test('fragments JSON requests and prefetches do not count as tab visits', function (array $headers): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('orders.index'), $headers)->assertOk();

    $this->assertDatabaseEmpty('audit_logs');
})->with([
    'order fragment' => [['X-Orders-Fragment' => '1']],
    'JSON response' => [['Accept' => 'application/json']],
    'prefetch' => [['Sec-Purpose' => 'prefetch']],
]);

test('full order views are tracked but history pagination and missing or inaccessible orders are excluded', function (): void {
    $actor = User::factory()->create(['name' => 'Jem Morales']);
    $order = Order::factory()->hasItems(1)->create(['created_by' => $actor->id, 'order_date' => '2026-11-06']);
    $other = Order::factory()->hasItems(1)->create();
    $this->actingAs($actor);

    $this->get(route('orders.show', ['order' => $order, 'search' => 'Plastic', 'page' => 2]))->assertOk();
    $this->get(route('orders.show', ['order' => $order, 'history_page' => 2]))->assertOk();
    $this->get(route('orders.show', 999999))->assertNotFound();
    $this->get(route('orders.show', $other))->assertNotFound();

    $this->assertDatabaseCount('audit_logs', 2);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'order_viewed', 'order_id' => $order->id, 'statement' => 'Jem Morales views an order "ORD-2026-0001".']);
});

test('only successfully served receipts record a view and their contents are excluded', function (): void {
    config(['payment-receipts.local_disk' => 'local']);
    Storage::fake('local');
    $actor = User::factory()->create(['name' => 'Jem Morales']);
    $order = Order::factory()->create(['created_by' => $actor->id, 'order_date' => '2026-11-06']);
    $path = 'payment-receipts/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path, 'Private receipt contents');
    $payment = Payment::factory()->for($order)->create(['receipt_storage' => 'local', 'receipt_path' => $path, 'receipt_name' => 'receipt.pdf', 'receipt_mime_type' => 'application/pdf']);
    $missing = Payment::factory()->for($order)->create();
    $other = Payment::factory()->create();
    $this->actingAs($actor);

    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertOk()->assertDownload('receipt.pdf');
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $missing]))->assertNotFound();
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $other]))->assertNotFound();

    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'receipt_viewed', 'statement' => 'Jem Morales views a payment receipt for an order "ORD-2026-0001".']);
    expect(AuditLog::query()->first()->toJson())->not->toContain('Private receipt contents', $path, 'receipt_sha256');
});

test('sign in and sign out are recorded once per successful session without credentials', function (): void {
    $actor = User::factory()->create(['name' => 'Jem Morales', 'email' => 'jem@example.test', 'remember_token' => 'private-remember-token']);

    $this->post(route('login.store'), ['email' => 'jem@example.test', 'password' => 'test-password-123'])->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
    $this->get(route('dashboard'))->assertOk();
    $this->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
    expect(AuditLog::query()->orderBy('id')->pluck('statement')->all())->toBe([
        'Jem Morales signs in to OMS.',
        'Jem Morales switches to the "Dashboard" tab.',
        'Jem Morales signs out of OMS.',
    ]);
    expect(AuditLog::query()->get()->toJson())->not->toContain('test-password-123', $actor->password, 'private-remember-token');
});

test('failed login and non web or anonymous authentication events create no success activity', function (): void {
    $actor = User::factory()->create(['email' => 'jem@example.test']);

    $this->postJson(route('login.store'), ['email' => 'jem@example.test', 'password' => 'wrong-password'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    Event::dispatch(new Login('api', $actor, false));
    Event::dispatch(new Logout('api', $actor));
    Event::dispatch(new Logout('web', null));

    $this->assertGuest();
    $this->assertDatabaseEmpty('audit_logs');
});

test('staff creation records the administrator and excludes rejected creations and passwords', function (): void {
    $admin = User::factory()->admin()->create(['name' => 'Jem Morales']);
    $this->actingAs($admin);
    $data = ['name' => 'Marie Cruz', 'email' => 'marie@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'];

    $this->post(route('staff.store'), $data)->assertRedirect(route('staff.index'));
    $this->postJson(route('staff.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->actingAs(User::query()->where('email', 'marie@example.test')->firstOrFail());
    $this->postJson(route('staff.store'), [...$data, 'email' => 'another@example.test'])->assertForbidden();

    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'staff_created', 'statement' => 'Jem Morales creates a staff account "Marie Cruz".']);
    expect(AuditLog::query()->first()->toJson())->not->toContain('test-password-123', 'password_confirmation');
});

test('staff creation rolls back when its audit entry cannot be saved', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $this->withoutExceptionHandling();
    AuditLog::creating(function (): never {
        throw new RuntimeException('Simulated audit failure');
    });

    expect(fn () => $this->post(route('staff.store'), [
        'name' => 'Marie Cruz', 'email' => 'marie@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
    ]))->toThrow(RuntimeException::class, 'Simulated audit failure');

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseEmpty('audit_logs');
});

test('successful password changes record only the action', function (): void {
    $actor = User::factory()->create(['name' => 'Jem Morales', 'remember_token' => 'old-remember-token']);
    $this->actingAs($actor);

    $this->from(route('account.password'))->put(route('user-password.update'), [
        'current_password' => 'test-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
    ])->assertRedirect(route('account.password'))->assertSessionHas('status', 'password-updated');

    expect(Hash::check('new-password-456', $actor->fresh()->password))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'statement' => 'Jem Morales changes their password.']);
    expect(AuditLog::query()->first()->toJson())->not->toContain('test-password-123', 'new-password-456', 'old-remember-token');
});

test('failed password validation leaves both the password and audit trail unchanged', function (): void {
    $actor = User::factory()->create();
    $originalPassword = $actor->password;
    $this->actingAs($actor);

    $this->putJson(route('user-password.update'), [
        'current_password' => 'wrong-password', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($actor->fresh()->password)->toBe($originalPassword);
    $this->assertDatabaseEmpty('audit_logs');
});

test('password and remember token changes roll back when auditing fails', function (): void {
    $actor = User::factory()->create(['remember_token' => 'old-remember-token']);
    $originalPassword = $actor->password;
    $this->actingAs($actor)->withoutExceptionHandling();
    AuditLog::creating(function (): never {
        throw new RuntimeException('Simulated audit failure');
    });

    expect(fn () => $this->put(route('user-password.update'), [
        'current_password' => 'test-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
    ]))->toThrow(RuntimeException::class, 'Simulated audit failure');

    expect($actor->fresh()->password)->toBe($originalPassword);
    expect($actor->fresh()->remember_token)->toBe('old-remember-token');
    $this->assertDatabaseEmpty('audit_logs');
});
