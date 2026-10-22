<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

/**
 * Includes/requires
 *
 * Mímir max_age op nightly-fetches: LACHESIS_NIGHTLY_MAX_AGE (14400).
 * auth.php moet $mimirApi én de BC-credentials bevatten; bij een Mímir-fout
 * valt deze run (CLI of GET) terug op het directe BC-pad.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/voortgang_data.php';
require_once __DIR__ . '/nightly_runner.php';

/**
 * Functies
 */

function voortgang_nightly_companies(string $requestedCompany): array
{
    $requestedCompany = trim($requestedCompany);
    if ($requestedCompany !== '') {
        return [$requestedCompany];
    }

    return VOORTGANG_COMPANIES;
}

/**
 * Company→environment uit Mímir companies.php laden zolang Mímir gezond is.
 * Valt Mímir later in de run uit, dan bouwt het directe BC-pad zijn URL met
 * dezelfde environment per bedrijf (gesplitste $auth_list) in plaats van een
 * eigen discovery over de lokale environments.
 */
function voortgang_nightly_prime_company_environments(): void
{
    if (!function_exists('odata_mimir_enabled') || !odata_mimir_enabled() || !function_exists('auth_get_company_environment_map')) {
        return;
    }
    if (function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open()) {
        return;
    }

    try {
        auth_get_company_environment_map(300, true);
    } catch (Throwable $ignored) {
        // Geen map: het directe pad doet zo nodig zijn eigen discovery.
    }
}

/**
 * Elk bedrijf begint met een dicht Mímir-circuit: een Mímir-fout bij het ene
 * bedrijf mag de andere niet naar het directe BC-pad dwingen.
 */
function voortgang_nightly_reset_mimir_circuit(): void
{
    if (function_exists('odata_mimir_circuit_reset')) {
        odata_mimir_circuit_reset();
    }
}

function voortgang_nightly_send_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Page load
 */

$startedAt = time();
$isCli = PHP_SAPI === 'cli';
$requestedCompany = trim((string) ($_GET['company'] ?? ''));

// Web: ?status=1 geeft de stand van de (achtergrond)run zonder iets te starten.
if (!$isCli && isset($_GET['status'])) {
    voortgang_nightly_send_json(nightly_status_view());
}

$runLock = null;
if (!$isCli) {
    // Web: één run tegelijk. flock valt vanzelf weg bij een dood proces, dus een
    // achtergebleven lockbestand blokkeert niet; de status toont of het stale was.
    [$runLock, $lockReason] = nightly_try_acquire_run_lock();
    if ($runLock === null) {
        $view = nightly_status_view();
        voortgang_nightly_send_json([
            'ok' => false,
            'started' => false,
            'reason' => $lockReason === 'running' ? 'Er loopt al een nightly-run.' : 'Run-lock niet beschikbaar.',
            'status' => $view,
        ], 409);
    }
    $previous = nightly_status_view();
    $runStatus = [
        'state' => 'running',
        'pid' => getmypid(),
        'started_at' => $startedAt,
        'finished_at' => 0,
        'requested_company' => $requestedCompany,
        'current_company' => '',
        'companies' => [],
        'errors' => [],
        'previous_stale' => (bool) ($previous['stale'] ?? false),
    ];
    nightly_write_status($runStatus);
    nightly_detach_response([
        'ok' => true,
        'started' => true,
        'started_at' => $startedAt,
        'pid' => getmypid(),
        'status_url' => '?status=1',
        'message' => 'Nightly gestart op de achtergrond; volg de voortgang via ?status=1.',
    ]);
}

// Nightly Mímir max_age = 4h (LACHESIS_NIGHTLY_MAX_AGE). UI/on-demand keeps LACHESIS_ODATA_TTL.
$GLOBALS['lachesis_odata_max_age'] = defined('LACHESIS_NIGHTLY_MAX_AGE') ? LACHESIS_NIGHTLY_MAX_AGE : 14400;
// Mímir-timeout los van de SAPI: de web-default (90s) is te kort voor een koude KvT-refresh.
$GLOBALS['lachesis_mimir_timeout_override'] = defined('LACHESIS_NIGHTLY_MIMIR_TIMEOUT') ? LACHESIS_NIGHTLY_MIMIR_TIMEOUT : 600;
$results = [];
$staleLocksRemoved = 0;
$cacheProtectionErrors = [];
$ok = false;

try {
    $staleLocksRemoved = voortgang_cleanup_stale_locks();
    $cacheProtectionErrors = voortgang_protect_cache_dirs();
    voortgang_nightly_prime_company_environments();
    $companies = voortgang_nightly_companies($requestedCompany);
    $ok = $cacheProtectionErrors === [];

    foreach ($companies as $company) {
        $companyName = trim((string) $company);
        if ($companyName === '') {
            continue;
        }

        voortgang_nightly_reset_mimir_circuit();
        if (!$isCli) {
            $runStatus['current_company'] = $companyName;
            nightly_write_status($runStatus);
        }

        // voortgang_refresh_company schrijft de cache van dit bedrijf direct weg
        // (en laat hem staan als de guard tegen lege resultaten afgaat).
        try {
            $meta = voortgang_refresh_company($companyName);
            $result = [
                'ok' => true,
                'company' => $companyName,
                'cached_at' => (int) ($meta['cached_at'] ?? time()),
                'contract_count' => (int) ($meta['contract_count'] ?? 0),
                'workorder_count' => (int) ($meta['workorder_count'] ?? 0),
                'workorder_read' => (int) ($meta['workorder_read'] ?? 0),
                'workorder_pages' => (int) ($meta['workorder_pages'] ?? 0),
                'contract_matched' => (int) ($meta['contract_matched'] ?? 0),
                'contract_read' => (int) ($meta['contract_read'] ?? 0),
            ];
        } catch (Throwable $error) {
            $ok = false;
            $result = [
                'ok' => false,
                'company' => $companyName,
                'error' => $error->getMessage(),
            ];
        }
        $results[] = $result;
        if (!$isCli) {
            $runStatus['companies'][] = $result + ['finished_at' => time()];
            if (!$result['ok']) {
                $runStatus['errors'][] = $companyName . ': ' . $result['error'];
            }
            nightly_write_status($runStatus);
        }
    }
} catch (Throwable $fatal) {
    $ok = false;
    $results[] = ['ok' => false, 'company' => '', 'error' => $fatal->getMessage()];
    if (!$isCli) {
        $runStatus['errors'][] = $fatal->getMessage();
    }
} finally {
    if (!$isCli) {
        $runStatus['state'] = ($ok && $results !== []) ? 'done' : 'error';
        $runStatus['ok'] = $ok && $results !== [];
        $runStatus['current_company'] = '';
        $runStatus['finished_at'] = time();
        $runStatus['duration_seconds'] = time() - $startedAt;
        $runStatus['stale_locks_removed'] = $staleLocksRemoved;
        $runStatus['cache_protection_errors'] = $cacheProtectionErrors;
        foreach ($cacheProtectionErrors as $protectionError) {
            $runStatus['errors'][] = (string) $protectionError;
        }
        nightly_write_status($runStatus);
        nightly_release_run_lock($runLock);
    }
}

if (!$isCli) {
    exit; // response is al verstuurd
}

voortgang_nightly_send_json([
    'ok' => $ok && $results !== [],
    'ran_at' => $startedAt,
    'duration_seconds' => time() - $startedAt,
    'stale_locks_removed' => $staleLocksRemoved,
    'cache_protection_errors' => $cacheProtectionErrors,
    'companies' => $results,
], $ok && $results !== [] ? 200 : 500);
