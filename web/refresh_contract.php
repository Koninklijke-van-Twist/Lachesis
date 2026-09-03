<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
set_time_limit(180);
ignore_user_abort(true);
ini_set('memory_limit', '256M');

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/voortgang_data.php';

/**
 * Functies
 */

function voortgang_refresh_send_json(array $payload, int $status = 200): never
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

$company = trim((string) ($_GET['company'] ?? $_POST['company'] ?? ''));
$contractNo = trim((string) ($_GET['contract'] ?? $_POST['contract'] ?? ''));

if ($company === '' || $contractNo === '') {
    voortgang_refresh_send_json([
        'ok' => false,
        'error' => 'Bedrijf of contractnummer ontbreekt.',
    ], 400);
}

$allowed = VOORTGANG_COMPANIES;
$cached = voortgang_cached_companies();
if ($cached !== []) {
    $allowed = array_values(array_unique(array_merge($allowed, $cached)));
}

if (!in_array($company, $allowed, true)) {
    voortgang_refresh_send_json([
        'ok' => false,
        'error' => 'Ongeldig bedrijf.',
    ], 400);
}

$mode = trim((string) ($_GET['mode'] ?? $_POST['mode'] ?? 'refresh'));
if ($mode === 'proforma') {
    $requested = trim((string) ($_GET['workorders'] ?? $_POST['workorders'] ?? ''));
    $workorderNos = [];
    if ($requested !== '') {
        foreach (explode(',', $requested) as $no) {
            $no = trim($no);
            if ($no !== '') {
                $workorderNos[] = $no;
            }
        }
    }
    if ($workorderNos === []) {
        $cache = voortgang_read_company_cache($company);
        $rows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row) || voortgang_scalar_string($row['contract_no'] ?? '') !== $contractNo) {
                continue;
            }
            $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $no = voortgang_scalar_string($item['no'] ?? '');
                if ($no !== '') {
                    $workorderNos[] = $no;
                }
            }
            break;
        }
    }

    try {
        $items = voortgang_proforma_documents_for_workorders($company, $workorderNos);
        voortgang_refresh_send_json([
            'ok' => true,
            'contract_no' => $contractNo,
            'items' => $items,
        ]);
    } catch (Throwable $error) {
        voortgang_refresh_send_json([
            'ok' => false,
            'contract_no' => $contractNo,
            'error' => $error->getMessage(),
        ], 500);
    }
}

try {
    $result = voortgang_refresh_contract($company, $contractNo);
    voortgang_refresh_send_json($result, !empty($result['ok']) ? 200 : 502);
} catch (Throwable $error) {
    voortgang_refresh_send_json([
        'ok' => false,
        'shared' => false,
        'removed' => false,
        'contract_no' => $contractNo,
        'error' => $error->getMessage(),
    ], 500);
}
