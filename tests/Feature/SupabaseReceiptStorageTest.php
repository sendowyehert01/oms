<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'payment-receipts.storage' => 'supabase',
        'payment-receipts.supabase.url' => 'https://receipts.example.test',
        'payment-receipts.supabase.secret_key' => 'sb_secret_test_receipts',
        'payment-receipts.supabase.bucket' => 'payment-receipts',
    ]);
    Storage::fake('local');
    Http::preventStrayRequests();
});

function supabaseReceiptOrder(): Order
{
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['quantity' => '100 pcs', 'unit_price' => '10.00']);

    return $order;
}

/** @return array<string, mixed> */
function supabaseReceiptData(): array
{
    return [
        'submission_key' => (string) Str::uuid(), 'amount' => '100.00',
        'payment_date' => '2026-11-03', 'payment_type' => 'down_payment',
        'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nPaid PHP 100\n%%EOF"),
    ];
}

test('Supabase receives raw receipt bytes with a server key and no local copy', function (): void {
    $order = supabaseReceiptOrder();
    $data = supabaseReceiptData();
    $contents = $data['receipt']->getContent();
    Http::fake(['https://receipts.example.test/storage/v1/object/payment-receipts/*' => Http::response(['Key' => 'receipt'], 200)]);

    $this->post(route('orders.payments.store', ['order' => $order]), $data)
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();

    $payment = Payment::query()->firstOrFail();
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'receipt_storage' => 'supabase', 'receipt_bucket' => 'payment-receipts',
        'receipt_name' => 'receipt.pdf', 'receipt_size' => strlen($contents), 'receipt_sha256' => hash('sha256', $contents),
    ]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://receipts.example.test/storage/v1/object/payment-receipts/'.$payment->receipt_path
        && $request->body() === $contents && $request->hasHeader('Content-Type', 'application/pdf')
        && $request->hasHeader('apikey', 'sb_secret_test_receipts') && ! $request->hasHeader('Authorization')
        && $request->hasHeader('x-upsert', 'false'));
    Http::assertSentCount(1);
    Storage::disk('local')->assertEmpty();
    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('Download receipt.pdf')
        ->assertDontSee('sb_secret_test_receipts')->assertDontSee('receipts.example.test');
});

test('private Supabase receipts download through OMS using the originally saved bucket', function (): void {
    $order = supabaseReceiptOrder();
    $path = 'payment-receipts/'.Str::uuid().'.pdf';
    $payment = Payment::factory()->for($order)->create([
        'receipt_storage' => 'supabase', 'receipt_bucket' => 'original-receipts', 'receipt_path' => $path,
        'receipt_name' => 'receipt.pdf', 'receipt_mime_type' => 'application/pdf',
    ]);
    config(['payment-receipts.storage' => 'local', 'payment-receipts.supabase.bucket' => 'new-receipts']);
    $url = 'https://receipts.example.test/storage/v1/object/authenticated/original-receipts/'.$path;
    Http::fake([$url => Http::response('%PDF-1.4 original receipt', 200)]);

    $response = $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))
        ->assertOk()->assertDownload('receipt.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('%PDF-1.4 original receipt');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $url
        && $request->hasHeader('apikey', 'sb_secret_test_receipts') && ! $request->hasHeader('Authorization'));
    Http::assertSentCount(1);
    Storage::disk('local')->assertEmpty();
});

test('incomplete remote configuration prevents receipt uploads while ordinary payments still work', function (string $key, mixed $value): void {
    $order = supabaseReceiptOrder();
    config([$key => $value]);
    Exceptions::fake();

    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()
        ->assertSee('Receipt uploads are currently unavailable.')->assertDontSee('name="receipt"', false)
        ->assertDontSee('sb_secret_test_receipts');
    $this->post(route('orders.payments.store', ['order' => $order]), supabaseReceiptData())
        ->assertSessionHas('error', 'The payment could not be saved. Please try again.');
    $this->assertDatabaseCount('payments', 0);
    $data = supabaseReceiptData();
    unset($data['receipt']);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('payments', 1);
    Storage::disk('local')->assertEmpty();
    Http::assertNothingSent();
})->with([
    'missing URL' => ['payment-receipts.supabase.url', null],
    'insecure URL' => ['payment-receipts.supabase.url', 'http://receipts.example.test'],
    'missing key' => ['payment-receipts.supabase.secret_key', null],
    'missing bucket' => ['payment-receipts.supabase.bucket', ''],
]);

test('failed remote uploads do not record payments and attempt orphan cleanup', function (int $status): void {
    $order = supabaseReceiptOrder();
    Exceptions::fake();
    Http::fake([
        'https://receipts.example.test/storage/v1/object/payment-receipts/*' => Http::response('Private upstream details', $status, ['Location' => 'https://untrusted.example.test']),
        'https://receipts.example.test/storage/v1/object/payment-receipts' => Http::response([], 200),
    ]);

    $this->post(route('orders.payments.store', ['order' => $order]), supabaseReceiptData())
        ->assertRedirectToRoute('orders.payments.create', ['order' => $order])
        ->assertSessionHas('error', 'The payment could not be saved. Please try again.');

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://receipts.example.test/storage/v1/object/payment-receipts'
        && count($request['prefixes']) === 1);
    Http::assertSentCount(2);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The Supabase receipt upload failed.');
})->with(['unauthorised' => 401, 'bucket error' => 400, 'redirect' => 302, 'server error' => 500]);

test('a connection failure during upload does not save a payment or create a local fallback', function (): void {
    $order = supabaseReceiptOrder();
    Exceptions::fake();
    Http::fake([
        'https://receipts.example.test/storage/v1/object/payment-receipts/*' => fn (): never => throw new ConnectionException('Simulated connection failure.'),
        'https://receipts.example.test/storage/v1/object/payment-receipts' => Http::response([], 200),
    ]);

    $this->post(route('orders.payments.store', ['order' => $order]), supabaseReceiptData())
        ->assertSessionHas('error', 'The payment could not be saved. Please try again.');

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Exceptions::assertReported(ConnectionException::class);
});

test('a database failure cleans remote files and a cleanup failure retains the original error', function (int $cleanupStatus): void {
    $order = supabaseReceiptOrder();
    Exceptions::fake();
    Http::fake([
        'https://receipts.example.test/storage/v1/object/payment-receipts/*' => Http::response([], 200),
        'https://receipts.example.test/storage/v1/object/payment-receipts' => Http::response([], $cleanupStatus),
    ]);
    $event = 'eloquent.created: '.Payment::class;
    Event::listen($event, function (Payment $payment): void {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        $this->post(route('orders.payments.store', ['order' => $order]), supabaseReceiptData())
            ->assertSessionHas('error', 'The payment could not be saved. Please try again.');

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('local')->assertEmpty();
        $upload = Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->first()[0];
        $uploadedPath = substr($upload->url(), strlen('https://receipts.example.test/storage/v1/object/payment-receipts/'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request['prefixes'] === [$uploadedPath] && $request->hasHeader('apikey', 'sb_secret_test_receipts'));
        Http::assertSentCount(2);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated database failure.');

        if ($cleanupStatus === 500) {
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Supabase receipt cleanup failed.');
        }
    } finally {
        Event::forget($event);
    }
})->with(['cleanup succeeds' => 200, 'cleanup fails' => 500]);

test('remote download failures show a retry page without exposing upstream details', function (int $status): void {
    $order = supabaseReceiptOrder();
    $payment = Payment::factory()->for($order)->create([
        'receipt_storage' => 'supabase', 'receipt_bucket' => 'payment-receipts',
        'receipt_path' => 'payment-receipts/'.Str::uuid().'.pdf', 'receipt_name' => 'receipt.pdf',
    ]);
    Exceptions::fake();
    Http::fake(['https://receipts.example.test/storage/v1/object/authenticated/payment-receipts/*' => Http::response('Private upstream details', $status)]);

    $response = $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]));

    if ($status === 404) {
        $response->assertNotFound();
    } else {
        $response->assertStatus(503)->assertSee('The receipt could not be downloaded. Please try again.')
            ->assertDontSee('Private upstream details')->assertDontSee('sb_secret_test_receipts');
    }

    Http::assertSentCount(1);
    Storage::disk('local')->assertEmpty();
})->with(['missing receipt' => 404, 'unauthorised' => 401, 'redirect' => 302, 'server error' => 500]);

test('invalid payments and duplicate submissions never create additional remote uploads', function (): void {
    $order = supabaseReceiptOrder();
    $data = supabaseReceiptData();
    $invalid = array_replace($data, ['amount' => '1000.01']);

    $this->post(route('orders.payments.store', ['order' => $order]), $invalid)->assertSessionHasErrors('amount');
    Http::assertNothingSent();
    $this->assertDatabaseCount('payments', 0);
    Http::fake(['https://receipts.example.test/storage/v1/object/payment-receipts/*' => Http::response([], 200)]);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();

    $this->assertDatabaseCount('payments', 1);
    Http::assertSentCount(1);
    Storage::disk('local')->assertEmpty();
});
