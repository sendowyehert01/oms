<?php

use App\ExcelWorkbook;

function testWorkbook(): ExcelWorkbook
{
    return new ExcelWorkbook('List of Orders', 'Nov 1, 2026 to Nov 5, 2026', 'Alex Cruz', new DateTimeImmutable('2026-11-05 16:00:00 +08:00'), 'Asia/Manila');
}

function workbookXml(PharData $archive, string $path): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($archive[$path]->getContent()))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    return $xpath;
}

test('Excel workbooks preserve the supplied template and replace placeholders with report data', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Orders', [
        ['label' => 'Order Status'], ['label' => 'Order ID'], ['label' => 'Customer Name'],
        ['label' => 'Order Date', 'type' => 'date'], ['label' => 'Order Items'], ['label' => 'Remarks'],
        ['label' => 'Balance', 'type' => 'money'], ['label' => 'Total Amount', 'type' => 'money'],
        ['label' => 'Terms of Payment'], ['label' => 'Payment Status'],
    ]);
    $sheet->appendRow(['Pending', 'ORD-2026-0001', 'Acme', '2026-11-04', 'Plastic bags', 'Bundle 100', '75.25', '125.00', 'Net 30', 'Partially paid (Overdue)']);
    $sheet->appendRow(['Completed', 'ORD-2026-0002', 'Beta', null, 'Mailers', null, '0.00', '50.00', 'Cash', 'Fully paid (Settled)']);

    try {
        $path = $workbook->build();
        $archive = new PharData($path);
        $template = new PharData(__DIR__.'/../../resources/list-of-orders.xlsx');
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        $templateXml = workbookXml($template, 'xl/worksheets/sheet1.xml');

        expect(file_get_contents($path, false, null, 0, 2))->toBe('PK');
        expect($archive->offsetExists('[Content_Types].xml'))->toBeTrue();
        expect($archive->offsetExists('xl/_rels/workbook.xml.rels'))->toBeTrue();
        expect($archive->offsetExists('xl/sharedStrings.xml'))->toBeFalse();
        expect($archive['xl/styles.xml']->getContent())->toBe($template['xl/styles.xml']->getContent());
        expect($archive['xl/theme/theme1.xml']->getContent())->toBe($template['xl/theme/theme1.xml']->getContent());
        foreach (['mergeCells', 'pageMargins', 'sheetFormatPr', 'sheetViews'] as $element) {
            expect($xml->query('//s:'.$element)->item(0)->C14N())->toBe($templateXml->query('//s:'.$element)->item(0)->C14N());
        }
        foreach ($templateXml->query('//s:col') as $index => $column) {
            $exportedColumn = $xml->query('//s:col')->item($index);
            expect((float) $exportedColumn->getAttribute('width'))->toBeGreaterThanOrEqual((float) $column->getAttribute('width'));
            foreach (['min', 'max', 'style', 'bestFit', 'customWidth'] as $attribute) {
                expect($exportedColumn->getAttribute($attribute))->toBe($column->getAttribute($attribute));
            }
        }
        foreach ($templateXml->query('//s:row[number(@r) <= 7]/s:c') as $cell) {
            $reference = $cell->getAttribute('r');
            expect($xml->evaluate('string(//s:c[@r="'.$reference.'"]/@s)'))->toBe($cell->getAttribute('s'));
        }
        expect($xml->evaluate('string(//s:c[@r="A1"]/s:is/s:t)'))->toBe('OMS');
        expect($xml->evaluate('string(//s:c[@r="A2"]/s:is/s:t)'))->toBe('Alex Cruz');
        expect($xml->evaluate('string(//s:c[@r="A3"]/s:is/s:t)'))->toBe('Nov 5, 2026 4:00 PM (Asia/Manila)');
        expect($xml->evaluate('string(//s:c[@r="A5"]/s:is/s:t)'))->toBe('List of Orders');
        expect($xml->evaluate('string(//s:c[@r="A6"]/s:is/s:t)'))->toBe('Nov 1, 2026 to Nov 5, 2026');
        expect($xml->evaluate('string(//s:dimension/@ref)'))->toBe('A1:J9');
        expect($xml->evaluate('string(//s:c[@r="G8"]/s:v)'))->toBe('75.25');
        expect($xml->evaluate('string(//s:c[@r="D8"]/s:v)'))->toBe('46330');
        expect($xml->evaluate('string(//s:c[@r="D8"]/@s)'))->toBe('8');
        expect($xml->evaluate('string(//s:c[@r="A8"]/@s)'))->toBe($xml->evaluate('string(//s:c[@r="A9"]/@s)'));
        expect($xml->evaluate('string(//s:c[@r="J8"]/@s)'))->toBe($xml->evaluate('string(//s:c[@r="J9"]/@s)'));
        foreach (new RecursiveIteratorIterator($archive) as $entry) {
            $document = new DOMDocument;
            expect($document->loadXML($entry->getContent()))->toBeTrue();
        }
    } finally {
        unset($archive, $template, $entry);
        $workbook->cleanup();
    }

    expect(is_dir(dirname($sheet->path())))->toBeFalse();
});

test('literal text preserves leading zeros and spreadsheet formulas without executing them', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Clients', [
        ['label' => 'Company'], ['label' => 'Phone'], ['label' => 'Address'],
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
    $sheet = $workbook->addSheet('Totals', [['label' => 'Amount (PHP)', 'type' => 'money']]);
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

test('empty worksheets remain valid with the template design and no sample records', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Clients', [['label' => 'Company'], ['label' => 'Phone']]);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');

        expect($sheet->count())->toBe(0);
        expect($xml->evaluate('count(//s:row[number(@r) > 7]/s:c[s:v or s:is])'))->toBe(0.0);
        expect($xml->evaluate('count(//s:mergeCell)'))->toBe(6.0);
        expect($xml->evaluate('string(//s:dimension/@ref)'))->toBe('A1:J8');
        expect($archive->offsetExists('xl/sharedStrings.xml'))->toBeFalse();
        $template = new PharData(__DIR__.'/../../resources/list-of-orders.xlsx');
        $templateXml = workbookXml($template, 'xl/worksheets/sheet1.xml');
        expect($xml->query('//s:cols')->item(0)->C14N())->toBe($templateXml->query('//s:cols')->item(0)->C14N());
        expect($xml->document->textContent)->not->toContain('Batista', 'ORD-2026-0004', '[Remarks]');
    } finally {
        unset($archive, $template);
        $workbook->cleanup();
        $workbook->cleanup();
    }

    expect(is_file($sheet->path()))->toBeFalse();
});

test('cleanup removes unfinished worksheets after invalid data interrupts report writing', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Orders', [['label' => 'Amount', 'type' => 'money']]);

    try {
        expect(fn () => $sheet->appendRow(['invalid']))->toThrow(InvalidArgumentException::class);
    } finally {
        $workbook->cleanup();
    }

    expect(is_dir(dirname($sheet->path())))->toBeFalse();
});

test('long notes retain their complete text and the template row formatting', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Notes', [['label' => 'Notes']]);
    $sheet->appendRow(["First line\nSecond line\nThird line\nFourth line"]);

    try {
        $archive = new PharData($workbook->build());
        $template = new PharData(__DIR__.'/../../resources/list-of-orders.xlsx');
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        $templateXml = workbookXml($template, 'xl/worksheets/sheet1.xml');

        expect($xml->evaluate('string(//s:row[@r="8"]/@ht)'))->toBe($templateXml->evaluate('string(//s:row[@r="8"]/@ht)'));
        expect($xml->evaluate('string(//s:c[@r="A8"]/@s)'))->toBe($templateXml->evaluate('string(//s:c[@r="A8"]/@s)'));
        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe("First line\nSecond line\nThird line\nFourth line");
    } finally {
        unset($archive, $template);
        $workbook->cleanup();
    }
});

test('cleanup also removes worksheets whose heading construction failed', function (): void {
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*');
    $workbook = testWorkbook();

    try {
        expect(fn () => $workbook->addSheet('Broken', [['label' => new stdClass]]))->toThrow(TypeError::class);
    } finally {
        $workbook->cleanup();
    }

    expect(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-*'))->toBe($before);
});

test('compressed workbooks declare ZIP versions supported by Microsoft Excel in every header', function (): void {
    $workbook = testWorkbook();
    $workbook->addSheet('Orders', [['label' => 'Order ID']])->appendRow(['ORD-2026-0001']);

    try {
        $contents = file_get_contents($workbook->build());
        $end = substr($contents, -22);
        expect(substr($end, 0, 4))->toBe("PK\x05\x06");
        $entryCount = unpack('v', substr($end, 10, 2))[1];
        $offset = unpack('V', substr($end, 16, 4))[1];
        expect($entryCount)->toBe(9);

        for ($index = 0; $index < $entryCount; $index++) {
            expect(substr($contents, $offset, 4))->toBe("PK\x01\x02");
            expect(unpack('v', substr($contents, $offset + 10, 2))[1])->toBe(8);
            expect(unpack('v', substr($contents, $offset + 4, 2))[1] & 0xFF)->toBeGreaterThanOrEqual(20);
            expect(unpack('v', substr($contents, $offset + 6, 2))[1])->toBe(20);
            $localOffset = unpack('V', substr($contents, $offset + 42, 4))[1];
            expect(substr($contents, $localOffset, 4))->toBe("PK\x03\x04");
            expect(unpack('v', substr($contents, $localOffset + 4, 2))[1])->toBe(20);
            $lengths = unpack('vname/vextra/vcomment', substr($contents, $offset + 28, 6));
            $offset += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
        }
        expect(substr($contents, $offset, 4))->toBe("PK\x05\x06");
    } finally {
        $workbook->cleanup();
    }
});

test('all populated columns fit their headings and the longest value across every row', function (): void {
    $workbook = testWorkbook();
    $headings = ['Order Status', 'Order ID', 'Customer Name', 'Order Date', 'Order Items', 'Remarks', 'Balance', 'Total Amount', 'Terms of Payment', 'Payment Status'];
    $sheet = $workbook->addSheet('Orders', array_map(fn (string $heading): array => ['label' => $heading], $headings));
    $sheet->appendRow(array_fill(0, 10, 'Short'));
    $values = ['Ready for Delivery', 'ORD-2026-0001234567', 'WWWWWWWWWWWWWWWWWWWW', '5-Nov-26', 'Custom printed packaging bags', 'Pack and label each bundle with its order number', '12345678901234.56', '12345678901234.56', 'Payment within thirty calendar days', 'Partially paid (Not overdue)'];
    $sheet->appendRow($values);
    $sheet->appendRow(array_fill(0, 10, 'Short again'));

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        foreach ($xml->query('//s:col') as $index => $column) {
            $width = (float) $column->getAttribute('width');
            expect($width)->toBeGreaterThan(max(mb_strwidth($headings[$index]), mb_strwidth($values[$index])));
            expect($width)->toBeLessThanOrEqual(255.0);
            expect($column->getAttribute('bestFit'))->toBe('1');
            expect($column->getAttribute('customWidth'))->toBe('1');
        }
    } finally {
        unset($archive);
        $workbook->cleanup();
    }
});

test('column sizing handles multilingual and multiline text without widening unused or merged columns', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Clients', [['label' => 'ID'], ['label' => 'Company'], ['label' => 'Address']]);
    $company = str_repeat('東京', 12);
    $line = str_repeat('Wide address ', 4);
    $sheet->appendRow(['1', $company, $line."\n".$line]);

    try {
        $archive = new PharData($workbook->build());
        $template = new PharData(__DIR__.'/../../resources/list-of-orders.xlsx');
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        $templateXml = workbookXml($template, 'xl/worksheets/sheet1.xml');
        $columns = $xml->query('//s:col');
        expect((float) $columns->item(0)->getAttribute('width'))->toBeLessThan(20.0);
        expect((float) $columns->item(1)->getAttribute('width'))->toBeGreaterThan(mb_strwidth($company));
        expect((float) $columns->item(2)->getAttribute('width'))->toBeGreaterThan(mb_strwidth($line));
        expect((float) $columns->item(2)->getAttribute('width'))->toBeLessThan(mb_strwidth($line) * 2);
        foreach ($templateXml->query('//s:col[number(@min) > 3]') as $column) {
            expect($columns->item((int) $column->getAttribute('min') - 1)->C14N())->toBe($column->C14N());
        }
    } finally {
        unset($archive, $template);
        $workbook->cleanup();
    }
});

test('column widths stay within the Excel limit while retaining long cell values', function (): void {
    $workbook = testWorkbook();
    $sheet = $workbook->addSheet('Orders', [['label' => 'Remarks']]);
    $remarks = str_repeat('W', 500);
    $sheet->appendRow([$remarks]);

    try {
        $archive = new PharData($workbook->build());
        $xml = workbookXml($archive, 'xl/worksheets/sheet1.xml');
        expect((float) $xml->evaluate('string(//s:col[@min="1"]/@width)'))->toBe(255.0);
        expect($xml->evaluate('string(//s:c[@r="A8"]/s:is/s:t)'))->toBe($remarks);
    } finally {
        unset($archive);
        $workbook->cleanup();
    }
});
