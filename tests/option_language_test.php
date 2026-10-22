<?php

declare(strict_types=1);

// Taalonafhankelijke BC-optiewaarden (BC-gebruiker NL, EN of DE).
require dirname(__DIR__) . '/web/voortgang_data.php';

function option_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

foreach (VOORTGANG_STATUSES as $status) {
    if (voortgang_canonical_status($status) !== $status) {
        option_fail("NL-status {$status} moet ongewijzigd blijven");
    }
}

$cases = [
    'In Progress' => 'Onderhanden',
    '02 IN PROGRESS' => 'Onderhanden',
    'in_progress' => 'Onderhanden',
    'Completed' => 'Uitgevoerd',
    'Signed' => 'Ondertekend',
    'Checked' => 'Gecontroleerd',
    'Invoiced' => 'Gefactureerd',
    'Cancelled' => 'Geannuleerd',
    'Closed' => 'Afgesloten',
    'Planned' => 'Gepland',
    'OPEN' => 'Open',
    '01 Open' => 'Open',
    'Geschlossen' => 'Afgesloten',
    'Storniert' => 'Geannuleerd',
    'Iets Anders' => 'Iets Anders',
    '' => '',
];
foreach ($cases as $input => $expected) {
    $actual = voortgang_canonical_status((string) $input);
    if ($actual !== $expected) {
        option_fail("status '{$input}' gaf '{$actual}', verwacht '{$expected}'");
    }
}

$counts = voortgang_empty_counts();
foreach (['Checked', 'Gecontroleerd', '07 CANCELLED', 'Open'] as $status) {
    $item = voortgang_normalize_workorder_item(['no' => 'W' . $status, 'status' => $status]);
    $counts[$item['status']]++;
}
if (voortgang_progress_percent($counts, 4) !== 75.0) {
    option_fail('voortgang moet Engelse statussen meetellen');
}

foreach (['Factuur', 'Invoice', 'Rechnung', 'INVOICE'] as $type) {
    if (!voortgang_is_proforma_document_type(['Document_Type' => $type])) {
        option_fail("Document_Type {$type} is een factuur");
    }
}
foreach (['Credit Memo', 'Creditnota', 'Order', 'Quote', ''] as $type) {
    if (voortgang_is_proforma_document_type(['Document_Type' => $type])) {
        option_fail("Document_Type {$type} is geen factuur");
    }
}

echo "OK option_language_test\n";
