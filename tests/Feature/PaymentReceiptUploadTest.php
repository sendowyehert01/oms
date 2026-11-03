<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['payment-receipts.storage' => 'local']);
});

function receiptUploadOrder(): Order
{
    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create(['quantity' => '100 pcs', 'unit_price' => '10.00']);

    return $order;
}

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function receiptUploadData(array $overrides = []): array
{
    return array_replace([
        'submission_key' => (string) Str::uuid(), 'amount' => '100.00',
        'payment_date' => '2026-11-03', 'payment_type' => 'down_payment',
    ], $overrides);
}

function receiptPdf(string $name = 'receipt.pdf', string $body = 'Paid PHP 100'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$body."\n%%EOF");
}

test('payment form offers one optional receipt upload while local storage is selected', function (): void {
    $order = receiptUploadOrder();

    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()
        ->assertSee('enctype="multipart/form-data"', false)->assertSee('name="receipt"', false)
        ->assertSee('JPG, PNG, or PDF, up to 5 MB')->assertSee('select the file again');
});

test('a receipt saves privately on the laptop and downloads with its original content', function (): void {
    $order = receiptUploadOrder();
    $file = receiptPdf();
    $contents = $file->getContent();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $file]))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();

    $payment = Payment::query()->firstOrFail();
    $this->assertDatabaseHas('payments', [
        'id' => $payment->id, 'receipt_storage' => 'local', 'receipt_bucket' => null,
        'receipt_name' => 'receipt.pdf', 'receipt_mime_type' => 'application/pdf',
        'receipt_size' => strlen($contents), 'receipt_sha256' => hash('sha256', $contents),
    ]);
    Storage::disk('local')->assertExists($payment->receipt_path);
    expect(Storage::disk('local')->get($payment->receipt_path))->toBe($contents);
    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('Download receipt.pdf');
    $response = $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))
        ->assertOk()->assertDownload('receipt.pdf')->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe($contents);
    Http::assertNothingSent();
});

test('supported image receipts can be recorded', function (string $name, string $mime): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $file = UploadedFile::fake()->create($name, 1, $mime);

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $file]))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();

    $payment = Payment::query()->firstOrFail();
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'receipt_name' => $name, 'receipt_mime_type' => $mime]);
    Storage::disk('local')->assertExists($payment->receipt_path);
})->with(['jpg' => ['receipt.jpg', 'image/jpeg'], 'jpeg' => ['receipt.jpeg', 'image/jpeg'], 'png' => ['receipt.png', 'image/png']]);

test('a five megabyte receipt is accepted', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $contents = '%PDF-1.4'.str_repeat(' ', 5 * 1024 * 1024 - 8);
    $file = UploadedFile::fake()->createWithContent('receipt.pdf', $contents);

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $file]))
        ->assertRedirectToRoute('orders.show', ['order' => $order])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('payments', ['receipt_size' => 5242880]);
    Storage::disk('local')->assertCount('payment-receipts', 1);
});

test('a receipt larger than five megabytes is rejected before payment or file storage', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $file = UploadedFile::fake()->create('receipt.pdf', 5121, 'application/pdf');

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $file]))
        ->assertSessionHasErrors(['receipt' => 'The receipt must not exceed 5 MB.']);

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
});

test('disallowed or disguised file contents are rejected', function (string $name, string $contents): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $file = UploadedFile::fake()->createWithContent($name, $contents);
    $upload = new UploadedFile($file->getPathname(), $name, test: true);

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $upload]))
        ->assertSessionHasErrors(['receipt' => 'Choose a JPG, PNG, or PDF receipt.']);

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
})->with(['html as pdf' => ['receipt.pdf', '<html><script>alert(1)</script></html>'], 'svg' => ['receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>']]);

test('a valid PDF with a disallowed filename extension is rejected', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $file = receiptPdf('receipt.exe');
    $upload = new UploadedFile($file->getPathname(), 'receipt.exe', test: true);

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $upload]))
        ->assertSessionHasErrors(['receipt' => 'Choose a receipt with a JPG, PNG, or PDF file extension.']);

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
});

test('receipt input must be a single uploaded file', function (mixed $receipt): void {
    $order = receiptUploadOrder();
    Storage::fake('local');

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $receipt]))->assertSessionHasErrors('receipt');

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
})->with(['text' => 'https://example.test/file.pdf', 'array' => [['invalid']]]);

test('invalid payment values do not upload an otherwise valid receipt', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $data = receiptUploadData(['amount' => '1000.01', 'receipt' => receiptPdf()]);

    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('payments', 0);
    Storage::disk('local')->assertEmpty();
    $this->get(route('orders.payments.create', ['order' => $order]))->assertOk()->assertSee('value="1000.01"', false)->assertSee('select the file again');
});

test('replaying a payment with its receipt creates only one payment and one file', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $data = receiptUploadData(['receipt' => receiptPdf()]);

    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();
    unset($data['receipt']);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();

    $this->assertDatabaseCount('payments', 1);
    Storage::disk('local')->assertCount('payment-receipts', 1);
});

test('a replay cannot replace the original receipt', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $data = receiptUploadData(['receipt' => receiptPdf()]);
    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();
    $originalPayment = Payment::query()->firstOrFail();
    $data['receipt'] = receiptPdf('other.pdf', 'Different payment');

    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasErrors('submission_key');

    expect($originalPayment->refresh()->receipt_name)->toBe('receipt.pdf');
    $this->assertDatabaseCount('payments', 1);
    Storage::disk('local')->assertCount('payment-receipts', 1);
});

test('a failed payment transaction removes its uploaded receipt', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    Exceptions::fake();
    $event = 'eloquent.created: '.Payment::class;
    Event::listen($event, function (Payment $payment): void {
        throw new RuntimeException('Simulated payment failure after receipt upload.');
    });
    try {
        $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => receiptPdf()]))
            ->assertRedirectToRoute('orders.payments.create', ['order' => $order])->assertSessionHas('error', 'The payment could not be saved. Please try again.');

        $this->assertDatabaseCount('payments', 0);
        Storage::disk('local')->assertEmpty();
        Exceptions::assertReported(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
});

test('a local write failure does not save a payment', function (): void {
    $order = receiptUploadOrder();
    Exceptions::fake();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andReturnTrue();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => receiptPdf()]))
        ->assertSessionHas('error', 'The payment could not be saved. Please try again.');

    $this->assertDatabaseCount('payments', 0);
    Exceptions::assertReported(RuntimeException::class);
});

test('receipt filenames are escaped and posted storage metadata cannot override the chosen provider', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    $name = '<img onerror=alert(1)>.pdf';
    $data = receiptUploadData(['receipt' => receiptPdf($name), 'receipt_storage' => 'supabase', 'receipt_path' => '../../.env']);

    $this->post(route('orders.payments.store', ['order' => $order]), $data)->assertSessionHasNoErrors();

    $payment = Payment::query()->firstOrFail();
    expect($payment->receipt_storage)->toBe('local');
    expect($payment->receipt_path)->not->toBe('../../.env');
    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee($payment->receipt_name)->assertDontSee($name, false);
    Storage::disk('local')->assertExists($payment->receipt_path);
});

test('receipt downloads reject another order missing files and unsafe stored paths', function (): void {
    $order = receiptUploadOrder();
    $otherOrder = receiptUploadOrder();
    Storage::fake('local');
    $payment = Payment::factory()->for($order)->create(['receipt_storage' => 'local', 'receipt_path' => 'payment-receipts/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.pdf']);

    $this->get(route('orders.payments.receipt', ['order' => $otherOrder, 'payment' => $payment]))->assertNotFound();
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertNotFound();
    $payment->update(['receipt_path' => '../../.env']);
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertNotFound();

    Storage::disk('local')->assertEmpty();
});

test('existing payments without receipts remain downloadable only as payment history', function (): void {
    $order = receiptUploadOrder();
    $payment = Payment::factory()->for($order)->create();

    $this->get(route('orders.show', ['order' => $order]))->assertOk()->assertSee('No receipt');
    $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertNotFound();
});

test('a local receipt remains readable after selecting Supabase for future uploads', function (): void {
    $order = receiptUploadOrder();
    Storage::fake('local');
    Http::preventStrayRequests();
    $file = receiptPdf();
    $contents = $file->getContent();
    $this->post(route('orders.payments.store', ['order' => $order]), receiptUploadData(['receipt' => $file]))->assertSessionHasNoErrors();
    $payment = Payment::query()->firstOrFail();
    config(['payment-receipts.storage' => 'supabase']);

    $response = $this->get(route('orders.payments.receipt', ['order' => $order, 'payment' => $payment]))->assertOk();

    expect($response->streamedContent())->toBe($contents);
    Http::assertNothingSent();
});
