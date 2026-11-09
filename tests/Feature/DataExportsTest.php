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
            if ($column === '') {
                continue;
            }
            $values[$column] = $sheet->evaluate($cell->getAttribute('t') === 'inlineStr' ? 'string(s:is/s:t)' : 'string(s:v)', $cell);
        }
        if (array_filter($values, fn (string $value): bool => $value !== '') !== []) {
            $rows[] = $values;
        }
    }

    return $rows;
}

test('administrators can find separate client and order exports in the navigation', function (): void {
    $response = $this->get('/exports');

    $response->assertOk()->assertSeeText('Reports')->assertDontSeeText('Data Exports')->assertSeeText('Audit Logs')->assertSeeText('Export clients')->assertSeeText('Export orders')
        ->assertSee('clients-date-from', false)->assertSee('orders-date-to', false);
    $response->assertViewHas('defaults', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);
    $document = new DOMDocument;
    $document->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $html = new DOMXPath($document);
    expect(trim($html->evaluate('string(//nav[@aria-label="Main navigation"]/a[@href="'.route('exports.index').'"])')))->toBe('Reports');
    expect($html->evaluate('count(//form[starts-with(@action, "'.route('exports.index').'")]/@data-busy-form)'))->toBe(0.0);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->id, 'action' => 'tab_switched', 'subject_name' => 'Reports',
        'statement' => 'Export Administrator switches to the "Reports" tab.',
    ]);
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
    $this->get(route('dashboard'))->assertOk()->assertDontSeeText('Reports');
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

    $response->assertOk()->assertDownload('list-of-clients-2026-11-01-to-2026-11-05.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $sheets = exportSheets($response);
    expect(array_keys($sheets))->toBe(['Clients']);
    $rows = exportRows($sheets['Clients']);
    expect(array_column($rows, 'Company Name'))->toBe(['Start boundary', 'End boundary']);
    expect($rows[0])->toBe([
        'Client ID' => '2', 'Company Name' => 'Start boundary', 'Contact Person' => 'Alex Cruz',
        'Contact Number' => '09123456789', 'Address' => '123 Market Street',
        'Created Date' => '46327', 'Created By' => 'Export Administrator',
    ]);
    expect($rows[1]['Created Date'])->toBe('46331');
    expect($sheets['Clients']->evaluate('string(//s:c[@r="D8"]/@t)'))->toBe('inlineStr');
    expect($sheets['Clients']->evaluate('string(//s:c[@r="F8"]/@s)'))->toBe('8');
    expect($sheets['Clients']->evaluate('count(//s:row[@r="7"]/s:c)'))->toBe(7.0);
    expect($sheets['Clients']->evaluate('count(//s:col)'))->toBe(7.0);
    expect($sheets['Clients']->evaluate('count(//s:c[starts-with(@r, "H") or starts-with(@r, "I") or starts-with(@r, "J")])'))->toBe(0.0);
    expect($sheets['Clients']->evaluate('string(//s:dimension/@ref)'))->toBe('A1:G9');
    expect($sheets['Clients']->evaluate('count(//s:row[@spans="1:7"])'))->toBe(9.0);
    expect($sheets['Clients']->evaluate('count(//s:mergeCell[contains(@ref, ":G")])'))->toBe(6.0);
    expect($sheets['Clients']->evaluate('string(//s:c[@r="G4"]/@s)'))->toBe('5');
});

test('orders use their order date and include clients created outside the range', function (): void {
    $client = Customer::factory()->create(['company_name' => 'Established client', 'created_at' => '2020-01-01 00:00:00']);
    Order::factory()->for($client)->count(4)->sequence(
        ['order_date' => '2026-10-31', 'created_at' => '2026-11-03 00:00:00'],
        ['order_date' => '2026-11-01', 'created_at' => '2026-10-01 00:00:00'],
        ['order_date' => '2026-11-05', 'created_at' => '2026-12-01 00:00:00'],
        ['order_date' => '2026-11-06', 'created_at' => '2026-11-03 00:00:00'],
    )->create();
    Customer::factory()->create(['company_name' => 'Unrelated client']);

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertOk()->assertDownload('list-of-orders-2026-11-01-to-2026-11-05.xlsx');
    $sheets = exportSheets($response);
    expect(array_keys($sheets))->toBe(['Orders']);
    $rows = exportRows($sheets['Orders']);
    expect(array_column($rows, 'Order ID'))->toBe(['ORD-2026-0002', 'ORD-2026-0003']);
    expect(array_column($rows, 'Order Date'))->toBe(['46327', '46331']);
    expect(array_column($rows, 'Customer Name'))->toBe(['Established client', 'Established client']);
});

test('order exports include details and all payments without duplicating financial totals', function (): void {
    $client = Customer::factory()->for($this->admin, 'creator')->create(['company_name' => 'Acme Packaging']);
    $order = Order::factory()->for($client)->for($this->admin, 'creator')->create([
        'order_date' => '2026-11-03', 'payment_due_date' => '2026-11-04', 'terms_of_payment' => 'Net 30',
        'status' => 'Delivered',
    ]);
    OrderItem::factory()->for($order)->withSpecifications()->readyForDelivery()->create(['product_name' => 'Transparent Bags']);
    OrderItem::factory()->for($order)->create([
        'product_name' => 'Paper Bags', 'quantity' => '40 pcs', 'unit_price' => '1.25',
        'customization_notes' => 'Confirm size before packing.',
    ]);
    Payment::factory()->for($order)->create(['payment_date' => '2026-10-10', 'amount' => '50.00']);
    Payment::factory()->for($order)->create([
        'payment_date' => '2026-12-01', 'amount' => '25.00', 'payment_type' => 'full_payment',
        'receipt_name' => 'receipt.pdf', 'receipt_path' => 'private-receipt-path', 'receipt_bucket' => 'private-bucket',
    ]);

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-05']);

    $response->assertOk();
    $sheets = exportSheets($response);
    expect(exportRows($sheets['Orders']))->toBe([[
        'Order Status' => 'Delivered', 'Order ID' => 'ORD-2026-0001',
        'Customer Name' => 'Acme Packaging', 'Order Date' => '46329',
        'Order Items' => 'Transparent Bags, Paper Bags',
        'Remarks' => 'Transparent Bags: Pack in bundles of 100.; Paper Bags: Confirm size before packing.',
        'Balance' => '100.00', 'Total Amount' => '175.00', 'Terms of Payment' => 'Net 30',
        'Payment Status' => 'Partially paid (Overdue)',
    ]]);
    expect($sheets['Orders']->document->textContent)->not->toContain('private-receipt-path', 'private-bucket', $this->admin->password);
});

test('overdue and payment statuses use actual balances and the reporting day', function (?string $dueDate, string $paid, string $dueStatus, string $paymentStatus, string $balance): void {
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
        'Payment Status' => $paymentStatus.' ('.$dueStatus.')', 'Balance' => $balance,
    ]);
})->with([
    'past due unpaid' => ['2026-11-04', '0.00', 'Overdue', 'Unpaid', '125.00'],
    'due today' => ['2026-11-05', '0.00', 'Due today', 'Unpaid', '125.00'],
    'future due' => ['2026-11-06', '0.00', 'Not overdue', 'Unpaid', '125.00'],
    'no payment due date' => [null, '0.00', 'No due date', 'Unpaid', '125.00'],
    'settled past due' => ['2026-11-04', '125.00', 'Settled', 'Fully paid', '0.00'],
    'underpaid full-payment label' => ['2026-11-04', '50.00', 'Overdue', 'Partially paid', '75.00'],
    'legacy overpayment' => ['2026-11-04', '150.00', 'Settled', 'Fully paid', '0.00'],
]);

test('fractional item subtotals are rounded per line when exporting legacy quantities', function (): void {
    $order = Order::factory()->create(['order_date' => '2026-11-01']);
    OrderItem::factory()->for($order)->count(3)->sequence(
        ['quantity' => '2.35 kgs', 'unit_price' => '1.25'],
        ['quantity' => '0.01 kgs', 'unit_price' => '0.50'],
        ['quantity' => '3', 'unit' => 'pcs', 'unit_price' => '1.25'],
    )->create();

    $response = $this->post('/exports/orders', ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']);

    $response->assertOk();
    $sheets = exportSheets($response);
    expect(exportRows($sheets['Orders'])[0])->toMatchArray(['Total Amount' => '6.70', 'Balance' => '6.70']);
});

test('empty date ranges keep the template headings and remove all sample data', function (string $type, string $sheetName): void {
    $response = $this->post('/exports/'.$type, ['date_from' => '2026-11-01', 'date_to' => '2026-11-01']);

    $response->assertOk()->assertDownload();
    $sheets = exportSheets($response);
    expect(array_keys($sheets))->toBe([$sheetName]);
    expect(exportRows($sheets[$sheetName]))->toBe([]);
    $columnCount = $type === 'clients' ? 7 : 10;
    $lastColumn = $type === 'clients' ? 'G' : 'J';
    expect($sheets[$sheetName]->evaluate('count(//s:row[@r="7"]/s:c)'))->toBe((float) $columnCount);
    expect($sheets[$sheetName]->evaluate('count(//s:col)'))->toBe((float) $columnCount);
    expect($sheets[$sheetName]->evaluate('string(//s:dimension/@ref)'))->toBe('A1:'.$lastColumn.'8');
    expect($sheets[$sheetName]->evaluate('count(//s:mergeCell[contains(@ref, ":'.$lastColumn.'")])'))->toBe(6.0);
    expect($sheets[$sheetName]->document->textContent)->toContain('OMS', 'Export Administrator')
        ->not->toContain('Batista', 'ORD-2026-0004', '[Remarks]', '[Balance]', '[Total Amount]', 'Name of Exporter');
})->with([
    'clients' => ['clients', 'Clients'],
    'orders' => ['orders', 'Orders'],
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
    $rows = exportRows($sheets['Orders']);
    expect($rows)->toHaveCount(205);
    expect((float) array_sum(array_column($rows, 'Total Amount')))->toBe(25625.0);
    expect((float) array_sum(array_column($rows, 'Balance')))->toBe(20500.0);
    expect(count($queries))->toBeLessThan(20);
    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*'))->toBe($before);
});
