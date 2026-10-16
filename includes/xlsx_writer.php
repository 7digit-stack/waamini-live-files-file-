<?php
/**
 * Minimal Excel (.xlsx) writer with no extensions needed (no ZipArchive): builds the zip by hand (stored entries).
 * Every cell is written as text, so phone numbers like +919003876516 never turn into 9.19E+11,
 * Tamil/emoji names keep their characters, the header row is bold, frozen and filterable, and columns have widths.
 *
 *   xlsx_download('contacts.xlsx', 'Contacts', ['Name', 'Phone'], [['Ravi', '+919...']], [24, 18]);
 */

if (!function_exists('xlsx_build')) {

    function xlsx_col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) { $s = chr(65 + ($n - 1) % 26) . $s; }
        return $s;
    }

    function xlsx_esc(string $v): string
    {
        // strip characters that are illegal in XML 1.0
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v) ?? '';
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return string the .xlsx file bytes */
    function xlsx_build(string $sheetName, array $headers, array $rows, array $widths = []): string
    {
        $sheetName = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheetName) ?: 'Sheet1', 0, 31);
        $cols = count($headers);
        $last = xlsx_col(max(0, $cols - 1));
        $total = count($rows) + 1;

        $colXml = '';
        for ($i = 0; $i < $cols; $i++) {
            $w = (float)($widths[$i] ?? 18);
            $colXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $data = '<row r="1">';
        foreach (array_values($headers) as $i => $h) {
            $data .= '<c r="' . xlsx_col($i) . '1" t="inlineStr" s="1"><is><t>' . xlsx_esc((string)$h) . '</t></is></c>';
        }
        $data .= '</row>';
        foreach (array_values($rows) as $r => $row) {
            $rn = $r + 2;
            $data .= '<row r="' . $rn . '">';
            foreach (array_values($row) as $i => $v) {
                $v = (string)($v ?? '');
                if ($v === '') { continue; }
                $data .= '<c r="' . xlsx_col($i) . $rn . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
            }
            $data .= '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $last . $total . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<cols>' . $colXml . '</cols><sheetData>' . $data . '</sheetData>'
            . ($total > 1 ? '<autoFilter ref="A1:' . $last . $total . '"/>' : '')
            . '</worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF128C7E"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"><alignment vertical="center"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="center"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . xlsx_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
                . ($total > 1 ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . xlsx_esc(str_replace("'", "''", $sheetName)) . '\'!$A$1:$' . $last . '$' . $total . '</definedName></definedNames>' : '')
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        return xlsx_zip($files);
    }

    /** Zip archive with stored (uncompressed) entries. */
    function xlsx_zip(array $files): string
    {
        $out = ''; $central = ''; $n = 0;
        $time = ((int)date('H') << 11) | ((int)date('i') << 5) | intdiv((int)date('s'), 2);
        $date = (((int)date('Y') - 1980) << 9) | ((int)date('n') << 5) | (int)date('j');
        foreach ($files as $name => $body) {
            $crc = crc32($body); $len = strlen($body); $off = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name . $body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $n++;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
    }

    function xlsx_download(string $filename, string $sheetName, array $headers, array $rows, array $widths = []): void
    {
        $bin = xlsx_build($sheetName, $headers, $rows, $widths);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }

    /** CSV that Excel opens correctly: UTF-8 BOM, numbers kept as text with ="...". */
    function csv_excel_download(string $filename, array $headers, array $rows, array $textCols = []): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Cache-Control: no-store');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, $headers);
        foreach ($rows as $row) {
            $row = array_values($row);
            foreach ($row as $i => $v) {
                $v = (string)($v ?? '');
                if (in_array($i, $textCols, true) && $v !== '') { $row[$i] = '="' . str_replace('"', '', $v) . '"'; continue; }
                // no spreadsheet formulas from user data
                if ($v !== '' && strpos('=+-@', $v[0]) !== false) { $v = "'" . $v; }
                $row[$i] = $v;
            }
            fputcsv($o, $row);
        }
        fclose($o);
    }
}
