<?php

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use LengthException;
use RuntimeException;

class ExcelWorksheet
{
    private const int HEADER_ROW = 7;

    private const string SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var resource|null */
    private mixed $stream = null;

    private int $dataRows = 0;

    /** @var array<int, float> */
    private array $columnWidths = [];

    private bool $finished = false;

    private readonly DOMDocument $document;

    private readonly DOMElement $rowTemplate;

    private readonly string $textStyle;

    private readonly string $dateStyle;

    /**
     * @param  list<string>  $sharedStrings
     * @param  list<array{label: string, type?: string}>  $columns
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        private readonly string $filePath,
        string $templateXml,
        array $sharedStrings,
        private readonly array $columns,
        array $metadata,
    ) {
        $this->document = ExcelWorkbook::document($templateXml);
        $xpath = new DOMXPath($this->document);
        $xpath->registerNamespace('s', self::SPREADSHEET_NAMESPACE);
        $this->rowTemplate = $xpath->query('//s:row[@r="8"]')->item(0)->cloneNode(true);
        $sampleCells = $this->rowTemplate->getElementsByTagName('c');

        if (count($columns) < 1 || count($columns) > $sampleCells->length) {
            throw new InvalidArgumentException('Worksheet columns must fit the template.');
        }
        $this->textStyle = $sampleCells->item(0)->getAttribute('s');
        $this->dateStyle = $sampleCells->item(3)->getAttribute('s');
        foreach ($sampleCells as $cell) {
            $this->clearCell($cell);
        }
        foreach ($xpath->query('//s:row[number(@r) > 7]') as $row) {
            $row->parentNode->removeChild($row);
        }
        foreach ($xpath->query('//s:c[@t="s"]') as $cell) {
            $this->setText($cell, $sharedStrings[(int) $cell->textContent]);
        }
        foreach ($metadata as $reference => $value) {
            $this->setText($xpath->query('//s:c[@r="'.$reference.'"]')->item(0), $value);
        }
        foreach ($xpath->query('//s:row[@r="7"]/s:c') as $index => $cell) {
            if (isset($columns[$index])) {
                $this->setText($cell, $columns[$index]['label']);
                $this->measureColumn($index, $columns[$index]['label']);
            } else {
                $this->clearCell($cell);
            }
        }
        $this->trimUnusedColumns();
        $this->stream = fopen($filePath.'.rows', 'w+b');
        if ($this->stream === false) {
            throw new RuntimeException('The worksheet could not be created.');
        }
    }

    /** @param list<string|int|float|DateTimeInterface|null|array{value: string|int|float|DateTimeInterface|null, type: string}> $values */
    public function appendRow(array $values): void
    {
        if ($this->finished || count($values) !== count($this->columns)) {
            throw new InvalidArgumentException('Worksheet rows must match their columns and be written before finishing.');
        }
        if ($this->dataRows >= 1_048_576 - self::HEADER_ROW) {
            throw new LengthException('The export exceeds the Excel worksheet row limit.');
        }
        $this->dataRows++;
        $this->write($this->rowXml($values, $this->dataRows + self::HEADER_ROW));
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        if ($this->dataRows === 0) {
            $this->write($this->rowXml(array_fill(0, count($this->columns), null), self::HEADER_ROW + 1));
        }
        if ($this->dataRows > 0) {
            foreach ($this->document->getElementsByTagName('col') as $index => $column) {
                if (! isset($this->columnWidths[$index])) {
                    continue;
                }
                $width = min(255, max((float) $column->getAttribute('width'), $this->columnWidths[$index]));
                $column->setAttribute('width', (string) (ceil($width * 256) / 256));
                $column->setAttribute('bestFit', '1');
                $column->setAttribute('customWidth', '1');
            }
        }
        $lastColumn = chr(64 + count($this->columns));
        $this->document->getElementsByTagName('dimension')->item(0)->setAttribute('ref', 'A1:'.$lastColumn.max(8, $this->dataRows + self::HEADER_ROW));
        [$header, $footer] = explode('</sheetData>', $this->document->saveXML(), 2);
        $output = fopen($this->filePath, 'wb');
        if ($output === false) {
            throw new RuntimeException('The worksheet could not be created.');
        }

        try {
            if (fwrite($output, $header) !== strlen($header)) {
                throw new RuntimeException('The worksheet headings could not be written.');
            }
            $length = ftell($this->stream);
            rewind($this->stream);
            if (stream_copy_to_stream($this->stream, $output) !== $length) {
                throw new RuntimeException('The worksheet rows could not be written.');
            }
            $footer = '</sheetData>'.$footer;
            if (fwrite($output, $footer) !== strlen($footer)) {
                throw new RuntimeException('The worksheet footer could not be written.');
            }
        } finally {
            fclose($output);
        }
        $this->finished = true;
        $this->close();
    }

    public function path(): string
    {
        return $this->filePath;
    }

    public function count(): int
    {
        return $this->dataRows;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        $this->stream = null;
    }

    /** @param list<string|int|float|DateTimeInterface|null|array{value: string|int|float|DateTimeInterface|null, type: string}> $values */
    private function rowXml(array $values, int $number): string
    {
        $row = $this->rowTemplate->cloneNode(true);
        $row->setAttribute('r', (string) $number);
        foreach ($row->getElementsByTagName('c') as $index => $cell) {
            $cell->setAttribute('r', preg_replace('/\d+/', (string) $number, $cell->getAttribute('r')));
            $value = $values[$index] ?? null;
            $type = $this->columns[$index]['type'] ?? 'text';
            if (is_array($value)) {
                $type = $value['type'];
                $value = $value['value'];
            }
            $cell->setAttribute('s', in_array($type, ['date', 'datetime'], true) ? $this->dateStyle : $this->textStyle);
            if ($value === null || $value === '') {
                continue;
            }
            $displayText = $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
            if (in_array($type, ['date', 'datetime'], true)) {
                $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value);
                $wallTime = new DateTimeImmutable($date->format($type === 'date' ? 'Y-m-d 00:00:00' : 'Y-m-d H:i:s'), new DateTimeZone('UTC'));
                if ((int) $wallTime->format('Y') < 1900) {
                    $this->setText($cell, $date->format('Y-m-d H:i:s'));
                    $displayText = $date->format('Y-m-d H:i:s');
                } else {
                    $serial = ($wallTime->getTimestamp() - (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->getTimestamp()) / 86_400;
                    if ($wallTime < new DateTimeImmutable('1900-03-01', new DateTimeZone('UTC'))) {
                        $serial--;
                    }
                    $this->setNumber($cell, rtrim(rtrim(number_format($serial, 10, '.', ''), '0'), '.'));
                    $displayText = $date->format('j-M-y');
                }
            } elseif (in_array($type, ['money', 'integer', 'decimal'], true)) {
                $numberValue = (string) $value;
                if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $numberValue)) {
                    throw new InvalidArgumentException('Numeric worksheet cells must contain a decimal number.');
                }
                if (strlen(ltrim(str_replace(['.', '-'], '', $numberValue), '0')) > 15) {
                    $this->setText($cell, $numberValue);
                } else {
                    $this->setNumber($cell, $numberValue);
                }
            } else {
                $this->setText($cell, $displayText);
            }
            $this->measureColumn($index, $displayText);
        }

        return $this->document->saveXML($row);
    }

    private function measureColumn(int $index, string $text): void
    {
        $text = str_replace("\t", '    ', ExcelWorkbook::literalText($text));
        foreach (preg_split('/\R/u', $text) as $line) {
            $width = mb_strwidth($line, 'UTF-8') * 1.1 + preg_match_all('/[MW@%&#]/u', $line) * 0.5 + 2;
            $this->columnWidths[$index] = max($this->columnWidths[$index] ?? 0, min(255, $width));
        }
    }

    private function trimUnusedColumns(): void
    {
        $columnCount = count($this->columns);
        $lastColumn = chr(64 + $columnCount);
        foreach (iterator_to_array($this->document->getElementsByTagName('col')) as $column) {
            if ((int) $column->getAttribute('min') > $columnCount) {
                $column->parentNode->removeChild($column);
            } elseif ((int) $column->getAttribute('max') > $columnCount) {
                $column->setAttribute('max', (string) $columnCount);
            }
        }
        $rows = iterator_to_array($this->document->getElementsByTagName('row'));
        $rows[] = $this->rowTemplate;
        foreach ($rows as $row) {
            $cells = iterator_to_array($row->getElementsByTagName('c'));
            if ($columnCount > 1 && count($cells) > $columnCount && (int) $row->getAttribute('r') < self::HEADER_ROW) {
                $cells[$columnCount - 1]->setAttribute('s', $cells[array_key_last($cells)]->getAttribute('s'));
            }
            foreach (array_slice($cells, $columnCount) as $cell) {
                $cell->parentNode->removeChild($cell);
            }
            $row->setAttribute('spans', '1:'.$columnCount);
        }
        $merges = $this->document->getElementsByTagName('mergeCells')->item(0);
        if ($columnCount === 1) {
            $merges->parentNode->removeChild($merges);

            return;
        }
        foreach ($merges->getElementsByTagName('mergeCell') as $merge) {
            $merge->setAttribute('ref', preg_replace('/[A-Z]+(?=\d+$)/', $lastColumn, $merge->getAttribute('ref')));
        }
    }

    private function clearCell(DOMElement $cell): void
    {
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->removeAttribute('t');
    }

    private function setText(DOMElement $cell, string $text): void
    {
        $this->clearCell($cell);
        $cell->setAttribute('t', 'inlineStr');
        $inline = $this->document->createElementNS(self::SPREADSHEET_NAMESPACE, 'is');
        $value = $this->document->createElementNS(self::SPREADSHEET_NAMESPACE, 't');
        $value->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $value->appendChild($this->document->createTextNode(ExcelWorkbook::literalText($text)));
        $inline->appendChild($value);
        $cell->appendChild($inline);
    }

    private function setNumber(DOMElement $cell, string $number): void
    {
        $value = $this->document->createElementNS(self::SPREADSHEET_NAMESPACE, 'v');
        $value->appendChild($this->document->createTextNode($number));
        $cell->appendChild($value);
    }

    private function write(string $xml): void
    {
        if (! is_resource($this->stream) || fwrite($this->stream, $xml) !== strlen($xml)) {
            throw new RuntimeException('The worksheet could not be written.');
        }
    }
}
