<?php

declare(strict_types=1);

// Synthetische scope: unieke Gebruik-posten, gesigneerd Total_Cost_LCY,
// routes LVS_Contract_No / Job_No / LVS_Work_Order_No. Geen live klant- of transactiedata.
$_SESSION['lang'] = 'nl';
require dirname(__DIR__) . '/web/voortgang_data.php';
require dirname(__DIR__) . '/web/localization.php';
require dirname(__DIR__) . '/web/voortgang_xlsx.php';

function scope_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$projects = ['P-SYN' => ['SYN-CT']];
$workorders = ['WO-SYN' => ['SYN-CT']];
$entries = [
    ['Entry_No' => 1, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 10, 'LVS_Contract_No' => 'SYN-CT', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN', 'Job_Task_No' => 'WO-ANDERE-TAAK'],
    ['Entry_No' => 1, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 10, 'LVS_Contract_No' => 'SYN-CT', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN', 'Job_Task_No' => 'WO-ANDERE-TAAK'],
    ['Entry_No' => 2, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 40, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN', 'Unit_of_Measure_Code' => 'HR'],
    ['Entry_No' => 2, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 40, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN', 'Unit_of_Measure_Code' => 'HR'],
    ['Entry_No' => 3, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 2.5, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN', 'Unit_of_Measure_Code' => 'KM'],
    ['Entry_No' => 4, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => -1.25, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
    ['Entry_No' => 5, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 99, 'LVS_Contract_No' => 'OTHER-CT', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
    ['Entry_No' => 6, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 50, 'LVS_Contract_No' => '', 'Job_No' => '', 'LVS_Work_Order_No' => '', 'Job_Task_No' => 'WO-SYN'],
    ['Entry_No' => 7, 'Entry_Type' => 'Verkoop', 'Total_Cost_LCY' => 80, 'LVS_Contract_No' => 'SYN-CT', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
    ['Entry_No' => 8, 'Entry_Type' => 'Usage', 'Total_Cost_LCY' => 1, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
    ['Entry_No' => 9, 'Entry_Type' => '02 Verbrauch', 'Total_Cost_LCY' => 0.25, 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
];
$buckets = voortgang_usage_costs_by_contract($entries, $projects, $workorders);
if (!empty($buckets['SYN-CT']['incomplete']) || abs($buckets['SYN-CT']['total'] - 52.5) > 0.001) {
    scope_fail('materiaal+uren+km+credit moet 52.50 zijn, zonder dubbele routes of andermans contract');
}
if (abs(($buckets['OTHER-CT']['total'] ?? 0) - 99.0) > 0.001) {
    scope_fail('expliciet ander contract houdt zijn eigen post');
}
$rows = [
    'SYN-CT' => [
        'contract_no' => 'SYN-CT',
        'total_cost' => 10.0,
        'total_revenue' => 10.0,
        'total_sales' => 20.0,
        'workorders' => [['no' => 'WO-SYN', 'status' => 'Open', 'task_code' => 'OH', 'start_date' => '2026-01-15', 'proforma_amount' => 4]],
    ],
];
voortgang_apply_usage_costs_to_rows($rows, $buckets);
if (abs((float) $rows['SYN-CT']['total_cost'] - 52.5) > 0.001) {
    scope_fail('KVT_Total_Cost mag niet bovenop de postensom');
}
voortgang_apply_unlinked_revenue_to_rows($rows, ['WO-SYN' => 15.0, 'WO-ANDERS' => 1000.0]);
if (abs($rows['SYN-CT']['total_revenue'] - 25.0) > 0.001 || abs((float) $rows['SYN-CT']['total_cost'] - 52.5) > 0.001) {
    scope_fail('losse verkoopposten verhogen alleen gefactureerd');
}

$gap = voortgang_usage_costs_by_contract([
    ['Entry_No' => 10, 'Entry_Type' => 'Gebruik', 'LVS_Contract_No' => 'SYN-CT', 'Job_No' => 'P-SYN', 'Total_Cost_LCY' => 4],
    ['Entry_No' => 11, 'Entry_Type' => 'Gebruik', 'LVS_Contract_No' => '', 'Job_No' => 'P-SYN', 'LVS_Work_Order_No' => 'WO-SYN'],
], $projects, $workorders);
if (empty($gap['SYN-CT']['incomplete'])) {
    scope_fail('ontbrekend Total_Cost_LCY mag niet als 0 meetellen');
}
$rows['SYN-CT']['total_cost'] = 4.0;
voortgang_apply_usage_costs_to_rows($rows, $gap);
if ($rows['SYN-CT']['total_cost'] !== null) {
    scope_fail('onvolledige som mag niet als bedrag getoond worden');
}

$conflict = voortgang_usage_costs_by_contract([
    ['Entry_No' => 12, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 9, 'LVS_Contract_No' => '', 'Job_No' => 'P-SPLIT', 'LVS_Work_Order_No' => 'WO-SPLIT'],
], ['P-SPLIT' => ['SYN-A']], ['WO-SPLIT' => ['SYN-B']]);
if (empty($conflict['SYN-A']['incomplete']) || empty($conflict['SYN-B']['incomplete']) || abs($conflict['SYN-A']['total']) > 0.001) {
    scope_fail('project en werkorder van verschillende contracten mogen het bedrag niet kiezen');
}

$changed = voortgang_usage_costs_by_contract([
    ['Entry_No' => 13, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 5, 'LVS_Contract_No' => 'SYN-CT'],
    ['Entry_No' => 13, 'Entry_Type' => 'Gebruik', 'Total_Cost_LCY' => 8, 'LVS_Contract_No' => 'SYN-CT'],
], $projects, $workorders);
if (empty($changed['SYN-CT']['incomplete'])) {
    scope_fail('zelfde post met een ander bedrag is geen stille som');
}

$header = [];
voortgang_fill_contract_header($header, [
    'Customer_No' => '00123',
    'Name' => 'Synthetische Klant',
    'Bill_to_Customer_No' => '999',
    'Bill_to_Name' => 'Factuurklant',
    'Description' => 'Synthetisch',
    'Invoice_Period' => 'Jaar',
    'KVT_Total_Sales_Price' => 20,
    'KVT_Total_Revenue' => -5,
    'KVT_Total_Cost' => 10,
]);
if (($header['customer_no'] ?? '') !== '00123' || ($header['customer_name'] ?? '') !== 'Synthetische Klant') {
    scope_fail('contractklant komt uit Customer_No en Name');
}
if (voortgang_customer_no_text(123.0) !== '' || voortgang_customer_no_text(50) !== '50') {
    scope_fail('klantnummer blijft tekst; een float heeft geen voorloopnullen');
}
if (abs($header['total_revenue'] - 5.0) > 0.001 || abs($header['total_cost'] - 10.0) > 0.001) {
    scope_fail('flowfield-teken: omzet omdraaien, kosten nog niet');
}

$filtered = voortgang_apply_filters_to_rows([
    [
        'contract_no' => 'SYN-CT',
        'customer_no' => '00123',
        'total_cost' => 52.5,
        'total_revenue' => 25,
        'workorders' => [
            ['no' => 'WO-SYN', 'status' => 'Open', 'task_code' => 'OH', 'start_date' => '2026-01-15', 'proforma_amount' => 4],
            ['no' => 'WO-PD', 'status' => 'Open', 'task_code' => 'PD', 'start_date' => '2026-08-01', 'proforma_amount' => 6],
        ],
    ],
], true, '2026-01-01', '2026-03-01');
if (count($filtered) !== 1 || abs((float) $filtered[0]['total_cost'] - 52.5) > 0.001 || ($filtered[0]['customer_no'] ?? '') !== '00123') {
    scope_fail('datum- en PD-filter laten kosten en klantnummer staan');
}

$headers = voortgang_xlsx_headers();
$exported = voortgang_xlsx_export_row([
    'contract_no' => 'SYN-CT',
    'customer_no' => '00123',
    'customer_name' => 'Synthetische Klant',
    'description' => 'Synthetisch',
    'invoice_period' => 'Jaar',
    'counts' => ['Open' => 1],
    'total' => 1,
    'progress' => 0,
    'total_sales' => 20,
    'total_revenue' => 25,
    'total_cost' => 52.5,
    'open_proforma' => 4,
    'instructions' => '',
]);
if (count($headers) !== count($exported)) {
    scope_fail('exportkolommen lopen niet gelijk met de kop');
}
if ($headers[1] !== 'Klantnummer' || $headers[2] !== 'Klantnaam' || $exported[1] !== '00123' || $exported[2] !== 'Synthetische Klant') {
    scope_fail('klantkolommen horen direct na het contractnummer');
}
$costIndex = array_search('Totale Kosten', $headers, true);
if ($costIndex === false || abs((float) $exported[$costIndex] - 52.5) > 0.001) {
    scope_fail('excel toont de postensom in Totale Kosten');
}
$blank = voortgang_xlsx_export_row(['contract_no' => 'SYN-CT', 'total_cost' => null, 'counts' => []]);
if ($blank[$costIndex] !== '') {
    scope_fail('onvolledige kosten mogen in excel geen 0 zijn');
}
$customerCell = voortgang_xlsx_cell_xml('B2', $exported[1]);
if (!str_contains($customerCell, 't="inlineStr"') || !str_contains($customerCell, '00123')) {
    scope_fail('klantnummer in excel moet tekst blijven');
}

$claims = voortgang_workorder_claims_from_rows([
    ['contract_no' => 'SYN-CT', 'workorders' => [['no' => 'WO-SYN'], ['no' => 'WO-SYN']]],
]);
if ($claims !== ['WO-SYN' => ['SYN-CT']]) {
    scope_fail('werkorderclaim volgt AppWerkorders.No, niet Job_Task_No');
}

$src = (string) file_get_contents(dirname(__DIR__) . '/web/voortgang_data.php');
if (substr_count($src, 'voortgang_apply_posted_usage_costs(') < 3) {
    scope_fail('geboekte gebruikskosten moeten in nightly en contract-refresh zitten');
}
foreach (['LVS_Contract_No', 'Job_No', 'LVS_Work_Order_No', 'Total_Cost_LCY', 'Entry_No', 'Entry_Type'] as $field) {
    if (!str_contains(VOORTGANG_JOB_LEDGER_COST_SELECT, $field)) {
        scope_fail('kostselect mist ' . $field);
    }
}
if (str_contains(VOORTGANG_JOB_LEDGER_COST_SELECT, 'Job_Task_No') || !str_contains($src, "'LVS_Work_Order_No' =>")) {
    scope_fail('kosten koppelen via LVS_Work_Order_No, niet via Job_Task_No');
}
if (!str_contains($src, "LVS_Contract_No eq ''") || !str_contains(VOORTGANG_JOB_LEDGER_SELECT, 'Line_Amount_LCY')) {
    scope_fail('verkoopposten zonder contractnummer moeten blijven');
}
if (!str_contains(VOORTGANG_CONTRACTS_SELECT, 'Customer_No') || !str_contains(VOORTGANG_CONTRACTS_SELECT, ',Name,')) {
    scope_fail('contractselect moet Customer_No en Name vragen');
}
if (str_contains(VOORTGANG_CONTRACTS_SELECT, 'Bill_to') || str_contains($src, 'KVT_Workorder_Count') || str_contains($src, 'KVT_Sum_Work_Order_Cost') || str_contains($src, 'LVS_Used_Hours') || str_contains(VOORTGANG_JOB_LEDGER_COST_SELECT, 'Posting_Date')) {
    scope_fail('factuurklant, WO-totalen, urenhoeveelheid of een nieuw periodefilter horen er niet in');
}

$binary = voortgang_build_excel_xlsx([[
    'contract_no' => 'SYN-CT',
    'customer_no' => '00123',
    'customer_name' => 'Synthetische Klant',
    'description' => 'Synthetisch',
    'invoice_period' => 'Jaar',
    'counts' => [],
    'total' => 1,
    'progress' => 0,
    'total_sales' => 1,
    'total_revenue' => 1,
    'total_cost' => 1,
    'open_proforma' => 0,
    'instructions' => '',
    'workorders' => [],
]]);
$tmp = tempnam(sys_get_temp_dir(), 'lachesis_xlsx_');
if ($tmp === false) {
    scope_fail('geen tempbestand');
}
file_put_contents($tmp, $binary);
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    scope_fail('excel niet leesbaar');
}
$workbook = (string) $zip->getFromName('xl/workbook.xml');
$sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
$zip->close();
@unlink($tmp);
if (substr_count($workbook, '<sheet ') !== 2 || !str_contains($workbook, 'Contractvoortgang') || !str_contains($workbook, 'Werkorderregels')) {
    scope_fail('export hoort twee bestaande werkbladen te houden');
}
if (!str_contains($sheet, 'Klantnummer') || !str_contains($sheet, '00123')) {
    scope_fail('contractblad mist het klantnummer');
}

echo "OK contract_cost_scope_test\n";
