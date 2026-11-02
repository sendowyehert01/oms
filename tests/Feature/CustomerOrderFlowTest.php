<?php

use App\Models\Customer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function startCustomerOrderFlow(TestCase $testCase): string
{
    $response = $testCase->get(route('orders.create'))->assertOk();
    $testCase->withCookie(config('session.cookie'), session()->getId());

    return $response->viewData('submissionKey');
}

/** @return array<string, string> */
function orderFlowCustomerData(): array
{
    return ['customer_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz', 'address' => '123 Market Street', 'contact_number' => '09123456789'];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderFlowDetailsData(string $submissionKey, array $overrides = []): array
{
    return array_replace([
        'submission_key' => $submissionKey,
        'order_date' => '2026-11-02',
        'due_date' => '2026-11-07',
        'status' => 'Pending',
        'items' => [
            ['product_name' => 'Plastic bags', 'quantity' => 2, 'unit_price' => '9.95'],
            ['product_name' => 'Zip bags', 'quantity' => 3, 'unit_price' => '3.10'],
        ],
    ], $overrides);
}

test('order creation starts with customer choice and no customer or order input fields', function (): void {
    $response = $this->get(route('orders.create'))->assertOk();
    $key = $response->viewData('submissionKey');

    $response->assertSee('Is this an existing customer?')->assertSee('Existing customer')->assertSee('New customer')
        ->assertSee(e(route('orders.creation.customer', ['type' => 'existing', 'draft' => $key])), false)
        ->assertSee(e(route('orders.creation.customer', ['type' => 'new', 'draft' => $key])), false)
        ->assertDontSee('name="order_date"', false)->assertDontSee('name="customer_name"', false);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
});

test('a new customer completes customer information before order entry and both save together', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->get(route('orders.creation.customer', ['type' => 'new', 'draft' => $key]))->assertOk()
        ->assertSee('Customer name')->assertSee('Contact person')->assertDontSee('name="order_date"', false);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()])
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);

    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Acme Packaging')
        ->assertSee('Alex Cruz')->assertSee('Order date')->assertSee('Back to customer step')
        ->assertDontSee('name="customer_name"', false)->assertDontSee('name="customer_id"', false);
    $this->post(route('orders.store'), orderFlowDetailsData($key))->assertRedirectToRoute('orders.index')
        ->assertSessionHas('success', 'Order ORD-2026-0001 created successfully.');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseHas('customers', ['company_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz']);
    $this->assertDatabaseHas('orders', ['customer_id' => 1]);
    $this->assertDatabaseCount('order_items', 2);
    $this->get(route('orders.index'))->assertOk()->assertSee('29.20')->assertSee('Acme Packaging')->assertSessionMissing('orders.creation_drafts.'.$key);
});

test('an existing customer is reused and posted customer changes cannot replace the selection', function (): void {
    $customer = Customer::factory()->create(['company_name' => 'Existing Packaging', 'contact_person' => 'Original Contact']);
    $otherCustomer = Customer::factory()->create();
    $original = $customer->refresh()->getAttributes();
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customer->id])
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Existing Packaging');

    $this->post(route('orders.store'), orderFlowDetailsData($key, ['customer_id' => $otherCustomer->id, ...orderFlowCustomerData()]))
        ->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customers', 2);
    $this->assertDatabaseHas('orders', ['customer_id' => $customer->id]);
    expect($customer->refresh()->getAttributes())->toBe($original);
});

test('new customer details cannot be replaced by a forged ID at order submission', function (): void {
    $existing = Customer::factory()->create();
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $this->post(route('orders.store'), orderFlowDetailsData($key, ['customer_id' => $existing->id, 'customer_name' => 'Forged']))
        ->assertRedirectToRoute('orders.index');
    $customer = Customer::query()->where('company_name', 'Acme Packaging')->firstOrFail();
    $this->assertDatabaseCount('customers', 2);
    $this->assertDatabaseHas('orders', ['customer_id' => $customer->id]);
});

test('customer search works by company contact or phone and identifies similar names', function (string $search): void {
    $customer = Customer::factory()->create(['company_name' => 'Acme Packaging', 'contact_person' => 'Alex Cruz', 'contact_number' => '09123456789']);
    Customer::factory()->create(['company_name' => 'Other Packaging', 'contact_person' => 'Other Contact', 'contact_number' => '09999999999']);
    $key = startCustomerOrderFlow($this);

    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $key, 'search' => $search]))
        ->assertOk()->assertSee('Acme Packaging')->assertSee('Alex Cruz')->assertSee('09123456789')
        ->assertSee('Customer #'.$customer->id)->assertDontSee('Other Packaging')->assertDontSee('name="order_date"', false);
})->with(['company' => '  aCmE  ', 'contact' => 'alex', 'phone' => '091234']);

test('customer pagination preserves the draft and a previous selection remains available after searching', function (): void {
    $selected = Customer::factory()->create(['company_name' => 'Z Selected Company']);
    Customer::factory()->count(12)->create(['company_name' => 'A Other Company']);
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $selected->id]);
    $this->post(route('orders.creation.back'), orderFlowDetailsData($key))->assertRedirectToRoute('orders.creation.customer', ['type' => 'existing', 'draft' => $key]);

    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $key, 'search' => 'Other']))
        ->assertOk()->assertSee('Z Selected Company')->assertSee('checked', false)
        ->assertSee('draft='.$key, false)->assertSee('search=Other', false);
});

test('an empty customer list or unmatched search offers the new customer path', function (bool $hasCustomer): void {
    if ($hasCustomer) {
        Customer::factory()->create(['company_name' => 'Acme Packaging']);
    }
    $key = startCustomerOrderFlow($this);
    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $key, 'search' => $hasCustomer ? 'No match' : '']))
        ->assertOk()->assertSee($hasCustomer ? 'No customers match your search.' : 'No customers have been saved yet.')
        ->assertSee('New customer')->assertSee('disabled', false);
})->with([false, true]);

test('existing customer selection rejects missing unknown or malformed IDs', function (mixed $customerId): void {
    Customer::factory()->create(['company_name' => 'Available Customer']);
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customerId])
        ->assertRedirectToRoute('orders.creation.customer', ['type' => 'existing', 'draft' => $key])->assertSessionHasErrors('customer_id');
    $this->get(route('orders.creation.customer', ['type' => 'existing', 'draft' => $key]))->assertOk()->assertSee('Available Customer')
        ->assertDontSee('Customers could not be loaded.');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertRedirectToRoute('orders.create', ['draft' => $key]);
    $this->assertDatabaseCount('orders', 0);
})->with([null, 9999, ['invalid'], 'invalid']);

test('new customer validation retains values and does not advance to order entry', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', 'customer_name' => 'Retained Customer'])
        ->assertRedirectToRoute('orders.creation.customer', ['type' => 'new', 'draft' => $key])
        ->assertSessionHasErrors(['contact_person', 'address', 'contact_number']);
    for ($reload = 0; $reload < 2; $reload++) {
        $this->get(route('orders.creation.customer', ['type' => 'new', 'draft' => $key]))->assertOk()
            ->assertSee('value="Retained Customer"', false)->assertDontSee('name="order_date"', false);
    }
    $this->assertDatabaseCount('customers', 0);
});

test('incomplete customer input survives going back to choice and returning', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.back'), ['submission_key' => $key, 'customer_type' => 'new', 'customer_name' => 'Unfinished Customer'])
        ->assertRedirectToRoute('orders.create', ['draft' => $key]);
    $this->get(route('orders.creation.customer', ['type' => 'new', 'draft' => $key]))->assertOk()->assertSee('value="Unfinished Customer"', false);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertRedirectToRoute('orders.create', ['draft' => $key]);
});

test('back navigation retains unfinished order items while allowing customer details to change', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $input = orderFlowDetailsData($key, ['due_date' => '', 'items' => [['product_name' => 'Unfinished Item', 'quantity' => 0, 'unit_price' => '1.20']]]);
    $this->post(route('orders.creation.back'), $input)->assertRedirectToRoute('orders.creation.customer', ['type' => 'new', 'draft' => $key]);
    $this->get(route('orders.creation.customer', ['type' => 'new', 'draft' => $key]))->assertOk()->assertSee('value="Acme Packaging"', false);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData(), 'customer_name' => 'Updated Customer']);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Updated Customer')
        ->assertSee('value="Unfinished Item"', false)->assertSee('value="0"', false)->assertSee('value="1.20"', false);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
});

test('order validation preserves all item rows and the draft on repeated page loads', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $data = orderFlowDetailsData($key);
    $data['items'][1]['quantity'] = 0;
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.creation.details', ['draft' => $key])->assertSessionHasErrors('items.1.quantity');
    for ($reload = 0; $reload < 2; $reload++) {
        $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Acme Packaging')
            ->assertSee('value="Plastic bags"', false)->assertSee('value="Zip bags"', false)->assertSee('value="'.$key.'"', false);
    }
    $this->assertDatabaseCount('customers', 0);
});

test('separate tabs retain their own customer and order progress', function (): void {
    $first = startCustomerOrderFlow($this);
    $second = startCustomerOrderFlow($this);
    foreach ([$first => 'First Customer', $second => 'Second Customer'] as $key => $name) {
        $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData(), 'customer_name' => $name]);
    }
    $this->post(route('orders.store'), orderFlowDetailsData($first, ['items' => []]))->assertSessionHasErrors('items');
    $this->get(route('orders.creation.details', ['draft' => $second]))->assertOk()->assertSee('Second Customer')->assertDontSee('First Customer');
    $this->post(route('orders.creation.cancel'), ['submission_key' => $first])->assertRedirectToRoute('orders.index');
    $this->get(route('orders.creation.details', ['draft' => $second]))->assertOk()->assertSee('Second Customer')->assertSessionMissing('orders.creation_drafts.'.$first);
});

test('canceling any step discards the draft and creates no customer or order', function (bool $hasCustomer): void {
    $key = startCustomerOrderFlow($this);
    if ($hasCustomer) {
        $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    }
    $this->post(route('orders.creation.cancel'), ['submission_key' => $key])->assertRedirectToRoute('orders.index');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertRedirectToRoute('orders.create');
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
})->with([false, true]);

test('a customer removed after selection cannot receive an order', function (): void {
    $customer = Customer::factory()->create();
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customer->id]);
    $customer->delete();
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertRedirectToRoute('orders.creation.customer', ['type' => 'existing', 'draft' => $key])
        ->assertSessionHasErrors('customer_id');
    $this->post(route('orders.store'), orderFlowDetailsData($key))->assertSessionHasErrors('customer_id');
    $this->assertDatabaseCount('orders', 0);
});

test('unknown draft keys cannot enter or change customer and order steps', function (): void {
    $key = (string) Str::uuid();
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertRedirectToRoute('orders.create');
    $this->get(route('orders.creation.customer', ['type' => 'new', 'draft' => $key]))->assertRedirectToRoute('orders.create');
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()])
        ->assertRedirectToRoute('orders.create');
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
});

test('repeating a completed submission returns its success without duplicating records', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $data = orderFlowDetailsData($key);
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index');
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('order_items', 2);
});

test('a failed save rolls back its records retains the draft and can be retried', function (): void {
    Exceptions::fake();
    $shouldFail = true;
    DB::listen(function (QueryExecuted $query) use (&$shouldFail): void {
        if ($shouldFail && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'order_items')) {
            throw new RuntimeException('Unable to save order items.');
        }
    });
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $this->post(route('orders.store'), orderFlowDetailsData($key))->assertRedirectToRoute('orders.creation.details', ['draft' => $key])
        ->assertSessionHas('error', 'The order could not be saved. Please try again.');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('The order could not be saved.')
        ->assertSee('value="Plastic bags"', false);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
    Exceptions::assertReported(RuntimeException::class);
    $shouldFail = false;
    $this->post(route('orders.store'), orderFlowDetailsData($key))->assertRedirectToRoute('orders.index');
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('orders', 1);
});

test('malformed retained order input does not break the details screen', function (): void {
    $key = startCustomerOrderFlow($this);
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'new', ...orderFlowCustomerData()]);
    $this->post(route('orders.store'), orderFlowDetailsData($key, ['order_date' => ['invalid'], 'items' => ['invalid']]))->assertSessionHasErrors(['order_date', 'items.0']);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Please correct the following');
});
