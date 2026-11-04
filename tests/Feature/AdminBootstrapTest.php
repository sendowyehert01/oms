<?php

use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;

pest()->use(DatabaseMigrations::class);

test('the first admin is created using private terminal prompts', function (): void {
    $this->artisan('oms:create-admin')
        ->expectsQuestion('Admin name', 'Owner Admin')
        ->expectsQuestion('Admin email', ' OWNER@EXAMPLE.TEST ')
        ->expectsQuestion('Admin password (12 to 72 characters)', 'private-password-123')
        ->expectsQuestion('Confirm admin password', 'private-password-123')
        ->expectsOutput('The first admin account was created. Sign in at the OMS base URL.')
        ->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->name)->toBe('Owner Admin');
    expect($admin->email)->toBe('owner@example.test');
    expect($admin->role)->toBe(UserRole::Admin);
    expect(Hash::check('private-password-123', $admin->password))->toBeTrue();
});

test('Render can bootstrap an admin from environment configuration without terminal input', function (): void {
    config(['auth.bootstrap_admin' => ['name' => 'Owner Admin', 'email' => 'owner@example.test', 'password' => 'private-password-123']]);

    $this->artisan('oms:create-admin', ['--from-env' => true, '--no-interaction' => true])
        ->expectsOutput('The first admin account was created. Sign in at the OMS base URL.')
        ->doesntExpectOutputToContain('private-password-123')->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->role)->toBe(UserRole::Admin);
    expect(Hash::check('private-password-123', $admin->password))->toBeTrue();
});

test('redeploying never creates an extra admin or overwrites an existing account', function (): void {
    $existing = User::factory()->admin()->create(['name' => 'Existing Admin', 'email' => 'owner@example.test']);
    $password = $existing->password;
    config(['auth.bootstrap_admin' => ['name' => 'Replacement', 'email' => 'another@example.test', 'password' => 'replacement-password-123']]);

    $this->artisan('oms:create-admin', ['--from-env' => true, '--no-interaction' => true])
        ->expectsOutput('An admin account already exists. No account was changed.')->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    expect($existing->fresh()->password)->toBe($password);
    expect($existing->fresh()->name)->toBe('Existing Admin');
    expect($existing->fresh()->email)->toBe('owner@example.test');
});

test('missing or invalid bootstrap settings fail without creating an insecure admin', function (array $input): void {
    config(['auth.bootstrap_admin' => $input]);

    $this->artisan('oms:create-admin', ['--from-env' => true, '--no-interaction' => true])->assertFailed();
    $this->assertDatabaseCount('users', 0);
})->with([
    'missing' => [['name' => null, 'email' => null, 'password' => null]],
    'invalid email' => [['name' => 'Owner', 'email' => 'invalid', 'password' => 'private-password-123']],
    'short password' => [['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'short']],
]);

test('bootstrap cannot promote an existing staff account through a duplicate email', function (): void {
    $staff = User::factory()->create(['email' => 'owner@example.test']);
    config(['auth.bootstrap_admin' => ['name' => 'Owner', 'email' => 'OWNER@example.test', 'password' => 'private-password-123']]);

    $this->artisan('oms:create-admin', ['--from-env' => true, '--no-interaction' => true])->assertFailed();

    expect($staff->fresh()->role)->toBe(UserRole::Staff);
    $this->assertDatabaseCount('users', 1);
});

test('noninteractive bootstrap without environment mode fails instead of using defaults', function (): void {
    $this->artisan('oms:create-admin', ['--no-interaction' => true])->assertFailed();
    $this->assertDatabaseCount('users', 0);
});
