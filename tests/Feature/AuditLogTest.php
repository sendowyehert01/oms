<?php

use App\AuditAction;
use App\AuditRecorder;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use App\UserRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('audit access is granted only to the admin role', function (UserRole $role, bool $allowed): void {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('view-audit-logs'))->toBe($allowed);
})->with([
    'admin' => [UserRole::Admin, true],
    'staff' => [UserRole::Staff, false],
]);

test('guests are redirected to login before reading audit activity', function (): void {
    $this->get(route('audit-logs.index'))->assertRedirect(route('login'));

    $this->assertDatabaseEmpty('audit_logs');
});

test('staff receive 403 and cannot see the audit tab', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('audit-logs.index'))->assertForbidden();
    $this->assertDatabaseEmpty('audit_logs');
    $this->get(route('dashboard'))->assertOk()->assertDontSee('Audit Logs');
});

test('admins can read uniform statements and timestamps in the reporting timezone', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $actor = User::factory()->create(['name' => 'Jem Morales']);
    $client = Customer::factory()->create(['company_name' => 'Sendo Galang']);
    $entry = app(AuditRecorder::class)->clientCreated($actor, $client);
    AuditLog::query()->whereKey($entry->id)->update(['created_at' => '2026-11-05 17:15:23']);

    $this->get(route('audit-logs.index'))->assertOk()
        ->assertSee('Audit Logs')->assertSee('Jem Morales creates a client "Sendo Galang".')
        ->assertSee('Create client')->assertSee('Nov 06, 2026')->assertSee('01:15:23 AM')
        ->assertSee('Asia/Manila')->assertSee('User activity recorded in OMS');
});

test('search user action and date filters combine to return only matching activity', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $jem = User::factory()->create(['name' => 'Jem Morales']);
    $other = User::factory()->create(['name' => 'Marie Cruz']);
    $match = AuditLog::factory()->by($jem)->create([
        'action' => AuditAction::ClientCreated, 'statement' => 'Jem Morales creates a client "Sendo Galang".', 'created_at' => '2026-11-06 01:00:00',
    ]);
    AuditLog::factory()->by($other)->create(['action' => AuditAction::ClientCreated, 'statement' => 'Marie Cruz creates a client "Sendo Galang".', 'created_at' => '2026-11-06 01:00:00']);
    AuditLog::factory()->by($jem)->create(['action' => AuditAction::OrderViewed, 'statement' => 'Jem Morales views an order for Sendo Galang.', 'created_at' => '2026-11-06 01:00:00']);
    AuditLog::factory()->by($jem)->create(['action' => AuditAction::ClientCreated, 'statement' => 'Jem Morales creates a client "Sendo Galang".', 'created_at' => '2026-11-04 01:00:00']);
    AuditLog::factory()->by($jem)->create(['action' => AuditAction::ClientCreated, 'statement' => 'Jem Morales creates a client "Another Client".', 'created_at' => '2026-11-06 01:00:00']);

    $this->get(route('audit-logs.index', [
        'search' => 'sendo', 'actor_id' => $jem->id, 'action' => 'client_created', 'date_from' => '2026-11-06', 'date_to' => '2026-11-06',
    ]))->assertOk()->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 1 && $logs->first()->id === $match->id);
});

test('date filters include the entire local day and exclude adjacent days', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $actor = User::factory()->create();
    AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-05 15:59:59']);
    $first = AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-05 16:00:00']);
    $last = AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-06 15:59:59']);
    AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-06 16:00:00']);

    $this->get(route('audit-logs.index', ['date_from' => '2026-11-06', 'date_to' => '2026-11-06']))
        ->assertOk()->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->getCollection()->pluck('id')->all() === [$last->id, $first->id]);
});

test('a single date boundary is valid without requiring the other boundary', function (array $filters): void {
    $this->actingAs(User::factory()->admin()->create());
    $actor = User::factory()->create();
    AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-04 16:00:00']);
    AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-05 16:00:00']);
    AuditLog::factory()->by($actor)->create(['created_at' => '2026-11-06 16:00:00']);

    $this->get(route('audit-logs.index', $filters))->assertOk()
        ->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 2);
})->with([
    'from only' => [['date_from' => '2026-11-06']],
    'to only' => [['date_to' => '2026-11-06']],
]);

test('invalid filters return 422 without creating a success entry', function (array $filters, string $field): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->getJson(route('audit-logs.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseEmpty('audit_logs');
})->with([
    'array search' => [['search' => ['unsafe']], 'search'],
    'unknown action' => [['action' => 'delete_all'], 'action'],
    'invalid user' => [['actor_id' => -1], 'actor_id'],
    'invalid page' => [['page' => 0], 'page'],
    'invalid date' => [['date_from' => '2026-02-30'], 'date_from'],
    'reversed dates' => [['date_from' => '2026-11-07', 'date_to' => '2026-11-06'], 'date_to'],
]);

test('reversed dates explain how to correct the filter', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $this->getJson(route('audit-logs.index', ['date_from' => '2026-11-07', 'date_to' => '2026-11-06']))
        ->assertUnprocessable()->assertJsonPath('errors.date_to.0', 'The end date must be on or after the start date.');
});

test('pagination keeps filters and sorts simultaneous entries by newest id', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $actor = User::factory()->create(['name' => 'Jem Morales']);
    $entries = AuditLog::factory()->by($actor)->count(30)->create(['created_at' => '2026-11-06 01:00:00']);

    $this->get(route('audit-logs.index', ['search' => 'Jem', 'action' => 'tab_switched']))->assertOk()
        ->assertSee('Audit entries pagination')->assertSee('search=Jem', false)->assertSee('action=tab_switched', false)
        ->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => count($logs->items()) === 25 && $logs->first()->id === $entries->last()->id);
    $this->get(route('audit-logs.index', ['search' => 'Jem', 'action' => 'tab_switched', 'page' => 2]))->assertOk()
        ->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => count($logs->items()) === 5 && $logs->last()->id === $entries->first()->id);
    $this->assertDatabaseCount('audit_logs', 30);
});

test('activity text is escaped and search input cannot broaden the SQL query', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $actor = User::factory()->create(['name' => '<b>Jem Morales</b>']);
    $client = Customer::factory()->create(['company_name' => '<script>alert(1)</script>']);
    app(AuditRecorder::class)->clientCreated($actor, $client);

    $this->get(route('audit-logs.index', ['search' => 'alert']))->assertOk()
        ->assertSee('<b>Jem Morales</b>')->assertDontSee('<b>Jem Morales</b>', false)
        ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    $this->get(route('audit-logs.index', ['search' => "' OR 1=1 --"]))->assertOk()
        ->assertViewHas('logs', fn (LengthAwarePaginator $logs): bool => $logs->total() === 0);
});

test('saved audit entries reject editing and deleting', function (string $operation): void {
    $entry = AuditLog::factory()->create();

    expect(fn () => $operation === 'update' ? $entry->update(['statement' => 'Changed']) : $entry->delete())->toThrow(LogicException::class);

    $this->assertDatabaseHas('audit_logs', ['id' => $entry->id, 'statement' => 'Example Staff switches to the "Orders" tab.']);
})->with(['update', 'delete']);

test('default database seeding does not fabricate historical audit activity', function (): void {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseEmpty('audit_logs');
});
