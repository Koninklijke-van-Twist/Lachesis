<?php

declare(strict_types=1);

// Kostengrens en klantnummer-als-tekst. Geen live BC en geen CT26000414-cijfers.
$_SESSION['lang'] = 'nl';
require dirname(__DIR__) . '/web/voortgang_data.php';
require dirname(__DIR__) . '/web/localization.php';
require dirname(__DIR__) . '/web/voortgang_xlsx.php';

function scope_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$amounts = voortgang_amounts_from_contract_row([
    'KVT_Total_Sales_Price' => 100,
    'KVT_Total_Revenue' => -40.5,
    'KVT_Total_Cost' => -2.25,
]);
if (abs($amounts['total_sales'] - 100.0) > 0.001 || abs($amounts['total_revenue'] - 40.5) > 0.001) {
    scope_fail('verkoopprijs en gefactureerd teken kloppen niet');
}
if (abs($amounts['total_cost'] - (-2.25)) > 0.001) {
    scope_fail('KVT_Total_Cost mag niet van teken wisselen');
}

$header = [];
voortgang_fill_contract_header($header, [
    'Description' => 'Synthetisch contract',
    'Invoice_Period' => 'Jaar',
    'KVT_Total_Sales_Price' => 10,
    'KVT_Total_Revenue' => 0,
    'KVT_Total_Cost' => 7.5,
]);
if (($header['description'] ?? '') !== 'Synthetisch contract' || abs((float) $header['total_cost'] - 7.5) > 0.001) {
    scope_fail('contractkop moet het flowfield overnemen');
}
if (array_key_exists('customer_no', $header) || array_key_exists('customer_name', $header)) {
    scope_fail('geen klantvelden verzinnen op de contractkop');
}
if (voortgang_customer_no_text('00123') !== '00123' || voortgang_customer_no_text(123.0) !== '') {
    scope_fail('klantnummer moet tekst blijven; een float heeft geen voorloopnullen');
}

$usage = voortgang_sum_confirmed_usage_costs([
    ['entry_no' => '1', 'workorder' => 'WO-SYN-1', 'component' => 'materiaal', 'amount' => 100.0, 'in_flowfield' => false],
    ['entry_no' => '2', 'workorder' => 'WO-SYN-1', 'component' => 'uren', 'amount' => 40.0, 'in_flowfield' => false],
    ['entry_no' => '3', 'workorder' => 'WO-SYN-1', 'component' => 'km', 'amount' => 10.5, 'in_flowfield' => false],
    ['entry_no' => '4', 'workorder' => 'WO-SYN-1', 'component' => 'materiaal', 'amount' => -25.0, 'in_flowfield' => false],
    ['entry_no' => '5', 'workorder' => 'WO-SYN-1', 'component' => 'uren', 'amount' => 80.0, 'in_flowfield' => true],
    ['entry_no' => '6', 'workorder' => 'WO-SYN-2', 'component' => 'km', 'amount' => 999.0, 'in_flowfield' => false],
    ['entry_no' => '1', 'workorder' => 'WO-SYN-1', 'component' => 'materiaal', 'amount' => 100.0, 'in_flowfield' => false],
], ['WO-SYN-1']);
if (!empty($usage['incomplete']) || abs($usage['extra'] - 125.5) > 0.001) {
    scope_fail('materiaal+uren+km minus credit, zonder flowfield- of ander-contractregel, verwacht 125.50');
}
$withBase = voortgang_total_cost_if_complete(20.0, $usage);
if ($withBase === null || abs($withBase - 145.5) > 0.001) {
    scope_fail('compleet totaal is flowfield plus extra');
}
$onlyFlow = voortgang_sum_confirmed_usage_costs([
    ['entry_no' => '8', 'workorder' => 'WO-SYN-1', 'component' => 'uren', 'amount' => 80.0, 'in_flowfield' => true],
], ['WO-SYN-1']);
$onlyFlowTotal = voortgang_total_cost_if_complete(80.0, $onlyFlow);
if (!empty($onlyFlow['incomplete']) || abs($onlyFlow['extra']) > 0.001 || $onlyFlowTotal === null || abs($onlyFlowTotal - 80.0) > 0.001) {
    scope_fail('wat al in KVT_Total_Cost zit wordt niet nog eens opgeteld');
}

$gap = voortgang_sum_confirmed_usage_costs([
    ['entry_no' => '10', 'workorder' => 'WO-SYN-1', 'component' => 'materiaal', 'amount' => 10.0, 'in_flowfield' => false],
    ['entry_no' => '11', 'workorder' => 'WO-SYN-1', 'component' => 'overig', 'amount' => 50.0, 'in_flowfield' => false],
    ['entry_no' => '12', 'workorder' => 'WO-SYN-1', 'component' => 'uren', 'amount' => null, 'in_flowfield' => false],
    ['entry_no' => '', 'workorder' => 'WO-SYN-1', 'component' => 'km', 'amount' => 3.0, 'in_flowfield' => false],
    ['workorder' => '', 'component' => 'materiaal', 'amount' => 4.0, 'in_flowfield' => false],
], ['WO-SYN-1']);
if (empty($gap['incomplete']) || abs($gap['extra'] - 10.0) > 0.001) {
    scope_fail('onbekende of bedragloze regel mag niet als 0 verdwijnen');
}
if (voortgang_total_cost_if_complete(20.0, $gap) !== null) {
    scope_fail('onvolledige som mag het flowfield niet vervangen');
}

$conflict = voortgang_sum_confirmed_usage_costs([
    ['entry_no' => '7', 'workorder' => 'WO-SYN-1', 'component' => 'km', 'amount' => 5.0, 'in_flowfield' => false],
    ['entry_no' => '7', 'workorder' => 'WO-SYN-1', 'component' => 'km', 'amount' => 9.0, 'in_flowfield' => false],
], ['WO-SYN-1']);
if (empty($conflict['incomplete']) || abs($conflict['extra'] - 5.0) > 0.001) {
    scope_fail('zelfde post met een ander bedrag mag niet dubbel tellen');
}

$rows = [
    'SYN-CT' => [
        'contract_no' => 'SYN-CT',
        'total_revenue' => 10.0,
        'total_cost' => 33.0,
        'total_sales' => 50.0,
        'workorders' => [
            ['no' => 'WO-SYN-1', 'status' => 'Open', 'task_code' => 'OH', 'start_date' => '2026-01-15', 'proforma_amount' => 4],
            ['no' => 'WO-SYN-9', 'status' => 'Open', 'task_code' => 'PD', 'start_date' => '2026-08-01', 'proforma_amount' => 6],
        ],
    ],
];
voortgang_apply_unlinked_revenue_to_rows($rows, ['WO-SYN-1' => 15.0, 'WO-ANDERS' => 1000.0]);
if (abs($rows['SYN-CT']['total_revenue'] - 25.0) > 0.001 || abs($rows['SYN-CT']['total_cost'] - 33.0) > 0.001) {
    scope_fail('losse verkoopposten verhogen alleen gefactureerd');
}
$filtered = voortgang_apply_filters_to_rows($rows, true, '2026-01-01', '2026-03-01');
if (count($filtered) !== 1 || abs((float) $filtered[0]['total_cost'] - 33.0) > 0.001 || abs((float) $filtered[0]['total_revenue'] - 25.0) > 0.001) {
    scope_fail('datum- en PD-filter mogen kosten en omzet niet herberekenen');
}
if (abs((float) $filtered[0]['open_proforma'] - 4.0) > 0.001 || (int) $filtered[0]['total'] !== 1) {
    scope_fail('filter hoort nog wel werkorders en proforma te beperken');
}

$headers = voortgang_xlsx_headers();
$exported = voortgang_xlsx_export_row([
    'contract_no' => 'SYN-CT',
    'description' => 'Synthetisch',
    'invoice_period' => 'Jaar',
    'counts' => ['Open' => 1],
    'total' => 1,
    'progress' => 0,
    'total_sales' => 50,
    'total_revenue' => 25,
    'total_cost' => 33,
    'open_proforma' => 4,
    'instructions' => '',
]);
if (count($headers) !== count($exported)) {
    scope_fail('exportkolommen lopen niet gelijk met de kop');
}
$costIndex = array_search('Totale Kosten', $headers, true);
$revenueIndex = array_search('Gefactureerd bedrag', $headers, true);
if ($costIndex === false || $exported[$costIndex] !== 33.0 || $exported[$revenueIndex] !== 25.0) {
    scope_fail('excel houdt kosten en gefactureerd op de bestaande kolommen');
}
$customerCell = voortgang_xlsx_cell_xml('B2', voortgang_customer_no_text('00123'));
if (!str_contains($customerCell, 't="inlineStr"') || !str_contains($customerCell, '00123')) {
    scope_fail('klantnummer in excel moet tekst blijven');
}

$src = (string) file_get_contents(dirname(__DIR__) . '/web/voortgang_data.php');
if (substr_count($src, 'voortgang_sum_confirmed_usage_costs') !== 1 || substr_count($src, 'voortgang_total_cost_if_complete') !== 1) {
    scope_fail('kostensom mag total_cost nog niet vullen');
}
if (!str_contains($src, "LVS_Contract_No eq ''") || !str_contains(VOORTGANG_JOB_LEDGER_SELECT, 'Line_Amount_LCY')) {
    scope_fail('verkoopposten zonder contractnummer moeten blijven zoals in de opbrengstlogica');
}
foreach (['Total_Cost', 'Customer_No', 'Sell_to', 'Bill_to'] as $field) {
    if (str_contains(VOORTGANG_CONTRACTS_SELECT, $field) && $field !== 'Total_Cost') {
        scope_fail('onbevestigd klantveld in de contractselect');
    }
    if (str_contains(VOORTGANG_JOB_LEDGER_SELECT, $field)) {
        scope_fail('onbevestigd kostveld in de projectpostselect');
    }
}
if (!str_contains(VOORTGANG_CONTRACTS_SELECT, 'KVT_Total_Cost')) {
    scope_fail('KVT_Total_Cost hoort in de contractselect te blijven');
}

$binary = voortgang_build_excel_xlsx([[
    'contract_no' => 'SYN-CT',
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
$zip->close();
@unlink($tmp);
if (substr_count($workbook, '<sheet ') !== 2 || !str_contains($workbook, 'Contractvoortgang') || !str_contains($workbook, 'Werkorderregels')) {
    scope_fail('export hoort twee bestaande werkbladen te houden');
}

echo "OK contract_cost_scope_test\n";
