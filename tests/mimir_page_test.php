<?php
/**
 * Mímir-entitypagina's moeten allemaal binnenkomen: nextLink, of een
 * vervolgquery als een antwoord exact op het 2000-plafond zit.
 * Run: php tests/mimir_page_test.php
 */

$mimirApi = 'mimir_page_test_key';
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

require dirname(__DIR__) . '/web/voortgang_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

/**
 * @return list<array<string, string>>
 */
function page_rows(int $start, int $count): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $n = $start + $i;
        $rows[] = [
            'No' => sprintf('WO%06d', $n),
            'Contract_No' => 'CT' . (string) $n,
        ];
    }

    return $rows;
}

$requests = [];
$GLOBALS['LACHESIS_MIMIR_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$requests): array {
    $requests[] = [
        'method' => $method,
        'path' => $path,
        'body' => $body,
    ];
    $script = $GLOBALS['mimir_page_script'] ?? [];
    $step = $script[$GLOBALS['mimir_page_step'] ?? 0] ?? null;
    $GLOBALS['mimir_page_step'] = (int) ($GLOBALS['mimir_page_step'] ?? 0) + 1;
    if (!is_array($step)) {
        fail('onverwacht Mímir-verzoek ' . $method . ' ' . $path . ' body=' . json_encode($body));
    }

    return $step;
};

function reset_pages(array $script): void
{
    global $requests;
    $requests = [];
    $GLOBALS['mimir_page_script'] = $script;
    $GLOBALS['mimir_page_step'] = 0;
}

function assert_same(string $label, $expected, $actual): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . json_encode($expected) . ' kreeg ' . json_encode($actual));
    }
}

if (voortgang_mimir_cursor_field('AppWerkorders') !== 'No') {
    fail('AppWerkorders-cursor moet No zijn');
}
if (voortgang_mimir_cursor_field('Projecten') !== 'No') {
    fail('Projecten-cursor moet No zijn');
}
if (voortgang_mimir_cursor_field('Onderhoudscontract') !== 'Contract_No') {
    fail('Onderhoudscontract-cursor moet Contract_No zijn');
}
if (voortgang_mimir_cursor_field('SalesInvoiceSubform') !== '') {
    fail('SalesInvoiceSubform heeft geen unieke cursor');
}

$cap = LACHESIS_MIMIR_PAGE_CAP;
reset_pages([
    ['value' => page_rows(1, $cap)],
    ['value' => page_rows($cap + 1, $cap)],
    ['value' => page_rows(($cap * 2) + 1, 19)],
]);

$seen = [];
$collected = odata_mimir_collect_pages(
    'Koninklijke van Twist',
    'AppWerkorders',
    ['$select' => 'No,Contract_No', '$filter' => "Contract_No ne ''"],
    14400,
    'No',
    static function (array $row) use (&$seen): bool {
        $no = (string) ($row['No'] ?? '');
        if (isset($seen[$no])) {
            fail('dubbele rij ' . $no);
        }
        $seen[$no] = true;

        return str_starts_with((string) ($row['Contract_No'] ?? ''), 'CT');
    }
);
assert_same('multi-page read', ($cap * 2) + 19, $collected['read']);
assert_same('multi-page kept', ($cap * 2) + 19, $collected['kept']);
assert_same('multi-page pages', 3, $collected['pages']);
assert_same('multi-page capped', false, $collected['capped']);
assert_same('verzoeken', 3, count($requests));
foreach ($requests as $request) {
    assert_same('methode', 'POST', $request['method']);
    assert_same('top blijft 0', 0, $request['body']['top'] ?? null);
}
$filter2 = (string) ($requests[1]['body']['filter'] ?? '');
$filter3 = (string) ($requests[2]['body']['filter'] ?? '');
if (strpos($filter2, "Contract_No ne ''") === false || strpos($filter2, "No gt 'WO002000'") === false) {
    fail('tweede filter mist de cursor: ' . $filter2);
}
if (strpos($filter3, "No gt 'WO004000'") === false) {
    fail('derde filter mist de cursor: ' . $filter3);
}

reset_pages([
    ['value' => page_rows(1, $cap + 1)],
]);
$collected = odata_mimir_collect_pages(
    'Koninklijke van Twist',
    'AppWerkorders',
    ['$select' => 'No', '$filter' => "Contract_No ne ''"],
    60,
    'No',
    static function (array $row): bool {
        return true;
    }
);
assert_same('boven plafond read', $cap + 1, $collected['read']);
assert_same('boven plafond pages', 1, $collected['pages']);
assert_same('boven plafond verzoeken', 1, count($requests));

reset_pages([
    ['value' => page_rows(1, $cap)],
    ['value' => []],
]);
$collected = odata_mimir_collect_pages(
    'Hunter van Twist',
    'AppWerkorders',
    ['$select' => 'No'],
    60,
    'No',
    static function (array $row): bool {
        return true;
    }
);
assert_same('exact plafond read', $cap, $collected['read']);
assert_same('exact plafond pages', 1, $collected['pages']);
assert_same('exact plafond verzoeken', 2, count($requests));

$called = 0;
reset_pages([
    ['value' => page_rows(1, $cap)],
]);
$collected = odata_mimir_collect_pages(
    'Koninklijke van Twist',
    'SalesInvoiceSubform',
    ['$select' => 'Document_No', '$filter' => "Document_Type eq 'Factuur'"],
    60,
    '',
    static function (array $row) use (&$called): bool {
        $called++;

        return true;
    }
);
assert_same('zonder cursor capped', true, $collected['capped']);
assert_same('zonder cursor geen emit', 0, $called);
assert_same('zonder cursor read', 0, $collected['read']);

reset_pages([
    [
        'value' => [
            ['No' => 'WO000001'],
            ['No' => 'WO000002'],
        ],
        '@odata.nextLink' => 'https://sleutels.kvt.nl/mimir/api/query.php?cursor=2',
    ],
    [
        'value' => [
            ['No' => 'WO000003'],
        ],
    ],
]);
$collected = odata_mimir_collect_pages(
    'Koninklijke van Twist',
    'AppWerkorders',
    ['$select' => 'No'],
    60,
    'No',
    static function (array $row): bool {
        return true;
    }
);
assert_same('nextLink read', 3, $collected['read']);
assert_same('nextLink pages', 2, $collected['pages']);
assert_same('nextLink eerste methode', 'POST', $requests[0]['method']);
assert_same('nextLink vervolg', 'GET', $requests[1]['method']);
assert_same('nextLink pad', 'query.php?cursor=2', $requests[1]['path']);

reset_pages([
    [
        'value' => page_rows(1, $cap),
        '@odata.nextLink' => 'https://bc.example/ODataV4/Company(\'X\')/AppWerkorders?$skiptoken=2',
    ],
    ['value' => page_rows($cap + 1, 5)],
]);
$collected = odata_mimir_collect_pages(
    'Koninklijke van Twist',
    'AppWerkorders',
    ['$select' => 'No', '$filter' => "Contract_No ne ''"],
    60,
    'No',
    static function (array $row): bool {
        return true;
    }
);
assert_same('BC-link niet volgen read', $cap + 5, $collected['read']);
assert_same('BC-link niet volgen pages', 2, $collected['pages']);
assert_same('BC-link blijft POST', 'POST', $requests[1]['method']);
if (strpos((string) ($requests[1]['body']['filter'] ?? ''), "No gt 'WO002000'") === false) {
    fail('BC-nextLink moet op een cursorfilter uitkomen: ' . json_encode($requests[1]));
}

reset_pages([
    ['value' => page_rows(1, $cap)],
    ['value' => page_rows($cap + 1, $cap)],
    ['value' => page_rows(($cap * 2) + 1, 19)],
]);
$stats = voortgang_paginate_entity(
    'Koninklijke van Twist',
    'AppWerkorders',
    ['$select' => 'No,Contract_No', '$filter' => "Contract_No ne ''"],
    static function (array $row): bool {
        return true;
    }
);
assert_same('voortgang read', ($cap * 2) + 19, $stats['read']);
assert_same('voortgang kept', ($cap * 2) + 19, $stats['kept']);
assert_same('voortgang pages', 3, $stats['pages']);

echo "OK\n";
