<?php

/**
 * Minimal .xlsx writer with an Excel table (headers + autofilter).
 */

function voortgang_xlsx_column_letter(int $columnNumber): string
{
    $columnNumber = max(1, $columnNumber);
    $letters = '';
    while ($columnNumber > 0) {
        $columnNumber--;
        $letters = chr(65 + ($columnNumber % 26)) . $letters;
        $columnNumber = intdiv($columnNumber, 26);
    }

    return $letters;
}

function voortgang_xlsx_cell_ref(int $rowNumber, int $columnNumber): string
{
    return voortgang_xlsx_column_letter($columnNumber) . max(1, $rowNumber);
}

function voortgang_xlsx_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function voortgang_xlsx_sanitize_text(string $value): string
{
    return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
}

function voortgang_xlsx_cell_xml(string $cellRef, mixed $value): string
{
    if (is_int($value)) {
        return '<c r="' . $cellRef . '"><v>' . (string) $value . '</v></c>';
    }

    if (is_float($value)) {
        if (!is_finite($value)) {
            $value = 0.0;
        }

        return '<c r="' . $cellRef . '"><v>' . rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') . '</v></c>';
    }

    $text = voortgang_xlsx_sanitize_text((string) $value);
    if ($text === '') {
        return '';
    }

    return '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">'
        . voortgang_xlsx_xml_escape($text) . '</t></is></c>';
}

function voortgang_xlsx_headers(): array
{
    $headers = [
        LOC('voortgang.col.contract_no'),
        LOC('voortgang.col.description'),
        LOC('voortgang.col.invoice_period'),
    ];
    foreach (VOORTGANG_STATUSES as $status) {
        $headers[] = $status;
    }

    $headers[] = LOC('voortgang.col.total');
    $headers[] = LOC('voortgang.col.progress');
    $headers[] = LOC('voortgang.col.original_amount');
    $headers[] = LOC('voortgang.col.invoiced_amount');
    $headers[] = LOC('voortgang.col.total_cost');
    $headers[] = LOC('voortgang.col.open_proforma');
    $headers[] = LOC('voortgang.col.instructions');

    return $headers;
}

function voortgang_xlsx_export_row(array $row): array
{
    $values = [
        (string) ($row['contract_no'] ?? ''),
        (string) ($row['description'] ?? ''),
        (string) ($row['invoice_period'] ?? ''),
    ];
    $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
    foreach (VOORTGANG_STATUSES as $status) {
        $values[] = (int) ($counts[$status] ?? 0);
    }

    $values[] = (int) ($row['total'] ?? 0);
    $values[] = (float) ($row['progress'] ?? 0);
    $values[] = round((float) ($row['total_sales'] ?? 0), 2);
    $revenue = (float) ($row['total_revenue'] ?? 0);
    $values[] = round($revenue < 0 ? -$revenue : $revenue, 2);
    $values[] = round((float) ($row['total_cost'] ?? 0), 2);
    $values[] = round((float) ($row['open_proforma'] ?? 0), 2);
    $values[] = (string) ($row['instructions'] ?? '');

    return $values;
}

function voortgang_xlsx_table_name(string $name): string
{
    $name = (string) preg_replace('/[^A-Za-z0-9_]/', '', voortgang_xlsx_sanitize_text($name));
    if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
        $name = 'Tabel' . $name;
    }

    return substr($name, 0, 60);
}

function voortgang_xlsx_sheet_name(string $name): string
{
    $name = trim((string) preg_replace('#[\\\\/\?\*\[\]:]#', ' ', voortgang_xlsx_sanitize_text($name)));
    if ($name === '') {
        $name = 'Export';
    }

    return mb_substr($name, 0, 31);
}

/**
 * @param list<string> $headers
 * @param list<list<string|int|float>> $rows
 */
function voortgang_build_table_xlsx(array $headers, array $rows, string $sheetName, string $tableName): string
{
    $headers = array_values($headers);
    if ($headers === []) {
        $headers = [''];
    }
    $colCount = count($headers);
    $sheetName = voortgang_xlsx_sheet_name($sheetName);
    $tableName = voortgang_xlsx_table_name($tableName);

    $sheetRows = '<row r="1">';
    foreach ($headers as $index => $header) {
        $sheetRows .= voortgang_xlsx_cell_xml(voortgang_xlsx_cell_ref(1, $index + 1), (string) $header);
    }
    $sheetRows .= '</row>';

    $excelRow = 2;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sheetRows .= '<row r="' . $excelRow . '">';
        $colIndex = 0;
        foreach (array_values($row) as $value) {
            if ($colIndex >= $colCount) {
                break;
            }
            $sheetRows .= voortgang_xlsx_cell_xml(voortgang_xlsx_cell_ref($excelRow, $colIndex + 1), $value);
            $colIndex++;
        }
        $sheetRows .= '</row>';
        $excelRow++;
    }

    $lastDataRow = max(1, $excelRow - 1);
    $tableRef = 'A1:' . voortgang_xlsx_cell_ref($lastDataRow, $colCount);

    $columnsXml = '';
    $usedNames = [];
    foreach ($headers as $index => $header) {
        $name = trim(voortgang_xlsx_sanitize_text((string) $header));
        if ($name === '') {
            $name = 'Kolom' . (string) ($index + 1);
        }
        $unique = $name;
        $suffix = 2;
        while (isset($usedNames[mb_strtolower($unique)])) {
            $unique = $name . ' ' . (string) $suffix;
            $suffix++;
        }
        $usedNames[mb_strtolower($unique)] = true;
        $columnsXml .= '<tableColumn id="' . (string) ($index + 1) . '" name="'
            . voortgang_xlsx_xml_escape($unique) . '"/>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="' . $tableRef . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
        . '</worksheet>';

    $tableXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' id="1" name="' . $tableName . '" displayName="' . $tableName . '" ref="' . $tableRef . '" totalsRowShown="0">'
        . '<autoFilter ref="' . $tableRef . '"/>'
        . '<tableColumns count="' . (string) $colCount . '">' . $columnsXml . '</tableColumns>'
        . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
        . '</table>';

    $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . voortgang_xlsx_xml_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
        . '</Types>';

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is niet beschikbaar voor Excel-export.');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'voortgang_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Kon tijdelijk Excel-bestand niet aanmaken.');
    }

    $zipPath = $tmp . '.xlsx';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Kon Excel-bestand niet schrijven.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);
    $zip->addFromString('xl/tables/table1.xml', $tableXml);
    $zip->close();

    $binary = file_get_contents($zipPath);
    @unlink($zipPath);
    if ($binary === false) {
        throw new RuntimeException('Kon Excel-bestand niet lezen.');
    }

    return $binary;
}

function voortgang_build_excel_xlsx(array $rows): string
{
    $values = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $values[] = voortgang_xlsx_export_row($row);
        }
    }

    return voortgang_build_table_xlsx(
        voortgang_xlsx_headers(),
        $values,
        'Contractvoortgang',
        'ContractVoortgang'
    );
}
