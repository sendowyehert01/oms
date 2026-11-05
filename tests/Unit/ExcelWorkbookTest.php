<?php

use App\ExcelWorkbook;

function testWorkbook(): ExcelWorkbook
{
    return new ExcelWorkbook('OMS | Test report', 'Nov 1, 2026 to Nov 5, 2026', 'Alex Cruz', new DateTimeImmutable('2026-11-05 16:00:00 +08:00'), 'Asia/Manila');
}

function workbookXml(PharData $archive, string $path): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($archive[$path]->getContent()))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return $xpath;
}

test('Excel workbooks package readable worksheets with professional report formatting', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Orders', [
        ['label' => 'Order number', 'width' => 24],
        ['label' => 'Balance (PHP)', 'width' => 20, 'type' => 'money'],
        ['label' => 'Payment due', 'width' => 18, 'type' => 'date'],
        ['label' => 'Due status', 'width' => 20, 'type' => 'status'],
    ]);
    $sheet->appendRow(['ORD-1', '75.25', '2026-11-04', 'Overdue']);
    $sheet->appendRow(['ORD-2', '0.00', null, 'Settled']);

    try {
        $path = $workbook->build();
        $archive = new PharData($path);
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        $styles = workbookXml($archive, 'xl/styles.xml');

        expect(file_get_contents($path, false, null, 0, 2))->toBe('PK');
        expect($archive->offsetExists('[Content_Types].xml'))->toBeTrue();
        expect($archive->offsetExists('xl/_rels/workbook.xml.rels'))->toBeTrue();
        expect($xml->evaluate('string(//s:c[@r="A1"]/s:is/s:t)'))->toBe('OMS | Test report | Orders');
        expect($xml->evaluate('string(//s:autoFilter/@ref)'))->toBe('A7:D9');
        expect($xml->evaluate('string(//s:pane/@ySplit)'))->toBe('7');
        expect($xml->evaluate('string(//s:pane/@topLeftCell)'))->toBe('B8');
        expect($xml->evaluate('string(//s:pageSetup/@orientation)'))->toBe('landscape');
        expect($xml->evaluate('string(//s:c[@r="B8"]/s:v)'))->toBe('75.25');
        expect($xml->evaluate('string(//s:c[@r="C8"]/s:v)'))->toBe('46330');
        expect($xml->evaluate('string(//s:c[@r="A8"]/@s)'))->not->toBe($xml->evaluate('string(//s:c[@r="A9"]/@s)'));
        expect($xml->evaluate('string(//s:c[@r="D8"]/@s)'))->not->toBe($xml->evaluate('string(//s:c[@r="D9"]/@s)'));
        expect($styles->evaluate('string(//s:numFmt[@numFmtId="164"]/@formatCode)'))->toContain('PHP', '#,##0.00');
        expect($styles->evaluate('string(//s:fill[3]/s:patternFill/s:fgColor/@rgb)'))->toBe('FF730E10');
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $document = new DOMDocument;
            expect($document->loadXML($entry->getContent()))->toBeTrue();
        }
    } finally {
        unset($archive, $entry);
        $workbook->cleanup();
    }

    expect(is_dir(dirname($sheet->path())))->toBeFalse();
});

test('literal text preserves leading zeros and spreadsheet formulas without executing them', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Clients', [
        ['label' => 'Company', 'width' => 30], ['label' => 'Phone', 'width' => 20], ['label' => 'Address', 'width' => 30],
    ]);
    $sheet->appendRow(['=HYPERLINK("https://example.com", "Click")', '09123456789', "A & B <C>\x00\nMakati"]);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');

        expect($xml->evaluate('count(//s:f)'))->toBe(0.0);
        expect($xml->evaluate('string(//s:c[@r="A8"]/@t)'))->toBe('inlineStr');
        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe('=HYPERLINK("https://example.com", "Click")');
        expect($xml->evaluate('string(//s:c[@r="B8"]/s:is/s:t)'))->toBe('09123456789');
        expect($xml->evaluate('string(//s:c[@r="C8"]/s:is/s:t)'))->toBe("A & B <C>\nMakati");
    } finally {
        unset($archive);
        $workbook->cleanup();
    }
});

test('numeric values exceeding Excel precision retain every cent as literal decimals', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Totals', [['label' => 'Amount (PHP)', 'width' => 24, 'type' => 'money']]);
    $sheet->appendRow(['12345678901234.56']);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');

        expect($xml->evaluate('string(//s:c[@r="A8"]/@t)'))->toBe('inlineStr');
        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe('12345678901234.56');
    } finally {
        unset($archive);
        $workbook->cleanup();
    }
});

test('empty worksheets remain valid and explain that no records matched', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Clients', [['label' => 'Company', 'width' => 30], ['label' => 'Phone', 'width' => 20]]);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');

        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe('No records match the selected date range.');
        expect($xml->evaluate('count(//s:autoFilter)'))->toBe(0.0);
    } finally {
        unset($archive);
        $workbook->cleanup();
        $workbook->cleanup();
    }

    expect(is_file($sheet->path()))->toBeFalse();
});

test('cleanup removes unfinished worksheets after invalid data interrupts report writing', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Orders', [['label' => 'Amount', 'width' => 20, 'type' => 'money']]);

    try {
        expect(fn () => $sheet->appendRow(['invalid']))->toThrow(InvalidArgumentException::class);
    } finally {
        $workbook->cleanup();
    }

    expect(is_dir(dirname($sheet->path())))->toBeFalse();
});

test('long notes get enough row height to stay readable', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Notes', [['label' => 'Notes', 'width' => 26]]);
    $sheet->appendRow(["First line\nSecond line\nThird line\nFourth line"]);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');

        expect((int) $xml->evaluate('string(//s:row[@r="8"]/@ht)'))->toBeGreaterThanOrEqual(60);
        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe("First line\nSecond line\nThird line\nFourth line");
    } finally {
        unset($archive);
        $workbook->cleanup();
    }
});

test('cleanup also removes worksheets whose heading construction failed', function (): void {
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*');
    $workbook = testWorkbook();

    try {
        expect(fn () => $workbook->addSheet('Broken', [['label' => new stdClass, 'width' => 20]]))->toThrow(TypeError::class);
    } finally {
        $workbook->cleanup();
    }

    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*'))->toBe($before);
});
