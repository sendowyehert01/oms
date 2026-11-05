<?php

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use Throwable;

class ExcelWorksheet
{
    private const int HEADER_ROW = 7;

    /** @var resource|null */
    private mixed $stream = null;

    private int $dataRows = 0;

    private bool $finished = false;

    /** @param list<array{label: string, width: int, type?: string}> $columns */
    public function __construct(
        private readonly string $filePath,
        string $title,
        string $period,
        string $prepared,
        private readonly array $columns,
        private readonly bool $filterable,
        int $frozenColumns,
    ) {
        if (count($columns) < 1 || count($columns) > 16_384) {
            throw new InvalidArgumentException('A worksheet must have between 1 and 16,384 columns.');
        }
        $this->stream = fopen($filePath, 'wb');
        if ($this->stream === false) {
            throw new RuntimeException('The worksheet could not be created.');
        }
        try {
            $this->writeHeaders($title, $period, $prepared, $frozenColumns);
        } catch (Throwable $exception) {
            $this->close();

            throw $exception;
        }
    }

    private function writeHeaders(string $title, string $period, string $prepared, int $frozenColumns): void
    {
        $frozenColumns = min(max(0, $frozenColumns), count($this->columns) - 1);
        $firstCell = ExcelWorkbook::columnName($frozenColumns + 1).(self::HEADER_ROW + 1);
        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView showGridLines="0" zoomScale="90" workbookViewId="0"><pane xSplit="'.$frozenColumns.'" ySplit="'.self::HEADER_ROW.'" topLeftCell="'.$firstCell.'" activePane="'.($frozenColumns === 0 ? 'bottomLeft' : 'bottomRight').'" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>');
        foreach ($this->columns as $index => $column) {
            $position = $index + 1;
            $this->write('<col min="'.$position.'" max="'.$position.'" width="'.$column['width'].'" customWidth="1"/>');
        }
        $this->write('</cols><sheetData>');
        $this->write('<row r="1" ht="36" customHeight="1">'.$this->textCell('A1', $title, 1).'</row>');
        $this->write('<row r="2" ht="22" customHeight="1">'.$this->textCell('A2', 'Date range: '.$period.' (inclusive)', 2).'</row>');
        $this->write('<row r="3" ht="22" customHeight="1">'.$this->textCell('A3', $prepared, 3).'</row>');
        $this->write('<row r="5" ht="22" customHeight="1">'.$this->textCell('A5', 'OMS | Philippine pesos (PHP) | Current records at the time of export', 3).'</row><row r="7" ht="34" customHeight="1">');
        foreach ($this->columns as $index => $column) {
            $this->write($this->textCell(ExcelWorkbook::columnName($index + 1).'7', $column['label'], 4));
        }
        $this->write('</row>');
    }

    /**
     * @param  list<string|int|float|DateTimeInterface|null|array{value: string|int|float|DateTimeInterface|null, type: string}>  $values
     */
    public function appendRow(array $values): void
    {
        if ($this->finished || count($values) !== count($this->columns)) {
            throw new InvalidArgumentException('Worksheet rows must match their columns and be written before finishing.');
        }
        if ($this->dataRows >= 1_048_576 - self::HEADER_ROW) {
            throw new LengthException('The export exceeds the Excel worksheet row limit.');
        }
        $this->dataRows++;
        $row = $this->dataRows + self::HEADER_ROW;
        $this->write('<row r="'.$row.'" ht="'.$this->rowHeight($values).'" customHeight="1">');
        foreach ($values as $index => $value) {
            $type = $this->columns[$index]['type'] ?? 'text';
            if (is_array($value)) {
                $type = $value['type'];
                $value = $value['value'];
            }
            $reference = ExcelWorkbook::columnName($index + 1).$row;
            $style = ExcelWorkbook::cellStyle($type, $this->dataRows % 2 === 0, is_scalar($value) ? (string) $value : null);
            if ($value === null || $value === '') {
                $this->write('<c r="'.$reference.'" s="'.$style.'"/>');
            } elseif (in_array($type, ['date', 'datetime'], true)) {
                $date = $value instanceof DateTimeInterface ? $value : new DateTimeImmutable((string) $value);
                $wallTime = new DateTimeImmutable($date->format($type === 'date' ? 'Y-m-d 00:00:00' : 'Y-m-d H:i:s'), new DateTimeZone('UTC'));
                if ((int) $wallTime->format('Y') < 1900) {
                    $this->write($this->textCell($reference, $date->format('Y-m-d H:i:s'), $style));
                } else {
                    $serial = ($wallTime->getTimestamp() - (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))->getTimestamp()) / 86_400;
                    if ($wallTime < new DateTimeImmutable('1900-03-01', new DateTimeZone('UTC'))) {
                        $serial--;
                    }
                    $this->write('<c r="'.$reference.'" s="'.$style.'"><v>'.rtrim(rtrim(number_format($serial, 10, '.', ''), '0'), '.').'</v></c>');
                }
            } elseif (in_array($type, ['money', 'integer', 'decimal'], true)) {
                $number = (string) $value;
                if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $number)) {
                    throw new InvalidArgumentException('Numeric worksheet cells must contain a decimal number.');
                }
                $digits = ltrim(str_replace(['.', '-'], '', $number), '0');
                if (strlen($digits) > 15) {
                    $this->write($this->textCell($reference, $number, $style));
                } else {
                    $this->write('<c r="'.$reference.'" s="'.$style.'"><v>'.$number.'</v></c>');
                }
            } else {
                $this->write($this->textCell($reference, $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value, $style));
            }
        }
        $this->write('</row>');
    }

    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $lastColumn = ExcelWorkbook::columnName(count($this->columns));
        if ($this->dataRows === 0) {
            $this->write('<row r="8" ht="32" customHeight="1">'.$this->textCell('A8', 'No records match the selected date range.', 5).'</row>');
        }
        $this->write('</sheetData>');
        if ($this->filterable && $this->dataRows > 0) {
            $this->write('<autoFilter ref="A7:'.$lastColumn.($this->dataRows + self::HEADER_ROW).'"/>');
        }
        $merges = count($this->columns) > 1 ? ['A1:'.$lastColumn.'1', 'A2:'.$lastColumn.'2', 'A3:'.$lastColumn.'3', 'A5:'.$lastColumn.'5'] : [];
        if ($this->dataRows === 0 && count($this->columns) > 1) {
            $merges[] = 'A8:'.$lastColumn.'8';
        }
        if ($merges !== []) {
            $this->write('<mergeCells count="'.count($merges).'">');
            foreach ($merges as $merge) {
                $this->write('<mergeCell ref="'.$merge.'"/>');
            }
            $this->write('</mergeCells>');
        }
        $this->write('<pageMargins left="0.25" right="0.25" top="0.4" bottom="0.4" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>'.ExcelWorkbook::escape('&LOMS&CPage &P of &N&RConfidential').'</oddFooter></headerFooter></worksheet>');
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
    private function rowHeight(array $values): int
    {
        $lines = 1;
        foreach ($values as $index => $value) {
            $text = is_array($value) ? $value['value'] : $value;
            if (! is_scalar($text)) {
                continue;
            }
            $width = max(1, $this->columns[$index]['width'] - 2);
            $wrappedLines = 0;
            foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
                $wrappedLines += max(1, (int) ceil(mb_strwidth($line, 'UTF-8') / $width));
            }
            $lines = max($lines, $wrappedLines);
        }

        return min(240, max(32, $lines * 15 + 8));
    }

    private function textCell(string $reference, string $text, int $style): string
    {
        return '<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.ExcelWorkbook::escape($text).'</t></is></c>';
    }

    private function write(string $xml): void
    {
        if (! is_resource($this->stream) || fwrite($this->stream, $xml) !== strlen($xml)) {
            throw new RuntimeException('The worksheet could not be written.');
        }
    }
}
