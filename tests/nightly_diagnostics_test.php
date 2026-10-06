<?php
/**
 * Nightly: Mímir-timeout-override, en foutmeldingen met URL, body en Mímir-oorzaak.
 * Run: php tests/nightly_diagnostics_test.php
 */

ini_set('log_errors', '0');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'kvtmdlive_aad';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = [
    'kvtmdlive_aad' => $auth,
    'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'deuser', 'pass' => 'de-secret'],
];

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

// Timeout: zonder override per SAPI, met override (nightly) altijd de override.
unset($GLOBALS['lachesis_mimir_timeout_override']);
if (odata_mimir_timeout_seconds() !== odata_mimir_timeout_seconds_for_sapi(PHP_SAPI)) {
    fail('zonder override hoort de SAPI-timeout te gelden');
}
$GLOBALS['lachesis_mimir_timeout_override'] = LACHESIS_NIGHTLY_MIMIR_TIMEOUT;
if (odata_mimir_timeout_seconds() !== 600) {
    fail('nightly-override hoort 600s te geven');
}
if (odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout buiten nightly blijft 90s');
}
$GLOBALS['lachesis_mimir_timeout_override'] = 0;
if (odata_mimir_timeout_seconds() !== odata_mimir_timeout_seconds_for_sapi(PHP_SAPI)) {
    fail('override 0 hoort genegeerd te worden');
}
unset($GLOBALS['lachesis_mimir_timeout_override']);

// Body-samenvatting.
if (odata_error_body_summary('') !== '(lege body)' || odata_error_body_summary(false) !== '(lege body)') {
    fail('lege body hoort expliciet benoemd te worden');
}
$long = odata_error_body_summary(str_repeat('x', 2000));
if (strlen($long) > LACHESIS_ODATA_ERROR_BODY_LIMIT + 4) {
    fail('lange body hoort afgekapt te worden');
}

// Fallback na Mímir-fout: directe fout bevat URL + Mímir-oorzaak, zonder secrets.
odata_mimir_circuit_reset();
$url = "https://bc.example:7148/kvtmdlive_aad/ODataV4/Company('KVT%20Gas')/SalesInvoiceSubform";
try {
    odata_mimir_or_direct(
        static function (): array {
            odata_mimir_fail(new Exception('Mímir cURL error: Operation timed out after 90001 milliseconds (key mimir_test_key_should_not_leak)'));
            return [];
        },
        static function () use ($url): array {
            throw new RuntimeException('HTTP 404 from OData (GET ' . $url . '): ' . odata_error_body_summary('') . ' pass=bc-secret');
        }
    );
    fail('directe fout hoort door te komen');
} catch (RuntimeException $error) {
    $message = $error->getMessage();
    foreach (['HTTP 404 from OData', $url, '(lege body)', 'directe BC-fallback na Mímir-fout', 'Operation timed out'] as $needle) {
        if (!str_contains($message, $needle)) {
            fail('melding mist "' . $needle . '": ' . $message);
        }
    }
    if (str_contains($message, 'mimir_test_key_should_not_leak') || str_contains($message, 'bc-secret')) {
        fail('melding lekt een secret: ' . $message);
    }
}

// Circuit open (volgend bedrijf): oorzaak staat er ook bij, maar maar één keer.
if (!odata_mimir_circuit_open()) {
    fail('circuit hoort open te staan na odata_mimir_fail');
}
try {
    odata_mimir_or_direct(
        static function (): array {
            fail('Mímir hoort overgeslagen te worden bij een open circuit');
            return [];
        },
        static function (): array {
            return odata_mimir_or_direct(
                static function (): array {
                    return [];
                },
                static function (): array {
                    throw new RuntimeException('HTTP 404 from OData (GET x): (lege body)');
                }
            );
        }
    );
    fail('directe fout hoort door te komen (open circuit)');
} catch (RuntimeException $error) {
    $count = substr_count($error->getMessage(), 'directe BC-fallback na Mímir-fout');
    if ($count !== 1) {
        fail('Mímir-oorzaak hoort precies één keer in de melding te staan, nu ' . $count . ': ' . $error->getMessage());
    }
}

// Reset: volgend bedrijf probeert Mímir weer.
odata_mimir_circuit_reset();
$viaMimir = false;
odata_mimir_or_direct(
    static function () use (&$viaMimir): array {
        $viaMimir = true;
        return [];
    },
    static function (): array {
        fail('na reset hoort Mímir weer eerst te gaan');
        return [];
    }
);
if (!$viaMimir) {
    fail('na reset is Mímir niet aangeroepen');
}

echo "OK\n";
