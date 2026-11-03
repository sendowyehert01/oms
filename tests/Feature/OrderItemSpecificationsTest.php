<?php

use App\Models\Customer;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function startItemSpecificationOrder(TestCase $testCase): string
{
    $customer = Customer::factory()->create();
    $choice = $testCase->get(route('orders.create'))->assertOk();
    $key = $choice->viewData('submissionKey');
    $testCase->withCookie(config('session.cookie'), session()->getId());
    $testCase->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $customer->id])
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key]);

    return $key;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function itemSpecificationData(array $overrides = []): array
{
    return array_replace([
        'product_name' => 'Printed bags',
        'material_color' => 'LDPE / transparent',
        'size' => '8 x 12 inches / 0.03 mm',
        'print_design_colors' => 'Company logo / 2 colors',
        'sealing' => 'Bottom Seal',
        'puncher' => 'None',
        'customization_notes' => "Pack in bundles of 100.\nDeliver flat.",
        'quantity' => 2,
        'unit_price' => '1.25',
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function itemSpecificationOrderData(string $key, array $items): array
{
    return ['submission_key' => $key, 'order_date' => '2026-11-02', 'terms_of_payment' => 'Net 30', 'status' => 'Pending', 'items' => $items];
}

test('the item form contains packaging inputs and the exact sealing and puncher choices', function (): void {
    $key = startItemSpecificationOrder($this);
    $response = $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk();
    foreach (['Item name', 'Material/Color', 'Size and Thickness', 'Print Design and No. of Colors', 'Sealing', 'Puncher', 'Remarks'] as $label) {
        $response->assertSee($label);
    }
    foreach (array_unique([...OrderItem::SEALING_OPTIONS, ...OrderItem::PUNCHER_OPTIONS]) as $option) {
        $response->assertSee('value="'.$option.'"', false);
    }
    $response->assertSee('name="items[0][sealing_other]"', false)->assertSee('name="items[0][puncher_other]"', false)
        ->assertSee('items[__INDEX__][material_color]', false);
});

test('different item specifications save independently and appear under the correct item', function (): void {
    $key = startItemSpecificationOrder($this);
    $first = itemSpecificationData();
    $second = itemSpecificationData([
        'product_name' => 'Special bags', 'material_color' => 'PP / blue', 'size' => '10 x 15 inches / 0.05 mm',
        'print_design_colors' => 'Back panel / 3 colors', 'sealing' => 'Others', 'sealing_other' => 'Double heat seal',
        'puncher' => 'Others', 'puncher_other' => 'Oval handle', 'customization_notes' => 'Handle with care.', 'quantity' => 3,
    ]);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [2 => $first, 7 => $second]))
        ->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('order_items', 2);
    $this->assertDatabaseHas('order_items', array_replace($first, ['quantity' => '2 pcs', 'unit' => 'pcs']));
    $this->assertDatabaseHas('order_items', array_replace($second, ['quantity' => '3 pcs', 'unit' => 'pcs']));
    $item = OrderItem::query()->where('product_name', 'Printed bags')->firstOrFail();
    $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()
        ->assertSeeInOrder(['Printed bags', 'LDPE / transparent', 'Bottom Seal', 'Special bags', 'PP / blue', 'Double heat seal', 'Oval handle'])
        ->assertSee('Company logo / 2 colors')->assertSee('8 x 12 inches / 0.03 mm')->assertSee('Handle with care.')
        ->assertSee('6.25')->assertDontSee('<textarea', false);
});

test('orders can still be created without optional item specifications', function (): void {
    $key = startItemSpecificationOrder($this);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [['product_name' => 'Basic bags', 'quantity' => 1, 'unit_price' => '2.00']]))
        ->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $item = OrderItem::query()->firstOrFail();
    foreach (['material_color', 'size', 'print_design_colors', 'sealing', 'sealing_other', 'puncher', 'puncher_other', 'customization_notes'] as $field) {
        expect($item->getAttribute($field))->toBeNull();
    }
    $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()->assertSee('Basic bags')->assertSee('2.00');
});

test('every sealing choice can be saved', function (string $choice): void {
    $key = startItemSpecificationOrder($this);
    $data = itemSpecificationData(['sealing' => $choice, 'sealing_other' => $choice === 'Others' ? 'Custom seal' : null]);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [$data]))->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('order_items', ['sealing' => $choice, 'sealing_other' => $choice === 'Others' ? 'Custom seal' : null]);
})->with(OrderItem::SEALING_OPTIONS);

test('every puncher choice can be saved', function (string $choice): void {
    $key = startItemSpecificationOrder($this);
    $data = itemSpecificationData(['puncher' => $choice, 'puncher_other' => $choice === 'Others' ? 'Custom punch' : null]);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [$data]))->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('order_items', ['puncher' => $choice, 'puncher_other' => $choice === 'Others' ? 'Custom punch' : null]);
})->with(OrderItem::PUNCHER_OPTIONS);

test('custom text is required only for its matching choice in the same item', function (): void {
    $key = startItemSpecificationOrder($this);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [
        2 => itemSpecificationData(['sealing' => 'Others']),
        7 => itemSpecificationData(['puncher' => 'Others']),
    ]))->assertSessionHasErrors(['items.2.sealing_other' => 'Specify the sealing type when choosing Others.', 'items.7.puncher_other' => 'Specify the puncher type when choosing Others.']);
    expect(session('errors')->has('items.2.puncher_other'))->toBeFalse();
    expect(session('errors')->has('items.7.sealing_other'))->toBeFalse();
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
});

test('stale custom text is excluded when choosing a predefined option', function (): void {
    $key = startItemSpecificationOrder($this);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [itemSpecificationData([
        'sealing_other' => ['invalid stale input'], 'puncher_other' => 'Old puncher',
    ])]))->assertRedirectToRoute('orders.index')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('order_items', ['sealing' => 'Bottom Seal', 'sealing_other' => null, 'puncher' => 'None', 'puncher_other' => null]);
});

test('invalid specification fields are rejected without saving and can be displayed again', function (string $field, mixed $value, array $extra): void {
    $key = startItemSpecificationOrder($this);
    $this->post(route('orders.store'), itemSpecificationOrderData($key, [itemSpecificationData([$field => $value, ...$extra])]))
        ->assertRedirectToRoute('orders.creation.details', ['draft' => $key])->assertSessionHasErrors('items.0.'.$field);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('Please correct the following');
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('order_items', 0);
})->with([
    'material array' => ['material_color', ['invalid'], []],
    'long material' => ['material_color', str_repeat('x', 256), []],
    'size array' => ['size', ['invalid'], []],
    'long size' => ['size', str_repeat('x', 101), []],
    'design array' => ['print_design_colors', ['invalid'], []],
    'long design' => ['print_design_colors', str_repeat('x', 256), []],
    'unknown sealing' => ['sealing', 'Unknown', []],
    'sealing array' => ['sealing', ['Bottom Seal'], []],
    'unknown puncher' => ['puncher', 'Bottom Seal', []],
    'puncher array' => ['puncher', ['None'], []],
    'blank other sealing' => ['sealing_other', '   ', ['sealing' => 'Others']],
    'other sealing array' => ['sealing_other', ['invalid'], ['sealing' => 'Others']],
    'long other sealing' => ['sealing_other', str_repeat('x', 256), ['sealing' => 'Others']],
    'blank other puncher' => ['puncher_other', null, ['puncher' => 'Others']],
    'other puncher array' => ['puncher_other', ['invalid'], ['puncher' => 'Others']],
    'long other puncher' => ['puncher_other', str_repeat('x', 256), ['puncher' => 'Others']],
    'remarks array' => ['customization_notes', ['invalid'], []],
    'long remarks' => ['customization_notes', str_repeat('x', 2001), []],
]);

test('specifications survive back navigation and validation errors with all item rows', function (): void {
    $key = startItemSpecificationOrder($this);
    $data = itemSpecificationOrderData($key, [itemSpecificationData(['sealing' => 'Others', 'sealing_other' => 'Custom seal']), itemSpecificationData(['product_name' => 'Second item'])]);
    $this->post(route('orders.creation.back'), $data)->assertRedirect();
    $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => Customer::query()->value('id')]);
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('value="Custom seal"', false)
        ->assertSee('value="LDPE / transparent"', false)->assertSee('value="8 x 12 inches / 0.03 mm"', false)->assertSee('Second item');
    $data['items'][0]['quantity'] = 0;
    $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee('value="Custom seal"', false)
        ->assertSee('value="Company logo / 2 colors"', false)->assertSee('Pack in bundles of 100.')->assertSee('Second item');
});

test('specification text is escaped in the form and the saved details view', function (): void {
    $key = startItemSpecificationOrder($this);
    $text = '<script>alert("specification")</script>';
    $data = itemSpecificationOrderData($key, [itemSpecificationData(['material_color' => $text, 'sealing' => 'Others', 'sealing_other' => $text, 'customization_notes' => $text])]);
    $data['items'][0]['quantity'] = 0;
    $this->post(route('orders.store'), $data)->assertSessionHasErrors('items.0.quantity');
    $this->get(route('orders.creation.details', ['draft' => $key]))->assertOk()->assertSee($text)->assertDontSee($text, false);
    $data['items'][0]['quantity'] = 1;
    $this->post(route('orders.store'), $data)->assertRedirectToRoute('orders.index');
    $this->get(route('orders.show', ['order' => OrderItem::query()->value('order_id')]))->assertOk()->assertSee($text)->assertDontSee($text, false);
});

test('the specification migration preserves legacy size and notes when reapplied', function (): void {
    $migration = require database_path('migrations/2026_11_02_074457_add_packaging_specifications_to_order_items_table.php');
    $migration->down();
    expect(Schema::hasColumn('order_items', 'material_color'))->toBeFalse();
    expect(Schema::hasColumns('order_items', ['size', 'customization_notes']))->toBeTrue();
    $item = OrderItem::factory()->create(['size' => 'Legacy size', 'customization_notes' => 'Legacy notes']);
    $migration->up();
    $item->refresh();
    expect($item->size)->toBe('Legacy size');
    expect($item->customization_notes)->toBe('Legacy notes');
    expect($item->material_color)->toBeNull();
    expect($item->sealing_other)->toBeNull();
    $this->get(route('orders.show', ['order' => $item->order_id]))->assertOk()->assertSee('Legacy size')->assertSee('Legacy notes');
});

test('the item factory can create a persisted specification example', function (): void {
    $item = OrderItem::factory()->withSpecifications()->create();
    $this->assertDatabaseHas('order_items', ['id' => $item->id, 'material_color' => 'LDPE / transparent', 'sealing' => 'Bottom Seal', 'puncher' => 'None']);
});
