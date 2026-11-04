<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(new DateTimeImmutable('2026-11-04 08:00:00 UTC'));
    config(['payment-receipts.storage' => 'local']);
});

function designHtmlDocument(string $html): DOMDocument
{
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

    return $document;
}

test('redesigned pages keep unique control IDs and accessible form labels', function (string $page): void {
    $admin = User::factory()->admin()->create(['name' => 'Alex Morgan', 'email' => 'alex@example.test']);
    $client = Customer::factory()->for($admin, 'creator')->create(['company_name' => 'Harbor Retail Co.']);
    $order = Order::factory()->for($client)->for($admin, 'creator')->hasItems(2)->create(['status' => 'In production']);
    Payment::factory()->for($order)->create();
    $secondClient = Customer::factory()->for($admin, 'creator')->create(['company_name' => 'Northside Printing']);
    Order::factory()->for($secondClient)->for($admin, 'creator')->hasItems(1)->create(['status' => 'Ready for delivery']);
    Order::factory()->for($client)->for($admin, 'creator')->hasItems(1)->create(['status' => 'Completed']);
    $this->actingAs($admin);

    $response = match ($page) {
        'dashboard' => $this->get(route('dashboard')),
        'orders' => $this->get(route('orders.index')),
        'order' => $this->get(route('orders.show', $order)),
        'payment' => $this->get(route('orders.payments.create', $order)),
        'staff' => $this->get(route('staff.index')),
        'password' => $this->get(route('account.password')),
        'unavailable' => $this->get(route('orders.show', ['order' => 99999])),
        default => null,
    };

    if ($page === 'login') {
        $this->app['auth']->forgetGuards();
        $response = $this->withSession(['_old_input' => ['password' => 'never-echo-a-password']])->get(route('login'));
        $response->assertDontSee('never-echo-a-password');
    } elseif (in_array($page, ['choice', 'customer', 'existing', 'details'], true)) {
        $response = $this->get(route('orders.create'))->assertOk();
        $key = $response['submissionKey'];
        $this->withCookie(config('session.cookie'), session()->getId());
        if ($page === 'customer' || $page === 'existing') {
            $response = $this->get(route('orders.creation.customer', ['type' => $page === 'existing' ? 'existing' : 'new', 'draft' => $key]));
        } elseif ($page === 'details') {
            $this->post(route('orders.creation.select-customer'), ['submission_key' => $key, 'customer_type' => 'existing', 'customer_id' => $client->id])->assertRedirect();
            $response = $this->get(route('orders.creation.details', ['draft' => $key]));
        }
    }

    $response->assertStatus($page === 'unavailable' ? 404 : 200);
    $html = $response->getContent();
    $document = designHtmlDocument($html);
    $xpath = new DOMXPath($document);
    $ids = [];
    foreach ($xpath->query('//*[@id]') as $element) {
        $ids[] = $element->getAttribute('id');
    }
    expect(count($ids))->toBe(count(array_unique($ids)));

    foreach ($xpath->query('//input[not(@type="hidden")] | //select | //textarea') as $control) {
        $id = $control->getAttribute('id');
        $labels = $xpath->query('ancestor::label', $control)->length;
        if ($id !== '') {
            $labels += $xpath->query('//label[@for="'.$id.'"]')->length;
        }
        expect($labels)->toBeGreaterThan(0);
    }
    foreach ($xpath->query('//button[@data-password-toggle]') as $toggle) {
        $input = $document->getElementById($toggle->getAttribute('aria-controls'));
        expect($input)->not->toBeNull();
        expect($input->getAttribute('type'))->toBe('password');
        expect($input->getAttribute('value'))->toBe('');
        expect($toggle->getAttribute('type'))->toBe('button');
    }

})->with(['login', 'dashboard', 'orders', 'order', 'choice', 'customer', 'existing', 'details', 'payment', 'staff', 'password', 'unavailable']);

test('receipt errors stay associated with the input alongside its help text', function (): void {
    $admin = User::factory()->admin()->create();
    $order = Order::factory()->hasItems(1)->create();
    $this->actingAs($admin);
    $response = $this->from(route('orders.payments.create', $order))->post(route('orders.payments.store', $order), [
        'submission_key' => (string) Str::uuid(), 'amount' => '25.00', 'payment_date' => '2026-11-04', 'payment_type' => 'down_payment',
        'receipt' => UploadedFile::fake()->create('invalid.txt', 1, 'text/plain'),
    ]);
    $this->withCookie(config('session.cookie'), session()->getId());
    $response->assertRedirect()->assertSessionHasErrors('receipt');
    $html = $this->get(route('orders.payments.create', $order))->assertOk()->getContent();
    $document = designHtmlDocument($html);
    $input = $document->getElementById('receipt');
    expect(explode(' ', $input->getAttribute('aria-describedby')))->toContain('receipt-help', 'receipt-error');
    expect($document->getElementById('receipt-help'))->not->toBeNull();
    expect($document->getElementById('receipt-error'))->not->toBeNull();
    $this->assertDatabaseCount('payments', 0);
});
