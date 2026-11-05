<?php

namespace App;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Phar;
use PharData;
use RuntimeException;

class ExcelWorkbook
{
    private const array CELL_TYPES = ['text', 'integer', 'decimal', 'money', 'date', 'datetime'];

    private const array STATUS_STYLES = [
        'Fully paid' => 17, 'Settled' => 17, 'Completed' => 17, 'Available' => 17,
        'Partially paid' => 18, 'Due today' => 18, 'Pending' => 18, 'Partially available' => 18, 'Not checked' => 18, 'No due date' => 18,
        'Unpaid' => 19, 'Overdue' => 19, 'Yes' => 19, 'Unavailable' => 19,
        'In production' => 20, 'Not overdue' => 20,
        'Ready for delivery' => 21, 'Delivered' => 22,
    ];

    /** @var list<array{name: string, worksheet: ExcelWorksheet}> */
    private array $sheets = [];

    /** @var list<string> */
    private array $worksheetPaths = [];

    private readonly string $directory;

    public function __construct(
        private readonly string $title,
        private readonly string $period,
        private readonly string $preparedBy,
        private readonly DateTimeInterface $generatedAt,
        private readonly string $timezone,
    ) {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-export-'.bin2hex(random_bytes(12));

        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('The export directory could not be created.');
        }
    }

    /** @param list<array{label: string, width: int, type?: string}> $columns */
    public function addSheet(string $name, array $columns, bool $filterable = true, int $frozenColumns = 1): ExcelWorksheet
    {
        if ($name === '' || strlen($name) > 31 || preg_match('/[\\\\\/\[\]*?:]/', $name) || in_array($name, array_column($this->sheets, 'name'), true)) {
            throw new InvalidArgumentException('Worksheet names must be valid and unique.');
        }

        $path = $this->directory.DIRECTORY_SEPARATOR.'sheet'.(count($this->sheets) + 1).'.xml';
        $this->worksheetPaths[] = $path;
        $worksheet = new ExcelWorksheet(
            $path,
            $this->title.' | '.$name,
            $this->period,
            'Prepared by '.$this->preparedBy.' | '.$this->generatedAt->format('M j, Y g:i A').' ('.$this->timezone.')',
            $columns,
            $filterable,
            $frozenColumns,
        );
        $this->sheets[] = ['name' => $name, 'worksheet' => $worksheet];

        return $worksheet;
    }

    public function build(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('A workbook needs at least one worksheet.');
        }

        $path = $this->directory.DIRECTORY_SEPARATOR.'report.zip';
        $archive = new PharData($path, 0, null, Phar::ZIP);
        foreach ($this->sheets as $index => $sheet) {
            $sheet['worksheet']->finish();
            $archive->addFile($sheet['worksheet']->path(), 'xl/worksheets/sheet'.($index + 1).'.xml');
        }
        $archive->addFromString('[Content_Types].xml', $this->contentTypes());
        $archive->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml', $this->workbookXml());
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $archive->addFromString('xl/styles.xml', $this->styles());
        $archive->addFromString('docProps/core.xml', $this->coreProperties());
        $archive->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>OMS</Application><Company>OMS</Company><AppVersion>1.0</AppVersion></Properties>');
        $archive->compressFiles(Phar::GZ);
        unset($archive);

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->sheets as $sheet) {
            $sheet['worksheet']->close();
        }
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

    public static function escape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        $value = str_replace(["\xEF\xBF\xBE", "\xEF\xBF\xBF"], '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function columnName(int $column): string
    {
        $name = '';
        while ($column > 0) {
            $column--;
            $name = chr(65 + $column % 26).$name;
            $column = intdiv($column, 26);
        }

        return $name;
    }

    public static function cellStyle(string $type, bool $alternate, ?string $value = null): int
    {
        if ($type === 'status' && isset(self::STATUS_STYLES[$value])) {
            return self::STATUS_STYLES[$value];
        }
        $index = array_search($type, self::CELL_TYPES, true);

        return 5 + ($index === false ? 0 : $index) * 2 + (int) $alternate;
    }

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml.'</Types>';
    }

    private function workbookXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="0"/><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<sheet name="'.self::escape($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }
        $xml .= '</sheets><definedNames>';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<definedName name="_xlnm.Print_Titles" localSheetId="'.$index.'">'.self::escape("'".str_replace("'", "''", $sheet['name'])."'!$1:$7").'</definedName>';
        }

        return $xml.'</definedNames></workbook>';
    }

    private function workbookRelationships(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        return $xml.'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function coreProperties(): string
    {
        $created = (new DateTimeImmutable($this->generatedAt->format(DATE_ATOM)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>'.self::escape($this->title).'</dc:title><dc:creator>'.self::escape($this->preparedBy).'</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">'.$created.'</dcterms:created></cp:coreProperties>';
    }

    private function styles(): string
    {
        $fonts = [
            '<font><sz val="11"/><name val="Calibri"/><color rgb="FF334155"/></font>',
            '<font><b/><sz val="20"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>',
            '<font><sz val="10"/><name val="Calibri"/><color rgb="FF64748B"/></font>',
            '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>',
        ];
        foreach (['166534', '92400E', '991B1B', '1E40AF', '6B21A8', '155E75'] as $color) {
            $fonts[] = '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF'.$color.'"/></font>';
        }
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        foreach (['730E10', '3B0A0D', 'FFFFFF', 'F8F8F6', 'DCFCE7', 'FEF3C7', 'FEE2E2', 'DBEAFE', 'F3E8FF', 'CFFAFE'] as $color) {
            $fills[] = '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$color.'"/><bgColor indexed="64"/></patternFill></fill>';
        }
        $formats = [
            164 => '"PHP" #,##0.00;[Red]("PHP" #,##0.00)',
            165 => 'mmm d, yyyy', 166 => 'mmm d, yyyy h:mm AM/PM', 167 => '#,##0.##',
        ];
        $numberFormats = '';
        foreach ($formats as $id => $format) {
            $numberFormats .= '<numFmt numFmtId="'.$id.'" formatCode="'.self::escape($format).'"/>';
        }
        $styles = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>',
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>',
        ];
        foreach ([0, 3, 167, 164, 165, 166] as $format) {
            foreach ([4, 5] as $fill) {
                $alignment = in_array($format, [3, 167, 164], true) ? 'right' : 'left';
                $styles[] = '<xf numFmtId="'.$format.'" fontId="0" fillId="'.$fill.'" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="'.$alignment.'" vertical="top" wrapText="1"/></xf>';
            }
        }
        foreach (range(4, 9) as $font) {
            $styles[] = '<xf numFmtId="0" fontId="'.$font.'" fillId="'.($font + 2).'" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="'.count($formats).'">'.$numberFormats.'</numFmts><fonts count="'.count($fonts).'">'.implode('', $fonts).'</fonts><fills count="'.count($fills).'">'.implode('', $fills).'</fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FFE5E7EB"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="'.count($styles).'">'.implode('', $styles).'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
    }
}
