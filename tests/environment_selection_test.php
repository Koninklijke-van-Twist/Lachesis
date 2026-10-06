<?php
/**
 * Gesplitste credentials zoals op prod: $auth_list bevat test-environments vóór
 * kvtmdlive_aad, en $environment is een array. Geen enkel pad mag dan de eerste
 * $auth_list-sleutel (kvtfat_aad) kiezen; daar staan Onderhoudscontract,
 * SalesInvoiceSubform en Projecten niet gepubliceerd (HTTP 404, lege body).
 * Run: php tests/environment_selection_test.php
 */

ini_set('log_errors', '0');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$bc = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [
    'kvtfat_aad' => $bc,
    'kvtfat2_aad' => $bc,
    'kvtmdlive_aad' => $bc,
    'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'deuser', 'pass' => 'de-secret'],
];
$environment = ['kvtmdlive_aad', 'kvtgermanylive_aad'];
$primaryEnvironment = $environment[0];
$auth = $auth_list[$primaryEnvironment];
$baseUrl = 'https://bc.example:7148/';
$allowedUsers = ['test@kvt.nl'];

$calls = [];
$GLOBALS['LACHESIS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = $url;
    if (preg_match('#/kvtgermanylive_aad/ODataV4/Compan(?:y|ies)(?:\\?|$)#', $url) === 1) {
        return [['Name' => 'KVT Germany GmbH']];
    }
    if (preg_match('#/([^/]+)/ODataV4/Compan(?:y|ies)(?:\\?|$)#', $url) === 1) {
        return [['Name' => 'Hunter van Twist'], ['Name' => 'Koninklijke van Twist'], ['Name' => 'KVT Gas']];
    }
    return [];
};

require dirname(__DIR__) . '/web/voortgang_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$expected = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

if ($GLOBALS['environment'] !== $expected) {
    fail('Mímir-placeholder mag een $environment-array niet overschrijven: ' . json_encode($GLOBALS['environment']));
}
if (odata_configured_environments() !== $expected) {
    fail('geconfigureerde environments: ' . json_encode(odata_configured_environments()));
}
if (odata_bc_environment() !== 'kvtmdlive_aad') {
    fail('odata_bc_environment hoort kvtmdlive_aad te zijn, niet ' . var_export(odata_bc_environment(), true));
}
if (odata_bc_environment_list() !== $expected) {
    fail('odata_bc_environment_list hoort alleen $environment te volgen: ' . json_encode(odata_bc_environment_list()));
}
if (auth_get_active_environments() !== $expected) {
    fail('auth_get_active_environments: ' . json_encode(auth_get_active_environments()));
}
if (auth_get_primary_environment() !== 'kvtmdlive_aad') {
    fail('primaire environment: ' . auth_get_primary_environment());
}

// Mímir uitgevallen: directe fallback bouwt URL's voor kvtmdlive_aad.
odata_mimir_trip(new Exception('Mímir cURL error: Operation timed out after 90000 milliseconds'));
foreach (['Koninklijke van Twist', 'Hunter van Twist', 'KVT Gas'] as $company) {
    $ctx = voortgang_bc_auth($company);
    if ($ctx['environment'] !== 'kvtmdlive_aad') {
        fail($company . ' hoort op kvtmdlive_aad te landen, niet ' . $ctx['environment']);
    }
    $url = bc_company_entity_url($ctx['baseUrl'], $ctx['environment'], $company, VOORTGANG_PROFORMA_ENTITY, []);
    if (strpos($url, 'https://bc.example:7148/kvtmdlive_aad/ODataV4/') !== 0) {
        fail('directe URL: ' . $url);
    }
    if (($ctx['auth']['user'] ?? '') !== 'bcuser') {
        fail('auth voor ' . $company . ' hoort uit $auth_list[kvtmdlive_aad] te komen');
    }
}
$ctx = voortgang_bc_auth('KVT Germany GmbH');
if ($ctx['environment'] !== 'kvtgermanylive_aad' || ($ctx['auth']['user'] ?? '') !== 'deuser') {
    fail('KVT Germany hoort kvtgermanylive_aad met de Duitse credentials te gebruiken');
}

// Na een company-context (die $environment een string maakt) blijven beide environments actief.
if (auth_get_active_environments() !== $expected) {
    fail('actieve environments na company-context: ' . json_encode(auth_get_active_environments()));
}

foreach ($calls as $url) {
    if (stripos($url, '/kvtfat') !== false) {
        fail('test-environment aangeroepen: ' . $url);
    }
}
if ($calls === []) {
    fail('directe discovery is niet aangeroepen');
}

// Geen code kiest een environment via de eerste $auth_list-sleutel.
foreach (glob(dirname(__DIR__) . '/web/*.php') as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/array_key_first\\(\\s*\\$auth_list|reset\\(\\s*\\$auth_list|\\$known\\[0\\]/', $source) === 1) {
        fail('eerste-sleutel-keuze uit $auth_list in ' . basename($file));
    }
}

echo "OK\n";
