<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/voortgang_data.php';

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
$requestedCompany = trim((string) ($_GET['company'] ?? ''));
$companies = voortgang_nightly_companies($requestedCompany);
$results = [];
$ok = true;

foreach ($companies as $company) {
    $companyName = trim((string) $company);
    if ($companyName === '') {
        continue;
    }

    try {
        $meta = voortgang_refresh_company($companyName);
        $results[] = [
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
        $results[] = [
            'ok' => false,
            'company' => $companyName,
            'error' => $error->getMessage(),
        ];
    }
}

voortgang_nightly_send_json([
    'ok' => $ok && $results !== [],
    'ran_at' => $startedAt,
    'duration_seconds' => time() - $startedAt,
    'companies' => $results,
], $ok && $results !== [] ? 200 : 500);
