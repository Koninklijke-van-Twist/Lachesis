<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/lachesis-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['LACHESIS_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Compan(?:y|ies)(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Lachesis] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$loggedAfterFirst = fallback_count();
odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
if (fallback_count() !== $loggedAfterFirst) {
    fail('open circuit mag niet opnieuw loggen, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Lachesis] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$auth = $auth_list['Production'];
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeCompanyEnv = count($calls);
$companyEnvRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($companyEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-environment fallback gaf geen rijen');
}
$companyEnvCall = $calls[$beforeCompanyEnv] ?? null;
if (!is_array($companyEnvCall)
    || strpos((string) ($companyEnvCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($companyEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('query gebruikte niet het environment en de auth van het bedrijf: ' . json_encode($companyEnvCall));
}

odata_mimir_circuit_reset();
$beforeUrlEnv = count($calls);
$urlEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($urlEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('URL-environment fallback gaf geen rijen');
}
$urlEnvCall = $calls[$beforeUrlEnv] ?? null;
if (!is_array($urlEnvCall)
    || ($urlEnvCall['url'] ?? '') !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || ($urlEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('URL-segment werd vervangen door het primaire environment: ' . json_encode($urlEnvCall));
}

odata_mimir_circuit_reset();
$beforeMapped = count($calls);
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($mappedRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-map fallback gaf geen rijen');
}
$mappedCall = $calls[$beforeMapped] ?? null;
if (!is_array($mappedCall)
    || strpos((string) ($mappedCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/') !== 0
    || ($mappedCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('placeholder-environment negeerde de company-map: ' . json_encode($mappedCall));
}
$encodedEnvUrl = odata_bc_url_from_odata_url('https://mimir.invalid/My%20Env/ODataV4/Company(\'X\')/AppWerkorders');
if ($encodedEnvUrl !== 'https://bc.example:7148/My%20Env/ODataV4/Company(\'X\')/AppWerkorders') {
    fail('environment-segment werd dubbel geëncodeerd: ' . $encodedEnvUrl);
}
if (strpos(fallback_log(), 'sandbox-secret') !== false) {
    fail('log bevat een sandbox-geheim');
}

$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    $auth_list['Sandbox']
);
if (strpos($cacheKey, '|mimir') !== false || substr($cacheKey, -7) === '|mimir') {
    fail('cache-key gebruikt de mimir-placeholder: ' . $cacheKey);
}
if (substr($cacheKey, -8) !== '|Sandbox') {
    fail('cache-key mist het BC-environment uit de URL: ' . $cacheKey);
}
$environment = 'Production';

$loggedBeforeCaller = fallback_count();
odata_mimir_circuit_reset();
try {
    odata_mimir_or_direct(
        static function (): array {
            throw new RuntimeException('caller-side');
        },
        static function (): array {
            return [['No' => 'SHOULD-NOT']];
        }
    );
    fail('een caller-exception moet blijven');
} catch (RuntimeException $callerError) {
    if ($callerError->getMessage() !== 'caller-side') {
        fail('verkeerde caller-exception: ' . $callerError->getMessage());
    }
}
if (odata_mimir_circuit_open()) {
    fail('een caller-exception mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeCaller) {
    fail('een caller-exception mag geen fallback loggen');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials moet een exception terugkomen');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$tmpAuth = sys_get_temp_dir() . '/lachesis-auth-fallback-' . getmypid() . '.php';
file_put_contents($tmpAuth, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$base = 'loaded-base';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
PHP);
$baseUrl = 'https://kept.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'mimir';
$GLOBALS['environment'] = 'mimir';
$auth = [];
$GLOBALS['auth'] = [];
$auth_list = [];
$GLOBALS['auth_list'] = [];
unset($GLOBALS['base']);
unset($GLOBALS['LACHESIS_BC_AUTH_LOADED']);
$GLOBALS['LACHESIS_AUTH_LOAD'] = static function () use ($tmpAuth): array {
    require $tmpAuth;
    return get_defined_vars();
};
odata_bc_ensure_config_loaded();
$loadedBase = odata_bc_base_url();
$loadedUser = (string) ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '');
$loadedEnv = odata_bc_environment();
$loadedBaseAlias = $GLOBALS['base'] ?? null;
require_once $tmpAuth;
$baseAfterSecondInclude = odata_bc_base_url();
@unlink($tmpAuth);
unset($GLOBALS['LACHESIS_AUTH_LOAD']);
if ($loadedBase !== 'https://kept.example:7148/') {
    fail('gezette baseUrl werd overschreven, base=' . var_export($loadedBase, true));
}
if ($loadedUser !== 'loaded-user' || $loadedEnv !== 'LoadedEnv') {
    fail('auth_list/environment uit auth.php zijn niet globaal: user=' . $loadedUser . ' env=' . var_export($loadedEnv, true));
}
if ($loadedBaseAlias !== 'loaded-base') {
    fail('base uit auth.php is niet naar $GLOBALS gekopieerd: ' . var_export($loadedBaseAlias, true));
}
if ($baseAfterSecondInclude !== 'https://kept.example:7148/') {
    fail('tweede require_once maakte de BC-globals weer leeg of overschreef baseUrl');
}
if (strpos(fallback_log(), 'loaded-secret') !== false) {
    fail('log bevat het wachtwoord uit auth.php');
}

$mimirApi = 'mimir_test_key_should_not_leak';
$GLOBALS['mimirApi'] = $mimirApi;
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://kept.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'Production';
$GLOBALS['environment'] = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$GLOBALS['auth'] = $auth;
$auth_list = [];
$GLOBALS['auth_list'] = [];
unset($GLOBALS['LACHESIS_BC_AUTH_LOADED']);
$authListLoads = 0;
$GLOBALS['LACHESIS_AUTH_LOAD'] = static function () use (&$authListLoads): array {
    $authListLoads++;
    return [
        'baseUrl' => 'https://should-not-replace.example:7148/',
        'environment' => 'ShouldNotReplace',
        'auth' => ['mode' => 'basic', 'user' => 'should-not-replace', 'pass' => 'replace-secret'],
        'auth_list' => [
            'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-list-secret'],
        ],
    ];
};
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
];
odata_bc_ensure_config_loaded();
odata_bc_ensure_config_loaded();
if ($authListLoads !== 1) {
    fail('auth-config werd opnieuw geladen terwijl auth_list al compleet is: ' . $authListLoads);
}
if (odata_bc_base_url() !== 'https://kept.example:7148/') {
    fail('lazy load overschreef baseUrl: ' . var_export(odata_bc_base_url(), true));
}
if (odata_bc_environment() !== 'Production') {
    fail('lazy load overschreef environment: ' . var_export(odata_bc_environment(), true));
}
if ((string) ($GLOBALS['auth']['user'] ?? '') !== 'bcuser') {
    fail('lazy load overschreef generieke auth: ' . var_export($GLOBALS['auth']['user'] ?? null, true));
}
if ((string) ($GLOBALS['auth_list']['Sandbox']['user'] ?? '') !== 'sandbox-user') {
    fail('auth_list uit de lazy load ontbreekt: ' . json_encode($GLOBALS['auth_list']));
}
odata_mimir_circuit_reset();
$beforeAuthList = count($calls);
$authListRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($authListRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query met nageladen auth_list gaf geen rijen');
}
$authListCall = $calls[$beforeAuthList] ?? null;
if (!is_array($authListCall)
    || strpos((string) ($authListCall['url'] ?? ''), "https://kept.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($authListCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('de nageladen auth_list werd niet gebruikt voor het gemapte bedrijf: ' . json_encode($authListCall));
}
if ($authListLoads !== 1) {
    fail('query laadde auth-config opnieuw: ' . $authListLoads);
}
if (strpos(fallback_log(), 'sandbox-list-secret') !== false || strpos(fallback_log(), 'replace-secret') !== false) {
    fail('log bevat een geheim uit de nageladen auth_list');
}
unset($GLOBALS['LACHESIS_AUTH_LOAD']);

function fallback_reset_bc(string $user, array $list, string $env = 'Production', string $base = 'https://bc.example:7148/'): void
{
    global $mimirApi, $mimirBase, $baseUrl, $environment, $auth, $auth_list;
    $mimirApi = 'mimir_test_key_should_not_leak';
    $GLOBALS['mimirApi'] = $mimirApi;
    $mimirBase = 'http://127.0.0.1:9';
    $GLOBALS['mimirBase'] = $mimirBase;
    $baseUrl = $base;
    $GLOBALS['baseUrl'] = $baseUrl;
    $environment = $env;
    $GLOBALS['environment'] = $environment;
    $auth = ['mode' => 'basic', 'user' => $user, 'pass' => $user . '-secret'];
    $GLOBALS['auth'] = $auth;
    $auth_list = $list;
    $GLOBALS['auth_list'] = $auth_list;
    unset($GLOBALS['demeter_company_environment_map'], $GLOBALS['demeter_companies_by_environment'], $GLOBALS['demeter_active_environments'], $GLOBALS['lachesis_original_auth'], $GLOBALS['base']);
    odata_mimir_circuit_reset();
}

fallback_reset_bc('only-auth-user', []);
if (auth_get_auth_for_environment('Production') !== []) {
    fail('gezonde Mímir mag generieke $auth niet als environment-auth teruggeven');
}
$context = auth_set_current_company_context('KVT Gas', 30);
if (($context['auth'] ?? null) !== []) {
    fail('Mímir-context moet lege auth teruggeven als $auth_list geen entry heeft: ' . json_encode($context));
}
if (($GLOBALS['auth']['user'] ?? '') !== 'only-auth-user') {
    fail('auth_set_current_company_context wiste de originele $auth: ' . json_encode($GLOBALS['auth']));
}
if (!odata_mimir_circuit_open()) {
    fail('company-context via Mímir hoort het circuit te openen na de Mímir-fout');
}
if ((auth_get_auth_for_environment('Production')['user'] ?? '') !== 'only-auth-user') {
    fail('fallback-auth voor het primaire environment moet $auth zijn');
}
if ((auth_get_auth_for_environment('Sandbox')['user'] ?? '') !== 'only-auth-user') {
    fail('lege $auth_list moet ook een ander environment met $auth bevragen');
}

fallback_reset_bc('only-auth-user', []);
$GLOBALS['demeter_company_environment_map'] = ['Mapped Co' => 'Production'];
$beforeOnlyAuth = count($calls);
$onlyAuthRows = odata_mimir_query('Mapped Co', 'AppResource', ['$select' => 'No'], 30);
if (($onlyAuthRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query met alleen $auth gaf geen rijen');
}
$onlyAuthCall = $calls[$beforeOnlyAuth] ?? null;
if (!is_array($onlyAuthCall)
    || strpos((string) ($onlyAuthCall['url'] ?? ''), "https://bc.example:7148/Production/ODataV4/Company('Mapped%20Co')/AppResource?") !== 0
    || ($onlyAuthCall['user'] ?? '') !== 'only-auth-user'
) {
    fail('gemapte query zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyAuthCall));
}

odata_mimir_circuit_reset();
$beforeOnlyUrl = count($calls);
$onlyUrl = "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
$onlyUrlRows = odata_get_all($onlyUrl, $auth, 25);
if (($onlyUrlRows[0]['No'] ?? '') !== 'WO-1') {
    fail('URL-fetch met alleen $auth gaf geen rijen');
}
$onlyUrlCall = $calls[$beforeOnlyUrl] ?? null;
if (!is_array($onlyUrlCall)
    || ($onlyUrlCall['url'] ?? '') !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No"
    || ($onlyUrlCall['user'] ?? '') !== 'only-auth-user'
) {
    fail('URL-fetch zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyUrlCall));
}

odata_mimir_circuit_reset();
$beforeOnlyCompanies = count($calls);
$onlyCompanyNames = odata_mimir_list_companies(null);
if ($onlyCompanyNames !== ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas']) {
    fail('companylijst met alleen $auth: ' . json_encode($onlyCompanyNames));
}
$onlyCompanyCall = $calls[$beforeOnlyCompanies] ?? null;
if (!is_array($onlyCompanyCall)
    || strpos((string) ($onlyCompanyCall['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Company') !== 0
    || ($onlyCompanyCall['user'] ?? '') !== 'only-auth-user'
) {
    fail('companylijst zonder $auth_list gebruikte niet $auth: ' . json_encode($onlyCompanyCall));
}

odata_mimir_circuit_reset();
unset($GLOBALS['demeter_company_environment_map'], $GLOBALS['demeter_companies_by_environment'], $GLOBALS['demeter_active_environments']);
$beforeDiscover = count($calls);
$discovered = auth_discover_companies_across_active_environments(30);
if (!in_array('KVT Gas', $discovered['companies'] ?? [], true)) {
    fail('company-discovery zonder $auth_list vond KVT Gas niet: ' . json_encode($discovered['companies'] ?? null));
}
$discoverCall = $calls[$beforeDiscover] ?? null;
if (!is_array($discoverCall)
    || strpos((string) ($discoverCall['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Companies?') !== 0
    || ($discoverCall['user'] ?? '') !== 'only-auth-user'
) {
    fail('company-discovery zonder $auth_list gebruikte niet $auth: ' . json_encode($discoverCall));
}

fallback_reset_bc('primary-user', [
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
]);
$GLOBALS['demeter_company_environment_map'] = ['Hunter van Twist' => 'Sandbox'];
$beforeUnmapped = count($calls);
$unmappedRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No'], 30);
if (($unmappedRows[0]['No'] ?? '') !== 'WO-1') {
    fail('ongemapt bedrijf gaf geen rijen');
}
$unmappedCall = $calls[$beforeUnmapped] ?? null;
if (!is_array($unmappedCall)
    || strpos((string) ($unmappedCall['url'] ?? ''), "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0
    || ($unmappedCall['user'] ?? '') !== 'primary-user'
) {
    fail('ongemapt bedrijf moet via $auth naar Production: ' . json_encode($unmappedCall));
}

fallback_reset_bc('bcuser', [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
]);
$beforeSandboxUrl = count($calls);
$sandboxRefused = null;
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
        $auth,
        20
    );
    fail('Sandbox-URL zonder auth_list-entry moet weigeren');
} catch (Throwable $sandboxError) {
    $sandboxRefused = $sandboxError;
}
if (!$sandboxRefused instanceof Throwable || strpos($sandboxRefused->getMessage(), 'Mímir') === false) {
    fail('Sandbox-URL moet de oorspronkelijke Mímir-fout geven: ' . ($sandboxRefused ? $sandboxRefused->getMessage() : 'geen'));
}
if (count($calls) !== $beforeSandboxUrl) {
    fail('Sandbox-URL mag geen BC-call doen: ' . json_encode(array_slice($calls, $beforeSandboxUrl)));
}
odata_mimir_circuit_reset();
$beforeSandboxFetch = count($calls);
try {
    odata_mimir_fetch_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
        20
    );
    fail('Sandbox fetch_all zonder auth_list-entry moet weigeren');
} catch (Throwable $sandboxFetchError) {
    if (strpos($sandboxFetchError->getMessage(), 'Mímir') === false) {
        fail('Sandbox fetch_all gaf niet de Mímir-fout: ' . $sandboxFetchError->getMessage());
    }
}
if (count($calls) !== $beforeSandboxFetch) {
    fail('Sandbox fetch_all mag geen BC-call doen: ' . json_encode(array_slice($calls, $beforeSandboxFetch)));
}
if (strpos(fallback_log(), 'only-auth-user-secret') !== false || strpos(fallback_log(), 'primary-user-secret') !== false) {
    fail('log bevat een geheim uit de extra fallback-tests');
}

echo "OK\n";
