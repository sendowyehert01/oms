<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('only admins can view and create staff accounts', function (): void {
    $staff = User::factory()->create();
    $this->actingAs($staff);

    $this->get(route('staff.index'))->assertForbidden();
    $this->post(route('staff.store'), ['name' => 'New Staff', 'email' => 'new@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'])->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Staff accounts')->assertSee('Change password')->assertSee('Log out');
    $this->assertDatabaseCount('users', 1);
});

test('admin staff creation normalizes details hashes passwords and prevents privilege escalation', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->post(route('staff.store'), [
        'name' => '  Alex Staff  ', 'email' => ' ALEX@EXAMPLE.TEST ', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
        'role' => 'admin', 'id' => 999, 'email_verified_at' => now()->toDateTimeString(),
    ])->assertRedirect(route('staff.index'))->assertSessionHas('success');

    $staff = User::query()->where('email', 'alex@example.test')->firstOrFail();
    expect($staff->name)->toBe('Alex Staff');
    expect($staff->role)->toBe(UserRole::Staff);
    expect($staff->id)->not->toBe(999);
    expect($staff->email_verified_at)->toBeNull();
    expect(Hash::check('test-password-123', $staff->password))->toBeTrue();
    expect($staff->password)->not->toBe('test-password-123');
    $this->assertAuthenticatedAs($admin);
    $this->assertDatabaseCount('users', 2);
    $this->get(route('staff.index'))->assertOk()->assertSee('Alex Staff')->assertSee('alex@example.test')->assertSee('Staff');
    Auth::logout();
    $this->post(route('login.store'), ['email' => 'alex@example.test', 'password' => 'test-password-123'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($staff);
});

test('invalid staff account data is rejected without creating a user', function (array $overrides, string $error): void {
    $this->actingAs(User::factory()->admin()->create());
    $data = array_replace(['name' => 'Alex Staff', 'email' => 'alex@example.test', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123'], $overrides);

    $this->from(route('staff.index'))->post(route('staff.store'), $data)->assertRedirect(route('staff.index'))->assertSessionHasErrors($error);
    $this->assertDatabaseCount('users', 1);
})->with([
    'missing name' => [['name' => '   '], 'name'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
    'invalid email' => [['email' => 'invalid-email'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'long password' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
    'multibyte password too long to hash' => [['password' => str_repeat('é', 40), 'password_confirmation' => str_repeat('é', 40)], 'password'],
    'password mismatch' => [['password_confirmation' => 'different-password'], 'password'],
]);

test('duplicate emails are rejected after normalization with preserved safe form values', function (): void {
    $this->actingAs(User::factory()->admin()->create(['email' => 'alex@example.test']));

    $this->from(route('staff.index'))->post(route('staff.store'), [
        'name' => 'New Staff', 'email' => ' ALEX@EXAMPLE.TEST ', 'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
    ])->assertRedirect(route('staff.index'))->assertSessionHasErrors(['email' => 'The email has already been taken.']);
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get(route('staff.index'))->assertOk()->assertSee('The email has already been taken.')->assertSee('value="New Staff"', false)->assertDontSee('value="test-password-123"', false);
    $this->assertDatabaseCount('users', 1);
});

test('staff listings escape account details and paginate without exposing password data', function (): void {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Admin']));
    $unsafe = '<script>alert(1)</script>';
    User::factory()->create(['name' => $unsafe]);
    User::factory()->count(21)->create();

    $response = $this->get(route('staff.index'));

    $response->assertOk()->assertSee($unsafe)->assertDontSee($unsafe, false)->assertSee('Accounts (23)');
    expect($response['users']->total())->toBe(23);
    expect($response['users']->items())->toHaveCount(20);
    foreach ($response['users'] as $user) {
        expect($user->getAttributes())->not->toHaveKey('password');
    }
    $this->get(route('staff.index', ['page' => 2]))->assertOk()->assertViewHas('users', fn (LengthAwarePaginator $users): bool => count($users->items()) === 3);
});

test('admin account creation and logout require CSRF tokens', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $this->app->instance('env', 'production');

    try {
        $this->post(route('staff.store'), ['name' => 'New Staff', 'email' => 'new@example.test'])->assertStatus(419);
        $this->post(route('logout'))->assertStatus(419);
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseCount('users', 1);
    } finally {
        $this->app->instance('env', 'testing');
    }
});
