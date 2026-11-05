<?php

use App\DataExports;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;

pest()->use(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['app.reporting_timezone' => 'Asia/Manila']);
    $this->admin = User::factory()->admin()->create(['name' => 'Export Administrator']);
    $this->actingAs($this->admin);
    $this->travelTo(new DateTimeImmutable('2026-11-05 08:00:00 UTC'));
});

/** @return array<string, DOMXPath> */
function exportSheets(TestResponse $response): array
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-test-export-'.bin2hex(random_bytes(8)).'.zip';
    file_put_contents($path, $response->streamedContent());

    try {
        $archive = new PharData($path);
        $document = new DOMDocument;
        expect($document->loadXML($archive['xl/workbook.xml']->getContent()))->toBeTrue();
        $workbook = new DOMXPath($document);
        $workbook->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = [];

        foreach ($workbook->query('//s:sheet') as $sheet) {
            $document = new DOMDocument;
            expect($document->loadXML($archive['xl/worksheets/sheet'.$sheet->getAttribute('sheetId').'.xml']->getContent()))->toBeTrue();
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheets[$sheet->getAttribute('name')] = $xpath;
        }

        return $sheets;
    } finally {
        unset($archive);
        Phar::unlinkArchive($path);
    }
}

/** @return list<array<string, string>> */
function exportRows(DOMXPath $sheet): array
{
    $columns = [];
    foreach ($sheet->query('//s:row[@r="7"]/s:c') as $cell) {
        $columns[preg_replace('/\d+/', '', $cell->getAttribute('r'))] = $sheet->evaluate('string(s:is/s:t)', $cell);
    }
    $rows = [];
    foreach ($sheet->query('//s:row[number(@r) > 7]') as $row) {
        $values = [];
        foreach ($sheet->query('s:c', $row) as $cell) {
            $column = $columns[preg_replace('/\d+/', '', $cell->getAttribute('r'))];
            $values[$column] = $sheet->evaluate($cell->getAttribute('t') === 'inlineStr' ? 'string(s:is/s:t)' : 'string(s:v)', $cell);
        }
        if (reset($values) !== 'No records match the selected date range.') {
            $rows[] = $values;
        }
    }

    return $rows;
}

/** @return array<string, string> */
function exportMetrics(DOMXPath $sheet): array
{
    return array_column(exportRows($sheet), 'Value', 'Metric');
}

test('administrators can find separate client and order exports in the navigation', function (): void {
    $response = $this->get('/exports');

    $response->assertOk()->assertSeeText('Data Exports')->assertSeeText('Export clients')->assertSeeText('Export orders')
        ->assertSee('clients-date-from', false)->assertSee('orders-date-to', false);
    $response->assertViewHas('defaults', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $html = new DOMXPath($document);
    expect($html->evaluate('count(//form[starts-with(@action, "'.route('exports.index').'")]/@data-busy-form)'))->toBe(0.0);
});

test('only administrators have export permission and staff cannot download or find the tab', function (): void {
    $staff = User::factory()->create();
    expect(Gate::forUser($this->admin)->allows('export-data'))->toBeTrue();
    expect(Gate::forUser($staff)->allows('export-data'))->toBeFalse();
    expect(fn () => app(DataExports::class)->workbook('orders', '2026-11-01', '2026-11-05', $staff))->toThrow(AuthorizationException::class);
    $this->actingAs($staff);

    $this->get('/exports')->assertForbidden();
    $this->post('/exports/clients', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05'])->assertForbidden();
    $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05'])->assertForbidden();
    $this->get(route('dashboard'))->assertOk()->assertDontSeeText('Data Exports');
});

test('guests must sign in before viewing or downloading exports', function (): void {
    auth()->logout();

    $this->get('/exports')->assertRedirect(route('login'));
    $this->post('/exports/clients')->assertRedirect(route('login'));
    $this->post('/exports/orders')->assertRedirect(route('login'));
});

test('unsupported export types cannot expose other database tables', function (): void {
    $this->post('/exports/users', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05'])->assertNotFound();
});

test('client exports include both reporting-date boundaries and preserve contact details', function (): void {
    Customer::factory()->for($this->admin, 'creator')->count(4)->sequence(
        ['company_name' => 'Before range', 'created_at' => '2026-10-31 15:59:59'],
        ['company_name' => 'Start boundary', 'created_at' => '2026-10-31 16:00:00'],
        ['company_name' => 'End boundary', 'created_at' => '2026-11-05 15:59:59'],
        ['company_name' => 'After range', 'created_at' => '2026-11-05 16:00:00'],
    )->create();

    $response = $this->post('/exports/clients', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertOk()->assertDownload('OMS-Clients-2026-11-01-to-2026-11-05.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $sheets = exportSheets($response);
    expect(array_keys($sheets))->toBe(['Overview', 'Clients']);
    $rows = exportRows($sheets['Clients']);
    expect(array_column($rows, 'Company name'))->toBe(['Start boundary', 'End boundary']);
    expect($rows[0])->toMatchArray([
        'Contact person' => 'Alex Cruz', 'Contact number' => '09123456789', 'Address' => '123 Market Street',
        'Created at' => '46327', 'Created by' => 'Export Administrator',
    ]);
    expect((float) $rows[1]['Created at'])->toBeGreaterThan(46331.99)->toBeLessThan(46332.0);
    expect(exportMetrics($sheets['Overview'])['Clients exported'])->toBe('2');
});

test('orders use their order date and include clients created outside the range', function (): void {
    $client = Customer::factory()->create(['company_name' => 'Established client', 'created_at' => '2020-01-01 00:00:00']);
    $orders = Order::factory()->for($client)->count(4)->sequence(
        ['order_date' => '2026-10-31', 'created_at' => '2026-11-03 00:00:00'],
        ['order_date' => '2026-11-01', 'created_at' => '2026-10-01 00:00:00'],
        ['order_date' => '2026-11-05', 'created_at' => '2026-12-01 00:00:00'],
        ['order_date' => '2026-11-06', 'created_at' => '2026-11-03 00:00:00'],
    )->create();
    Customer::factory()->create(['company_name' => 'Unrelated client']);

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertOk()->assertDownload('OMS-Orders-2026-11-01-to-2026-11-05.xlsx');
    $sheets = exportSheets($response);
    expect(array_keys($sheets))->toBe(['Overview', 'Orders', 'Clients', 'Order Items', 'Payments']);
    expect(array_column(exportRows($sheets['Orders']), 'Order ID'))->toBe([(string) $orders[1]->id, (string) $orders[2]->id]);
    expect(array_column(exportRows($sheets['Clients']), 'Company name'))->toBe(['Established client']);
    expect(exportMetrics($sheets['Overview']))->toMatchArray(['Orders exported' => '2', 'Clients represented' => '1']);
});

test('order exports include details and all payments without duplicating financial totals', function (): void {
    $client = Customer::factory()->for($this->admin, 'creator')->create(['company_name' => 'Acme Packaging']);
    $order = Order::factory()->for($client)->for($this->admin, 'creator')->create([
        'order_date' => '2026-11-03', 'payment_due_date' => '2026-11-04', 'terms_of_payment' => 'Net 30',
        'status' => 'Delivered', 'target_delivery_date' => '2026-11-04', 'delivery_date' => '2026-11-05',
        'delivery_recipient' => 'Alex Cruz', 'delivery_notes' => 'Complete delivery',
        'delivery_confirmed_by_name' => 'Export Administrator', 'delivery_confirmed_at' => '2026-11-05 07:00:00',
    ]);
    OrderItem::factory()->for($order)->withSpecifications()->readyForDelivery()->create();
    OrderItem::factory()->for($order)->create(['quantity' => '40 pcs', 'unit_price' => '1.25']);
    Payment::factory()->for($order)->create(['payment_date' => '2026-10-10', 'amount' => '50.00']);
    Payment::factory()->for($order)->create([
        'payment_date' => '2026-12-01', 'amount' => '25.00', 'payment_type' => 'full_payment',
        'receipt_name' => 'receipt.pdf', 'receipt_path' => 'private-receipt-path', 'receipt_bucket' => 'private-bucket',
    ]);

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertOk();
    $sheets = exportSheets($response);
    $rows = exportRows($sheets['Orders']);
    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'Company name' => 'Acme Packaging', 'Contact person' => 'Alex Cruz', 'Contact number' => '09123456789',
        'Address' => '123 Market Street', 'Order status' => 'Delivered', 'Payment status' => 'Partially paid',
        'Due status' => 'Overdue', 'Overdue' => 'Yes', 'Days overdue' => '1', 'Terms of payment' => 'Net 30',
        'Order total (PHP)' => '175.00', 'Total paid (PHP)' => '75.00', 'Balance (PHP)' => '100.00',
        'Items' => '2', 'Payments' => '2', 'Delivery recipient' => 'Alex Cruz', 'Delivery notes' => 'Complete delivery',
        'Delivery confirmed by' => 'Export Administrator', 'Created by' => 'Export Administrator',
    ]);
    expect(exportRows($sheets['Order Items']))->toHaveCount(2);
    expect(exportRows($sheets['Order Items'])[0])->toMatchArray([
        'Quantity' => '100', 'Unit' => 'pcs', 'Unit price (PHP)' => '1.25', 'Subtotal (PHP)' => '125.00',
        'Material / color' => 'LDPE / transparent', 'Size' => '8 x 12 inches / 0.03 mm',
        'Print / design / colors' => 'Company logo / 2 colors', 'Sealing' => 'Bottom Seal', 'Puncher' => 'None',
        'Stock availability' => 'Available', 'Production status' => 'Ready for delivery', 'Ready check required' => 'No',
    ]);
    expect(array_column(exportRows($sheets['Payments']), 'Amount (PHP)'))->toBe(['50.00', '25.00']);
    expect(exportRows($sheets['Payments'])[1]['Receipt filename'])->toBe('receipt.pdf');
    expect(exportMetrics($sheets['Overview']))->toMatchArray([
        'Order value (PHP)' => '175.00', 'Total paid (PHP)' => '75.00', 'Outstanding balance (PHP)' => '100.00',
        'Overdue balance (PHP)' => '100.00', 'Overdue orders' => '1',
    ]);
    foreach ($sheets as $sheet) {
        expect($sheet->document->textContent)->not->toContain('private-receipt-path', 'private-bucket', $this->admin->password);
    }
});

test('overdue and payment statuses use actual balances and the reporting day', function (?string $dueDate, string $paid, string $dueStatus, string $paymentStatus, string $balance, string $overdue): void {
    $this->travelTo(new DateTimeImmutable('2026-11-04 17:00:00 UTC'));
    $order = Order::factory()->hasItems(1)->create([
        'order_date' => '2026-11-01', 'payment_due_date' => $dueDate, 'due_date' => '2026-11-01',
        'terms_of_payment' => 'Net 30', 'target_delivery_date' => '2026-11-02',
    ]);
    if ($paid !== '0.00') {
        Payment::factory()->for($order)->create(['amount' => $paid, 'payment_type' => 'full_payment']);
    }

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']);

    $response->assertOk();
    $sheets = exportSheets($response);
    expect(exportRows($sheets['Orders'])[0])->toMatchArray([
        'Due status' => $dueStatus, 'Payment status' => $paymentStatus, 'Balance (PHP)' => $balance, 'Overdue' => $overdue,
    ]);
})->with([
    'past due unpaid' => ['2026-11-04', '0.00', 'Overdue', 'Unpaid', '125.00', 'Yes'],
    'due today' => ['2026-11-05', '0.00', 'Due today', 'Unpaid', '125.00', 'No'],
    'future due' => ['2026-11-06', '0.00', 'Not overdue', 'Unpaid', '125.00', 'No'],
    'no payment due date' => [null, '0.00', 'No due date', 'Unpaid', '125.00', 'No'],
    'settled past due' => ['2026-11-04', '125.00', 'Settled', 'Fully paid', '0.00', 'No'],
    'underpaid full-payment label' => ['2026-11-04', '50.00', 'Overdue', 'Partially paid', '75.00', 'Yes'],
    'legacy overpayment' => ['2026-11-04', '150.00', 'Settled', 'Fully paid', '0.00', 'No'],
]);

test('fractional item subtotals are rounded per line and legacy units are retained', function (): void {
    $order = Order::factory()->create(['order_date' => '2026-11-01']);
    OrderItem::factory()->for($order)->count(3)->sequence(
        ['quantity' => '2.35 kgs', 'unit_price' => '1.25'],
        ['quantity' => '0.01 kgs', 'unit_price' => '0.50'],
        ['quantity' => '3', 'unit' => 'pcs', 'unit_price' => '1.25'],
    )->create();

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']);

    $response->assertOk();
    $sheets = exportSheets($response);
    expect(array_column(exportRows($sheets['Order Items']), 'Subtotal (PHP)'))->toBe(['2.94', '0.01', '3.75']);
    expect(array_column(exportRows($sheets['Order Items']), 'Unit'))->toBe(['kgs', 'kgs', 'pcs']);
    expect(exportRows($sheets['Orders'])[0]['Order total (PHP)'])->toBe('6.70');
});

test('empty date ranges return complete usable workbooks with zero totals', function (string $type, array $detailSheets): void {
    $response = $this->post('/exports/'.$type, ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']);

    $response->assertOk()->assertDownload();
    $sheets = exportSheets($response);
    foreach ($detailSheets as $name) {
        expect(exportRows($sheets[$name]))->toBe([]);
        expect($sheets[$name]->document->textContent)->toContain('No records match the selected date range.');
    }
    expect(exportMetrics($sheets['Overview'])[ucfirst($type).' exported'])->toBe('0');
})->with([
    'clients' => ['clients', ['Clients']],
    'orders' => ['orders', ['Orders', 'Clients', 'Order Items', 'Payments']],
]);

test('invalid export dates return clear validation messages', function (array $payload, array $errors): void {
    $response = $this->post('/exports/clients', $payload);

    $response->assertRedirect(route('exports.index'))->assertSessionHasErrorsIn('clients', $errors);
})->with([
    'required dates' => [[], ['date_from' => 'Choose a Date from.', 'date_to' => 'Choose a Date to.']],
    'impossible start date' => [['date_from' => '2026-02-30', 'date_to' => '2026-11-05'], ['date_from' => 'Date from must be a valid date.']],
    'wrong end format' => [['date_from' => '2026-11-01', 'date_to' => '11/05/2026'], ['date_to' => 'Date to must be a valid date.']],
    'reversed range' => [['date_from' => '2026-11-05', 'date_to' => '2026-11-01'], ['date_to' => 'Date to must be on or after Date from.']],
]);

test('failed validation keeps dates and errors on the submitted form', function (): void {
    $response = $this->post('/exports/orders', ['date_from' => '2026-10-05', 'date_to' => '2026-10-01', 'export_type' => 'clients']);
    $this->withCookie(config('session.cookie'), session()->getId());

    $response->assertRedirect(route('exports.index'))->assertSessionHasErrorsIn('orders', ['date_to'])
        ->assertSessionHasInput('date_from', '2026-10-05');
    $page = $this->get('/exports');
    $page->assertOk()->assertSeeText('Date to must be on or after Date from.');
    $document = new DOMDocument;
    $document->loadHTML($page->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $html = new DOMXPath($document);
    expect($html->evaluate('string(//input[@id="orders-date-from"]/@value)'))->toBe('2026-10-05');
    expect($html->evaluate('string(//input[@id="clients-date-from"]/@value)'))->toBe('2026-11-01');
    expect($html->evaluate('string(//input[@id="orders-date-to"]/@aria-invalid)'))->toBe('true');
    expect($html->evaluate('count(//input[@id="clients-date-to"]/@aria-invalid)'))->toBe(0.0);
});

test('generation failures preserve filters report the exception and remove temporary files', function (): void {
    $order = Order::factory()->create(['order_date' => '2026-11-01']);
    OrderItem::factory()->for($order)->create(['quantity' => 'corrupt measurement']);
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*');
    Exceptions::fake();

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertRedirect(route('exports.index'))->assertSessionHasInput('date_from', '2026-11-01')
        ->assertSessionHasErrorsIn('orders', ['export' => 'The Excel report could not be generated. Please try again.']);
    Exceptions::assertReported(InvalidArgumentException::class);
    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*'))->toBe($before);
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->get('/exports')->assertOk()->assertSeeText('The Excel report could not be generated. Please try again.')
        ->assertDontSeeText('corrupt measurement');
});

test('exports keep all rows across database batches and clean files after download', function (): void {
    $client = Customer::factory()->for($this->admin, 'creator')->create();
    Order::factory()->for($client)->hasItems(1)->hasPayments(1)->count(205)->create(['order_date' => '2026-11-01']);
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*');
    DB::enableQueryLog();

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertOk();
    $sheets = exportSheets($response);
    expect(exportRows($sheets['Orders']))->toHaveCount(205);
    expect(exportRows($sheets['Order Items']))->toHaveCount(205);
    expect(exportRows($sheets['Payments']))->toHaveCount(205);
    expect(exportRows($sheets['Clients']))->toHaveCount(1);
    expect(exportMetrics($sheets['Overview']))->toMatchArray([
        'Order value (PHP)' => '25625.00', 'Total paid (PHP)' => '5125.00', 'Outstanding balance (PHP)' => '20500.00',
    ]);
    expect(count($queries))->toBeLessThan(20);
    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*'))->toBe($before);
});
