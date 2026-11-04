<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('staff can log in at the base URL with normalized email and reach the dashboard', function (): void {
    $user = User::factory()->create(['name' => 'Alex Staff', 'email' => 'alex@example.test']);
    $this->withSession(['previous' => 'session']);
    $previousSessionId = session()->getId();

    $response = $this->post(route('login.store'), ['email' => 'ALEX@EXAMPLE.TEST', 'password' => 'test-password-123']);

    $response->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($previousSessionId);
    $this->get(route('dashboard'))->assertOk()->assertSee('Alex Staff')->assertSee('alex@example.test');
    $this->get('/')->assertRedirect(route('dashboard'));
});

test('login returns to an intended order page', function (): void {
    User::factory()->create(['email' => 'alex@example.test']);
    $this->get(route('orders.create'))->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => 'alex@example.test', 'password' => 'test-password-123'])
        ->assertRedirect(route('orders.create'));
});

test('invalid credentials stay unauthenticated without revealing which credential failed', function (string $email, string $password): void {
    User::factory()->create(['email' => 'alex@example.test']);

    $this->from(route('login'))->post(route('login.store'), compact('email', 'password'))
        ->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->assertGuest();
})->with([
    'wrong password' => ['alex@example.test', 'wrong-password'],
    'unknown email' => ['unknown@example.test', 'test-password-123'],
]);

test('malformed login inputs return validation errors rather than server errors', function (): void {
    $this->postJson(route('login.store'), ['email' => ['alex@example.test'], 'password' => []])
        ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $this->assertGuest();
});

test('login attempts are limited to five per minute for the same email and address', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('login.store'), ['email' => 'alex@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $this->postJson(route('login.store'), ['email' => 'ALEX@example.test', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
    $this->assertGuest();
});

test('remember me is available only when selected', function (bool $remember): void {
    User::factory()->create(['email' => 'alex@example.test']);

    $response = $this->post(route('login.store'), ['email' => 'alex@example.test', 'password' => 'test-password-123', 'remember' => $remember]);

    $response->assertRedirect(route('dashboard'));
    if ($remember) {
        $response->assertCookie(Auth::guard()->getRecallerName());
    } else {
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }
})->with([false, true]);

test('logout clears staff authentication and order draft session data', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['orders.drafts' => ['private-customer-data'], '_token' => 'old-token']);
    $oldSessionId = session()->getId();

    $this->post(route('logout'))->assertRedirect('/')->assertSessionMissing('orders.drafts');

    $this->assertGuest();
    expect(session()->getId())->not->toBe($oldSessionId);
    expect(session()->token())->not->toBe('old-token');
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('all internal browser endpoints require authentication before accessing data', function (string $method, string $path): void {
    $this->call($method, $path)->assertRedirect(route('login'));
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
})->with([
    'dashboard' => ['GET', '/dashboard'],
    'orders' => ['GET', '/orders'],
    'order choice' => ['GET', '/orders/create'],
    'customer step' => ['GET', '/orders/create/customer/existing'],
    'customer selection' => ['POST', '/orders/create/customer'],
    'order details form' => ['GET', '/orders/create/details'],
    'order back' => ['POST', '/orders/create/back'],
    'order cancel' => ['POST', '/orders/create/cancel'],
    'save order' => ['POST', '/orders'],
    'payment form' => ['GET', '/orders/1/payments/create'],
    'save payment' => ['POST', '/orders/1/payments'],
    'receipt' => ['GET', '/orders/1/payments/1/receipt'],
    'sample order' => ['GET', '/orders/samples/1'],
    'saved order' => ['GET', '/orders/1'],
    'delete sample' => ['DELETE', '/orders/samples/1'],
    'delete saved' => ['DELETE', '/orders/1'],
    'staff list' => ['GET', '/staff'],
    'create staff' => ['POST', '/staff'],
    'password form' => ['GET', '/account/password'],
    'password update' => ['PUT', '/user/password'],
]);

test('unauthenticated receipt requests return 401 when expecting JSON', function (): void {
    $this->getJson('/orders/1/payments/1/receipt')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
});

test('public registration routes are unavailable and cannot create users', function (): void {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'Unknown', 'email' => 'unknown@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123', 'role' => 'admin'])->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});

test('login requires a CSRF token outside the test middleware bypass', function (): void {
    $this->app->instance('env', 'production');

    try {
        $this->post(route('login.store'), ['email' => 'alex@example.test', 'password' => 'test-password-123'])->assertStatus(419);
        $this->assertGuest();
    } finally {
        $this->app->instance('env', 'testing');
    }
});

test('staff can change only their own password with the correct current password', function (): void {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    $other = User::factory()->create();
    $otherPassword = $other->password;
    $this->actingAs($user);

    $this->from(route('account.password'))->put(route('user-password.update'), [
        'current_password' => 'test-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456', 'user_id' => $other->id,
    ])->assertRedirect(route('account.password'))->assertSessionHas('status', 'password-updated');

    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->remember_token)->not->toBe('old-remember-token');
    expect($other->fresh()->password)->toBe($otherPassword);
    $this->get(route('account.password'))->assertOk()->assertSee('Your password has been updated.');
});

test('password update errors are displayed in their named bag and leave the password unchanged', function (): void {
    config(['session.driver' => 'file']);
    $user = User::factory()->create();
    $originalPassword = $user->password;
    $this->actingAs($user);

    $response = $this->from(route('account.password'))->put(route('user-password.update'), [
        'current_password' => 'wrong-password', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
    ]);

    $this->withCookie(config('session.cookie'), session()->getId());
    $response->assertRedirect(route('account.password'))->assertSessionHasErrorsIn('updatePassword', ['current_password']);
    $this->get(route('account.password'))->assertOk()->assertSee('The provided password does not match your current password.')
        ->assertSee('aria-describedby="current_password-error"', false);
    expect($user->fresh()->password)->toBe($originalPassword);
});

test('password confirmation view is available to logged in staff', function (): void {
    $this->actingAs(User::factory()->create())->get(route('password.confirm'))->assertOk()->assertSee('Confirm your password');
});
