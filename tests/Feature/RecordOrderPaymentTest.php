<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\MoneyAmount;
use App\PaymentEntries;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
});

function paymentOrder(string $quantity = '100 pcs', string $price = '50.00'): Order
{
    $order = Order::factory()->create(['order_date' => '2026-11-01', 'terms_of_payment' => 'Net 30', 'status' => 'Pending']);
    OrderItem::factory()->for($order)->create(['quantity' => $quantity, 'unit_price' => $price]);

    return $order;
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function paymentData(array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'amount' => '1000.00',
        'payment_date' => '2026-11-03', 'payment_type' => 'down_payment',
    ], $overrides);
}

test('payment form shows the saved order and the amount date and payment choices', function (): void {
    $order = paymentOrder();
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()
        ->assertSee($order->customer->company_name)->assertSee('Net 30')->assertSee('5,000.00')
        ->assertSee('Amount (PHP)')->assertSee('name="amount"', false)->assertSee('name="payment_date"', false)
        ->assertSee('Downpayment')->assertSee('Fully Paid')->assertSee('4. Payment details')
        ->assertSee('No payments recorded yet.')->assertSee('Unpaid');
    $this->assertDatabaseCount('payments', 0);
    $this->get(route('orders.show', ['order' => $order]))->assertOk()
        ->assertSee('href="'.route('orders.payments.create', ['order' => $order]).'"', false);
});

test('multiple downpayments and final settlement update the order payment history and balance', function (): void {
    $order = paymentOrder();
    foreach (['1000.00', '500.00'] as $amount) {
        $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => $amount]))
            ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();
    }
    $response = $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('Partially paid')->assertSee('3,500.00');
    expect($response['paymentSummary']['total_paid_in_cents'])->toBe(150000);
    expect($response['paymentSummary']['balance_in_cents'])->toBe(350000);
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '3500.00', 'payment_type' => 'full_payment']))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHas('success', 'Payment recorded successfully.');
    $response = $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('Fully paid')->assertSee('Fully Paid')->assertSee('Downpayment');
    expect($response['paymentSummary']['balance_in_cents'])->toBe(0);
    expect($order->refresh()->status)->toBe('Pending');
    $this->assertDatabaseCount('payments', 3);
    $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'amount' => '3500.00', 'payment_type' => 'full_payment', 'payment_date' => '2026-11-03']);
    $this->deleteJson(route('orders.destroy', ['order' => $order]), ['confirmed' => true])->assertStatus(409);
});

test('a full payment can settle an order without a downpayment', function (): void {
    $order = paymentOrder();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '5000', 'payment_type' => 'full_payment']))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('payments', ['amount' => '5000.00']);
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()
        ->assertSee('No payment is due.')->assertDontSee('data-record-payment', false);
    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertDontSee('>Record payment<', false);
});

test('replaying a submitted full or partial payment creates only one payment', function (string $type, string $amount): void {
    $order = paymentOrder();
    $data = paymentData(['amount' => $amount, 'payment_type' => $type]);
    foreach ([1, 2] as $attempt) {
        $this->post(route('orders.payments.store', ['order' => $order]), $data)
            ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();
    }
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseHas('payments', ['submission_key' => $data['submission_key'], 'amount' => $amount]);
})->with(['partial' => ['down_payment', '1000.00'], 'full' => ['full_payment', '5000.00']]);

test('amounts with invalid formats or values are rejected without recording a payment', function (mixed $amount): void {
    $order = paymentOrder();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => $amount]))
        ->assertRedirectToRoute('orders.payments.create', ['order' => $order])->assertSessionHasErrors('amount');
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk();
    $this->assertDatabaseCount('payments', 0);
})->with([
    'zero' => [0], 'negative' => [-1], 'blank' => [''], 'null' => [null], 'letters' => ['abc'],
    'too precise' => ['1.005'], 'scientific notation' => ['1e2'], 'commas' => ['1,000'],
    'too many digits' => ['10000000000000000'], 'array' => [['1000']], 'boolean' => [true], 'signed' => ['+2'],
]);

test('required payment inputs must be provided', function (string $field): void {
    $order = paymentOrder();
    $data = paymentData();
    unset($data[$field]);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasErrors($field);
    $this->assertDatabaseCount('payments', 0);
})->with(['amount', 'payment_date', 'payment_type', 'submission_key']);

test('invalid payment dates types and form keys are rejected', function (string $field, mixed $value): void {
    $order = paymentOrder();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData([$field => $value]))->assertSessionHasErrors($field);
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk();
    $this->assertDatabaseCount('payments', 0);
})->with([
    ['payment_date', '2026-02-30'], ['payment_date', 'tomorrow'], ['payment_date', ['invalid']],
    ['payment_type', 'cash'], ['payment_type', ['full_payment']], ['submission_key', 'invalid'], ['submission_key', ['invalid']],
]);

test('payment amounts and types must agree with the remaining balance', function (string $amount, string $type, string $field): void {
    $order = paymentOrder();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => $amount, 'payment_type' => $type]))
        ->assertRedirectToRoute('orders.payments.create', ['order' => $order])->assertSessionHasErrors($field);
    $this->assertDatabaseCount('payments', 0);
})->with([
    'overpayment' => ['5000.01', 'down_payment', 'amount'],
    'incomplete full payment' => ['4999.99', 'full_payment', 'amount'],
    'settlement labelled downpayment' => ['5000.00', 'down_payment', 'payment_type'],
]);

test('a stale form is checked against payments recorded since it was opened', function (): void {
    $order = paymentOrder();
    $staleData = paymentData(['amount' => '2000.00']);
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '4000.00']))->assertSessionHasNoErrors();
    $this->post(route('orders.payments.store', ['order' => $order]), $staleData)->assertSessionHasErrors('amount');
    $this->assertDatabaseCount('payments', 1);
    expect(app(PaymentEntries::class)->summary($order)['balance_in_cents'])->toBe(100000);
});

test('settled and zero-value orders reject new payments', function (string $price): void {
    $order = paymentOrder('100 pcs', $price);
    if ($price !== '0.00') {
        Payment::factory()->for($order)->create(['amount' => '5000.00', 'payment_type' => 'full_payment']);
    }
    $beforeCount = Payment::query()->count();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData())->assertSessionHasErrors('amount');
    $this->assertDatabaseCount('payments', $beforeCount);
})->with(['settled' => '50.00', 'zero' => '0.00']);

test('a payment form key cannot be reused for another order or changed payment data', function (bool $anotherOrder): void {
    $order = paymentOrder();
    $data = paymentData();
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();
    $target = $anotherOrder ? paymentOrder() : $order;
    $data['amount'] = '2000.00';
    $this->post(route('orders.payments.store', ['order' => $target]), $data)->assertSessionHasErrors('submission_key');
    $this->assertDatabaseCount('payments', 1);
})->with([false, true]);

test('payment validation preserves the amount date type and form key for correction', function (): void {
    $order = paymentOrder();
    $data = paymentData(['amount' => '4000.00', 'payment_type' => 'full_payment']);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasErrors('amount');
    $response = $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()
        ->assertSee('value="4000.00"', false)->assertSee('value="2026-11-03"', false)->assertSee($data['submission_key']);
    expect($response->getContent())->toMatch('/value="full_payment"\s+selected/');
});

test('a failed payment insert rolls back and retains the form for a successful retry', function (): void {
    $order = paymentOrder();
    $data = paymentData();
    $shouldFail = true;
    $paymentCreated = 'eloquent.created: '.Payment::class;
    Exceptions::fake();
    Event::listen($paymentCreated, function (Payment $payment) use (&$shouldFail): void {
        if ($shouldFail) {
            $shouldFail = false;
            throw new RuntimeException('Simulated payment save failure.');
        }
    });
    try {
        $this->post(route('orders.payments.store', ['order' => $order]), $data)
            ->assertRedirectToRoute('orders.payments.create', ['order' => $order])->assertSessionHas('error', 'The payment could not be saved. Please try again.');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('orders', 1);
        $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()->assertSee($data['submission_key'])->assertSee('value="1000.00"', false);
        $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertRedirectToRoute('orders.show', ['order' => $order]);
        $this->assertDatabaseCount('payments', 1);
    } finally {
        Event::forget($paymentCreated);
    }
});

test('fractional measurements and decimal payments preserve exact remaining cents', function (): void {
    $order = paymentOrder('3 pcs', '0.10');
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '0.10']))->assertSessionHasNoErrors();
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '0.20', 'payment_type' => 'full_payment']))->assertSessionHasNoErrors();
    expect(app(PaymentEntries::class)->summary($order)['balance_in_cents'])->toBe(0);
    $weightOrder = paymentOrder('0.01 kgs', '0.50');
    $this->post(route('orders.payments.store', ['order' => $weightOrder]), paymentData(['amount' => '0.01', 'payment_type' => 'full_payment']))->assertSessionHasNoErrors();
    expect(app(PaymentEntries::class)->summary($weightOrder)['total_paid_in_cents'])->toBe(1);
});

test('a maximum-size order can settle a large balance without losing the final cent', function (): void {
    $order = Order::factory()->create();
    OrderItem::factory()->count(100)->for($order)->create(['quantity' => '100000000 pcs', 'unit_price' => '999999.99']);
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => '0.01']))->assertSessionHasNoErrors();
    $balance = app(PaymentEntries::class)->summary($order)['balance_in_cents'];
    expect($balance)->toBe(999_999_989_999_999_999);
    $this->post(route('orders.payments.store', ['order' => $order]), paymentData(['amount' => MoneyAmount::decimal($balance), 'payment_type' => 'full_payment']))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();
    $payment = Payment::query()->latest('id')->firstOrFail();
    expect($payment->amount)->toBe('9999999899999999.99');
    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('9,999,999,899,999,999.99')->assertSee('Fully paid');
});

test('the payment migration preserves legacy amounts and types', function (): void {
    $migration = require database_path('migrations/2026_11_03_095035_expand_payment_amounts_and_add_submission_keys.php');
    $migration->down();
    Schema::table('payments', function (Blueprint $table): void {
        $table->decimal('amount', total: 12, places: 2)->change();
    });
    $payment = Payment::factory()->for(paymentOrder())->create(['amount' => '25.50']);
    $migration->up();
    expect($payment->refresh()->amount)->toBe('25.50');
    expect($payment->payment_type)->toBe('down_payment');
    expect($payment->submission_key)->toBeNull();
    $this->get(route('orders.show', ['order' => $payment->order_id]))->assertOk()->assertSee('25.50')->assertSee('Partially paid');
});

test('unknown orders and sample URLs cannot receive recorded payments', function (): void {
    $this->get(route('orders.payments.create', ['order' => 999999]))->assertNotFound();
    $this->post(route('orders.payments.store', ['order' => 999999]), paymentData())->assertNotFound();
    $this->get('/orders/samples/1024/payments/create')->assertNotFound();
    $this->get(route('orders.samples.show', ['order' => 1024]))->assertOk()->assertDontSee('Record payment');
    $this->assertDatabaseCount('payments', 0);
});
