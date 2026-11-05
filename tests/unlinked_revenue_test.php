<?php

declare(strict_types=1);

// Verkoop-projectposten zonder LVS_Contract_No tellen mee in het gefactureerd bedrag (CTR2600010).
require dirname(__DIR__) . '/web/voortgang_data.php';

function unlinked_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$rows = [
    'CTR2600010' => ['total_revenue' => 0.0, 'workorders' => [['no' => 'WO2600397'], ['no' => 'WO2600398']]],
    'CTR2600063' => ['total_revenue' => 8964.5, 'workorders' => [['no' => 'WO2600374']]],
];
voortgang_apply_unlinked_revenue_to_rows($rows, ['WO2600397' => 22919.0]);

if (abs($rows['CTR2600010']['total_revenue'] - 22919.0) > 0.001) {
    unlinked_fail('CTR2600010 moet 22919 gefactureerd tonen');
}
if (abs($rows['CTR2600063']['total_revenue'] - 8964.5) > 0.001) {
    unlinked_fail('CTR2600063 mag niet dubbel tellen');
}
if (voortgang_mimir_cursor_field(VOORTGANG_JOB_LEDGER_ENTITY) !== 'Entry_No') {
    unlinked_fail('ProjectPosten moet Entry_No als cursor hebben');
}

echo "OK unlinked_revenue_test\n";
