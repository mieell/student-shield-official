<?php

namespace App\Libraries;

final class SchoolSummaryWorkbook
{
    public static function build(array $report): string
    {
        $f = $report['filters'];
        $summary = [
            [['STUDENTSHIELD | SCHOOL SUMMARY'], 1],
            [[sprintf('Reporting period: %s to %s', $f['start'], $f['end'])], 2],
            [['Report filters', 'Selection'], 3],
            [['Period', ['today' => 'Today', 'yesterday' => 'Yesterday', 'last7' => 'Last 7 days',
                'last30' => 'Last 30 days', 'week' => 'This week', 'month' => 'This month', 'custom' => 'Custom range'][$f['period']]], 0],
            [['Grade level', $f['grade'] ?: 'All grades'], 0],
            [['Section', $f['section'] ?: 'All sections'], 4],
            [['Event type', $f['category'] === 'all' ? 'All event types' : $f['category']], 0],
            [['Risk classification', $f['risk'] === 'all' ? 'All levels' : ucfirst(strtolower($f['risk']))], 4],
            [['School metrics', 'Value'], 3],
        ];
        foreach ([
            'registered' => 'Students registered in period', 'active' => 'Active student accounts registered in period',
            'events' => 'Events in period', 'attention' => 'Students needing counselor attention',
            'today_count' => 'Events today', 'week_count' => 'Events this week', 'month_count' => 'Events this month',
        ] as $key => $label) {
            $summary[] = [[$label, $report['metrics'][$key]], count($summary) % 2 ? 0 : 4];
        }
        $summary[] = [['Previous period events', $report['previous_events']], 4];
        $change = $report['change_percent'];
        $summary[] = [['Change vs previous period', $change === null ? 'No previous baseline' : $change / 100], 0, $change !== null ? 5 : 0];

        $daily = [[['DAILY EVENTS'], 1], [["{$f['start']} to {$f['end']}"], 2], [['Date (YYYY-MM-DD)', 'Events'], 3]];
        foreach ($report['groups']['trend'] as $row) {
            $daily[] = [[$row['label'], $row['total']], count($daily) % 2 ? 0 : 4];
        }
        $groups = [[['EVENT BREAKDOWNS'], 1], [['Counts reflect the selected filters.'], 2], [['Group', 'Label', 'Events'], 3]];
        foreach (['category' => 'Event type', 'risk' => 'Safe Space risk', 'grade' => 'Grade level', 'section' => 'Section'] as $key => $label) {
            foreach ($report['groups'][$key] ?: [['label' => 'No matching events', 'total' => 0]] as $row) {
                $value = $key === 'risk' ? ucfirst(strtolower($row['label'])) : $row['label'];
                $groups[] = [[$label, $value, $row['total']], count($groups) % 2 ? 0 : 4];
            }
        }

        return self::zip([
            '[Content_Types].xml' => self::xml('<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
                implode('', array_map(static fn ($n) => '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', [1, 2, 3])) . '</Types>'),
            '_rels/.rels' => self::relationships(['officeDocument' => ['xl/workbook.xml']]),
            'xl/_rels/workbook.xml.rels' => self::relationships(['worksheet' => ['worksheets/sheet1.xml', 'worksheets/sheet2.xml', 'worksheets/sheet3.xml'], 'styles' => ['styles.xml']]),
            'xl/workbook.xml' => self::xml('<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Daily events" sheetId="2" r:id="rId2"/><sheet name="Breakdowns" sheetId="3" r:id="rId3"/></sheets></workbook>'),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => self::sheet($summary, [58, 32]),
            'xl/worksheets/sheet2.xml' => self::sheet($daily, [34, 18]),
            'xl/worksheets/sheet3.xml' => self::sheet($groups, [26, 48, 16]),
        ]);
    }

    private static function zip(array $files): string
    {
        // XLSX supports stored ZIP entries, so shared hosting needs no ZIP extension or temporary files.
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $offset = strlen($body);
            $size = strlen($content);
            // Version, flags, method, DOS time/date, checksum, sizes, name length, extra length.
            $metadata = pack('vvvvvVVVvv', 20, 0, 0, 0, 33, crc32($content), $size, $size, strlen($name), 0);
            $body .= "PK\x03\x04" . $metadata . $name . $content;
            $directory .= "PK\x01\x02" . pack('v', 20) . $metadata
                . pack('vvvVV', 0, 0, 0, 0, $offset) . $name;
        }
        $count = count($files);
        return $body . $directory . "PK\x05\x06"
            . pack('vvvvVVv', 0, 0, $count, $count, strlen($directory), strlen($body), 0);
    }

    private static function sheet(array $rows, array $widths): string
    {
        $last = chr(64 + count($widths));
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($widths as $i => $width) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        $xml .= '</cols><sheetData>';
        foreach ($rows as $index => $row) {
            [$cells, $style] = $row;
            $number = $index + 1;
            $height = $style === 1 ? 36 : ($style === 3 ? 25 : 23);
            if ($style === 0 || $style === 4) {
                foreach ($cells as $column => $value) {
                    if (is_string($value)) $height = max($height, 16 * (int) ceil(mb_strlen($value) / ($widths[$column] - 3)) + 7);
                }
            }
            $xml .= '<row r="' . $number . '" ht="' . $height . '" customHeight="1">';
            foreach ($cells as $column => $value) {
                $ref = chr(65 + $column) . $number;
                $cellStyle = $column === 1 && isset($row[2]) ? $row[2] : $style;
                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . $cellStyle . '"><v>' . $value . '</v></c>';
                } else {
                    $safe = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED, 'UTF-8');
                    $xml .= '<c r="' . $ref . '" s="' . $cellStyle . '" t="inlineStr"><is><t xml:space="preserve">' . $safe . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return self::xml($xml . '</sheetData><mergeCells count="2"><mergeCell ref="A1:' . $last . '1"/><mergeCell ref="A2:' . $last . '2"/></mergeCells></worksheet>');
    }

    private static function xml(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $body;
    }

    private static function relationships(array $targets): string
    {
        $xml = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $id = 1;
        foreach ($targets as $type => $paths) {
            foreach ($paths as $path) {
                $xml .= '<Relationship Id="rId' . $id++ . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/' . $type . '" Target="' . $path . '"/>';
            }
        }
        return self::xml($xml . '</Relationships>');
    }

    private static function styles(): string
    {
        return self::xml('<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <fonts count="4">
                <font><sz val="11"/><color rgb="FF173A2A"/><name val="Calibri"/></font>
                <font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
                <font><b/><sz val="11"/><color rgb="FF0B5E2E"/><name val="Calibri"/></font>
                <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
            </fonts>
            <fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FF0B5E2E"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFF8E9BE"/><bgColor indexed="64"/></patternFill></fill>
                <fill><patternFill patternType="solid"><fgColor rgb="FFF1F7F3"/><bgColor indexed="64"/></patternFill></fill>
            </fills>
            <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
            <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
            <cellXfs count="6">
                <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
                <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
                <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>
                <xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center"/></xf>
                <xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
                <xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="center"/></xf>
            </cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    }
}
