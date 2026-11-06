<?php

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use InvalidArgumentException;
use Phar;
use PharData;
use RecursiveIteratorIterator;
use RuntimeException;

class ExcelWorkbook
{
    /** @var array<string, string> */
    private array $templateParts = [];

    /** @var list<string> */
    private array $worksheetPaths = [];

    private ?ExcelWorksheet $worksheet = null;

    private string $sheetName = '';

    private readonly string $directory;

    public function __construct(
        private readonly string $title,
        private readonly string $period,
        private readonly string $preparedBy,
        private readonly DateTimeInterface $generatedAt,
        private readonly string $timezone,
    ) {
        $template = new PharData(__DIR__.'/../resources/list-of-orders.xlsx');
        $prefix = 'phar://'.str_replace('\\', '/', $template->getPath()).'/';

        foreach (new RecursiveIteratorIterator($template) as $entry) {
            $path = str_replace('\\', '/', $entry->getPathname());
            $this->templateParts[substr($path, strlen($prefix))] = $entry->getContent();
        }
        unset($template, $entry);
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-'.bin2hex(random_bytes(12));

        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('The export directory could not be created.');
        }
    }

    /** @param list<array{label: string, type?: string}> $columns */
    public function addSheet(string $name, array $columns): ExcelWorksheet
    {
        if ($name === '' || mb_strlen($name) > 31 || preg_match('/[\\\\\/\[\]*?:]/', $name)) {
            throw new InvalidArgumentException('The worksheet name must be valid.');
        }
        if ($this->worksheet !== null) {
            throw new InvalidArgumentException('The template contains one worksheet.');
        }
        $strings = self::document($this->templateParts['xl/sharedStrings.xml']);
        $sharedStrings = [];
        foreach ($strings->getElementsByTagName('si') as $string) {
            $sharedStrings[] = $string->textContent;
        }
        $path = $this->directory.DIRECTORY_SEPARATOR.'sheet1.xml';
        $this->worksheetPaths = [$path, $path.'.rows'];
        $this->worksheet = new ExcelWorksheet($path, $this->templateParts['xl/worksheets/sheet1.xml'], $sharedStrings, $columns, [
            'A1' => 'OMS',
            'A2' => $this->preparedBy,
            'A3' => $this->generatedAt->format('M j, Y g:i A').' ('.$this->timezone.')',
            'A5' => $this->title,
            'A6' => $this->period,
        ]);
        $this->sheetName = $name;

        return $this->worksheet;
    }

    public function build(): string
    {
        if ($this->worksheet === null) {
            throw new RuntimeException('A workbook needs a worksheet.');
        }
        $this->worksheet->finish();
        $path = $this->directory.DIRECTORY_SEPARATOR.'report.zip';
        $archive = new PharData($path, 0, null, Phar::ZIP);

        foreach ($this->templateParts as $name => $contents) {
            if ($name === 'xl/sharedStrings.xml') {
                continue;
            }
            if ($name === 'xl/worksheets/sheet1.xml') {
                $archive->addFile($this->worksheet->path(), $name);

                continue;
            }
            $contents = match ($name) {
                '[Content_Types].xml', 'xl/_rels/workbook.xml.rels' => $this->withoutSharedStrings($contents),
                'xl/workbook.xml' => $this->workbookXml($contents),
                'docProps/core.xml' => $this->coreProperties(),
                'docProps/app.xml' => $this->applicationProperties($contents),
                default => $contents,
            };
            $archive->addFromString($name, $contents);
        }
        $archive->compressFiles(Phar::GZ);
        unset($archive);
        $this->normalizeZipVersions($path);

        return $path;
    }

    public function cleanup(): void
    {
        $this->worksheet?->close();
        foreach ($this->worksheetPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $archivePath = $this->directory.DIRECTORY_SEPARATOR.'report.zip';
        if (is_file($archivePath)) {
            Phar::unlinkArchive($archivePath);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public static function literalText(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        $value = str_replace(["\xEF\xBF\xBE", "\xEF\xBF\xBF"], '', $value);

        return mb_scrub($value, 'UTF-8');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars(self::literalText($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The Excel template contains invalid XML.');
        }

        return $document;
    }

    private function withoutSharedStrings(string $xml): string
    {
        $document = self::document($xml);
        foreach (iterator_to_array($document->documentElement->childNodes) as $node) {
            if ($node->attributes?->getNamedItem('PartName')?->nodeValue === '/xl/sharedStrings.xml'
                || $node->attributes?->getNamedItem('Target')?->nodeValue === 'sharedStrings.xml') {
                $node->parentNode->removeChild($node);
            }
        }

        return $document->saveXML();
    }

    private function workbookXml(string $xml): string
    {
        $document = self::document($xml);
        $document->getElementsByTagName('sheet')->item(0)->setAttribute('name', $this->sheetName);
        foreach (iterator_to_array($document->getElementsByTagName('AlternateContent')) as $node) {
            $node->parentNode->removeChild($node);
        }

        return $document->saveXML();
    }

    private function coreProperties(): string
    {
        $created = (new DateTimeImmutable($this->generatedAt->format(DATE_ATOM)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.self::escape($this->title).'</dc:title><dc:creator>'.self::escape($this->preparedBy).'</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created></cp:coreProperties>';
    }

    private function applicationProperties(string $xml): string
    {
        $document = self::document($xml);
        $document->getElementsByTagName('TitlesOfParts')->item(0)->getElementsByTagName('lpstr')->item(0)->textContent = $this->sheetName;
        $document->getElementsByTagName('Company')->item(0)->textContent = 'OMS';

        return $document->saveXML();
    }

    /**
     * Phar writes zero ZIP versions; DEFLATE entries require ZIP 2.0 for Excel.
     * Update both header types without changing any workbook content.
     */
    private function normalizeZipVersions(string $path): void
    {
        $stream = fopen($path, 'r+b');
        if ($stream === false) {
            throw new RuntimeException('The Excel archive could not be opened.');
        }

        try {
            if (fseek($stream, -22, SEEK_END) !== 0) {
                throw new RuntimeException('The Excel archive directory could not be read.');
            }
            $end = fread($stream, 22);
            if (strlen($end) !== 22 || substr($end, 0, 4) !== "PK\x05\x06") {
                throw new RuntimeException('The Excel archive directory is invalid.');
            }
            $entryCount = unpack('v', substr($end, 10, 2))[1];
            $offset = unpack('V', substr($end, 16, 4))[1];

            for ($index = 0; $index < $entryCount; $index++) {
                if (fseek($stream, $offset) !== 0) {
                    throw new RuntimeException('The Excel archive entry could not be read.');
                }
                $header = fread($stream, 46);
                if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('The Excel archive entry is invalid.');
                }
                $localOffset = unpack('V', substr($header, 42, 4))[1];
                foreach ([$offset + 4 => pack('vv', 20, 20), $localOffset + 4 => pack('v', 20)] as $position => $version) {
                    if (fseek($stream, $position) !== 0 || fwrite($stream, $version) !== strlen($version)) {
                        throw new RuntimeException('The Excel archive versions could not be written.');
                    }
                }
                $lengths = unpack('vname/vextra/vcomment', substr($header, 28, 6));
                $offset += 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
            }
        } finally {
            fclose($stream);
        }
    }
}
