<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/localization.php';
require_once __DIR__ . '/voortgang_data.php';
require_once __DIR__ . '/voortgang_xlsx.php';

/**
 * Functies
 */

function voortgang_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function voortgang_format_money(float $value): string
{
    return '€ ' . number_format($value, 2, ',', '.');
}

/** BC levert revenue vaak negatief; normaliseer naar positief bedrag. */
function voortgang_normalized_revenue(float $value): float
{
    return $value < 0 ? -$value : $value;
}

function voortgang_format_percent(float $value): string
{
    if (abs($value - round($value)) < 0.05) {
        return number_format($value, 0, ',', '.') . '%';
    }

    return number_format($value, 1, ',', '.') . '%';
}

function voortgang_row_search_text(array $row): string
{
    $parts = [
        $row['contract_no'] ?? '',
        $row['description'] ?? '',
        $row['invoice_period'] ?? '',
        $row['instructions'] ?? '',
        voortgang_format_money((float) ($row['total_sales'] ?? 0)),
        voortgang_format_money(voortgang_normalized_revenue((float) ($row['total_revenue'] ?? 0))),
        voortgang_format_money((float) ($row['total_cost'] ?? 0)),
        voortgang_format_money((float) ($row['open_proforma'] ?? 0)),
    ];

    return strtolower(implode(' ', array_map('strval', $parts)));
}

function voortgang_count_cell(int $count, string $status): string
{
    if ($count <= 0) {
        return '<td class="num">0</td>';
    }

    return '<td class="num"><button type="button" class="voortgang-count" data-status="'
        . voortgang_h($status) . '">' . (int) $count . '</button></td>';
}

function voortgang_proforma_cell(float $amount): string
{
    if ($amount <= 0) {
        return '<td class="num">-</td>';
    }

    return '<td class="num"><button type="button" class="voortgang-proforma">'
        . voortgang_h(voortgang_format_money($amount)) . '</button></td>';
}

/**
 * Page load
 */

$voortgangPageSizeOptions = [50, 100, 150, 200, 500];
$voortgangDefaultPageSize = 100;

if (isset($_GET['action']) && trim((string) $_GET['action']) === 'save_page_size') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $requestedSize = (int) ($_GET['page_size'] ?? $voortgangDefaultPageSize);
    if (!in_array($requestedSize, $voortgangPageSizeOptions, true)) {
        $requestedSize = $voortgangDefaultPageSize;
    }
    if ($prefEmail !== '') {
        saveUserPref($prefEmail, 'page_size', $requestedSize);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'page_size' => $requestedSize], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['action']) && trim((string) $_GET['action']) === 'save_settings') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $prefEmailSettings = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $hidePd = isset($_GET['hide_pd_task_code']) && (string) $_GET['hide_pd_task_code'] === '1';
    if ($prefEmailSettings !== '') {
        saveUserPref($prefEmailSettings, 'hide_pd_task_code', $hidePd);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'hide_pd_task_code' => $hidePd], JSON_UNESCAPED_UNICODE);
    exit;
}

$companies = voortgang_cached_companies();
if ($companies === []) {
    $companies = VOORTGANG_COMPANIES;
}
$prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$userPrefs = $prefEmail !== '' ? loadUserPrefs($prefEmail) : [];
$savedCompany = trim((string) ($userPrefs['company'] ?? ''));
$savedPageSize = (int) ($userPrefs['page_size'] ?? $voortgangDefaultPageSize);
if (!in_array($savedPageSize, $voortgangPageSizeOptions, true)) {
    $savedPageSize = $voortgangDefaultPageSize;
}

$requestedCompany = trim((string) ($_GET['company'] ?? ''));
$showCompanyWelcome = isset($_GET['welcome']) && trim((string) $_GET['welcome']) === '1';

if (isset($_GET['action']) && trim((string) $_GET['action']) === 'save_company') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $prefEmailSave = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $companyToSave = trim((string) ($_GET['company'] ?? ''));
    if ($companyToSave !== '' && in_array($companyToSave, $companies, true)) {
        if ($prefEmailSave !== '') {
            saveUserPref($prefEmailSave, 'company', $companyToSave);
        }
        header('Location: index.php?company=' . rawurlencode($companyToSave) . '&welcome=1');
        exit;
    }
    header('Location: index.php');
    exit;
}

$hasCompanyPref = $savedCompany !== '' && in_array($savedCompany, $companies, true);
$needsCompanyChoice = !$hasCompanyPref && $companies !== [];

if ($requestedCompany !== '' && in_array($requestedCompany, $companies, true)) {
    $company = $requestedCompany;
    if ($prefEmail !== '' && $requestedCompany !== $savedCompany) {
        saveUserPref($prefEmail, 'company', $requestedCompany);
    }
    $needsCompanyChoice = false;
} elseif ($hasCompanyPref) {
    $company = $savedCompany;
    $needsCompanyChoice = false;
} else {
    $company = '';
}

$cache = (!$needsCompanyChoice && $company !== '') ? voortgang_read_company_cache($company) : null;
$cachedAt = (int) ($cache['_meta']['cached_at'] ?? 0);
$cacheStale = $cache !== null && $cachedAt > 0 && (time() - $cachedAt) > 129600;
$rowsRaw = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
$hidePdTaskCode = !empty($userPrefs['hide_pd_task_code']);
$dateMin = voortgang_parse_odata_date($cache['_meta']['date_min'] ?? '');
$dateMax = voortgang_parse_odata_date($cache['_meta']['date_max'] ?? '');
if ($dateMin === '' || $dateMax === '') {
    $bounds = voortgang_workorder_date_bounds_from_rows($rowsRaw);
    if ($dateMin === '') {
        $dateMin = $bounds['min'];
    }
    if ($dateMax === '') {
        $dateMax = $bounds['max'];
    }
}
$dateFrom = voortgang_parse_odata_date($_GET['date_from'] ?? $dateMin);
$dateTo = voortgang_parse_odata_date($_GET['date_to'] ?? $dateMax);
if ($dateFrom === '') {
    $dateFrom = $dateMin;
}
if ($dateTo === '') {
    $dateTo = $dateMax;
}
$rows = voortgang_apply_filters_to_rows($rowsRaw, $hidePdTaskCode, $dateFrom, $dateTo);
$exportError = '';

if (isset($_GET['export']) && trim((string) $_GET['export']) === 'excel') {
    try {
        $binary = voortgang_build_excel_xlsx($rows);
        $filename = 'contractvoortgang_' . voortgang_company_slug($company) . '.xlsx';
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) strlen($binary));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $binary;
        exit;
    } catch (Throwable $error) {
        $exportError = LOC('voortgang.export.failed');
    }
}

$cachedAtLabel = '';
if ($cachedAt > 0) {
    $cachedAtLabel = (new DateTimeImmutable('@' . $cachedAt))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d-m-Y H:i');
}

$contractData = [];
foreach ($rowsRaw as $row) {
    if (!is_array($row)) {
        continue;
    }
    $contractNo = (string) ($row['contract_no'] ?? '');
    if ($contractNo === '') {
        continue;
    }
    $contractData[$contractNo] = [
        'contract_no' => $contractNo,
        'description' => (string) ($row['description'] ?? ''),
        'invoice_period' => (string) ($row['invoice_period'] ?? ''),
        'total_sales' => (float) ($row['total_sales'] ?? 0),
        'total_revenue' => (float) ($row['total_revenue'] ?? 0),
        'total_cost' => (float) ($row['total_cost'] ?? 0),
        'open_proforma' => (float) ($row['open_proforma'] ?? 0),
        'instructions' => (string) ($row['instructions'] ?? ''),
        'items' => is_array($row['workorders'] ?? null) ? $row['workorders'] : [],
    ];
}

$contractDataJson = json_encode($contractData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($contractDataJson === false) {
    $contractDataJson = '{}';
}

$excelUrl = 'index.php?export=excel&company=' . rawurlencode($company)
    . '&date_from=' . rawurlencode($dateFrom)
    . '&date_to=' . rawurlencode($dateTo);

$bcWebClient = [
    'base' => '',
    'company' => $company,
    'workorder_page' => VOORTGANG_BC_PAGE_WORKORDER,
    'invoice_page' => VOORTGANG_BC_PAGE_SALES_INVOICE,
];
if ($company !== '' && !$needsCompanyChoice) {
    $cachedEnvironment = trim((string) (is_array($cache) ? ($cache['_meta']['environment'] ?? '') : ''));
    $environment = voortgang_bc_webclient_environment($company, $cachedEnvironment);
    $bcWebClient['base'] = voortgang_bc_webclient_base_from_environment($environment);
}

?><!DOCTYPE html>
<html lang="<?= voortgang_h(getHtmlLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= voortgang_h(LOC('app.title')) ?></title>
    <link rel="stylesheet" href="brand.css">
    <link rel="manifest" href="site.webmanifest">
    <link rel="icon" href="doc.svg" type="image/svg+xml">
    <?php renderLanguageSwitcherStyles(); ?>
    <style>
        .voortgang-page { margin: 0 auto; padding: 16px; }
        .voortgang-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            margin-bottom: 16px;
        }
        .voortgang-header img { max-height: 42px; width: auto; justify-self: start; }
        .voortgang-header-dates {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            align-items: end;
            justify-content: center;
        }
        .voortgang-header-dates label {
            display: grid;
            gap: 4px;
            font-weight: 700;
            color: var(--kvt-muted);
            font-size: 0.82rem;
        }
        .voortgang-header-dates input[type="date"] {
            font: inherit;
            border-radius: 10px;
            border: 1px solid var(--kvt-line);
            padding: 8px 10px;
            min-width: 9.5rem;
            box-sizing: border-box;
        }
        .voortgang-header-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-self: end; }
        .voortgang-settings-btn {
            font: inherit;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            border: 1px solid var(--kvt-line);
            background: #fff;
            color: var(--kvt-main-blue);
            cursor: pointer;
            line-height: 1;
            font-size: 1.25rem;
            padding: 0;
        }
        .voortgang-settings-btn:hover,
        .voortgang-settings-btn:focus-visible {
            border-color: var(--kvt-main-blue);
            background: #f0f9ff;
        }
        .voortgang-settings-option {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin: 12px 0 0;
            font-weight: 600;
            color: var(--kvt-text);
            cursor: pointer;
        }
        .voortgang-settings-option input {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            flex: 0 0 auto;
        }
        .voortgang-excel { font: inherit; font-weight: 700; background: #15803d; color: #fff; border: 1px solid #15803d; border-radius: 10px; padding: 12px 16px; cursor: pointer; text-decoration: none; display: inline-block; }
        .voortgang-excel:hover { background: #166534; border-color: #166534; color: #fff; }
        .voortgang-card { background: var(--kvt-panel-bg); border: 1px solid var(--kvt-line); border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .voortgang-card h1, .voortgang-card h2 { margin: 0 0 12px; color: var(--kvt-text); }
        .voortgang-subtitle, .voortgang-meta { color: var(--kvt-muted); margin: 6px 0 0; }
        .voortgang-meta { font-size: 0.92rem; }
        .voortgang-form { display: grid; gap: 12px; margin-top: 16px; }
        .voortgang-form-grid { display: grid; gap: 12px; }
        .voortgang-form label { display: grid; gap: 6px; font-weight: 700; color: var(--kvt-muted); }
        .voortgang-form input, .voortgang-form select { font: inherit; border-radius: 10px; border: 1px solid var(--kvt-line); padding: 12px 14px; width: 100%; box-sizing: border-box; }
        .voortgang-progress-toggle {
            display: grid;
            grid-template-columns: minmax(4.5rem, auto) minmax(88px, 112px) minmax(4.5rem, auto);
            grid-template-rows: auto auto;
            column-gap: 10px;
            row-gap: 4px;
            align-items: center;
            justify-items: center;
            width: fit-content;
            max-width: 100%;
            margin: 0;
            padding: 4px 0;
            border: 0;
            background: transparent;
            cursor: pointer;
            font: inherit;
            color: var(--kvt-muted);
            user-select: none;
        }
        .voortgang-progress-toggle:focus-visible {
            outline: 2px solid var(--kvt-main-blue);
            outline-offset: 4px;
            border-radius: 8px;
        }
        .voortgang-progress-current {
            grid-column: 1 / -1;
            font-weight: 700;
            color: var(--kvt-text);
            font-size: 0.92rem;
            min-height: 1.2em;
            text-align: center;
        }
        .voortgang-progress-side {
            font-weight: 700;
            font-size: 0.86rem;
            white-space: nowrap;
        }
        .voortgang-progress-side.is-active { color: var(--kvt-text); }
        .voortgang-progress-track {
            position: relative;
            width: 100%;
            height: 28px;
            border-radius: 999px;
            border: 1px solid var(--kvt-line);
            background: #f1f5f9;
            box-sizing: border-box;
        }
        .voortgang-progress-knob {
            position: absolute;
            top: 3px;
            left: 3px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--kvt-main-blue);
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.2);
            transition: left 0.18s ease;
        }
        .voortgang-progress-toggle[data-state="completed"] .voortgang-progress-knob { left: 3px; }
        .voortgang-progress-toggle[data-state="all"] .voortgang-progress-knob { left: calc(50% - 10px); }
        .voortgang-progress-toggle[data-state="incomplete"] .voortgang-progress-knob { left: calc(100% - 23px); }
        .voortgang-alert { border: 1px solid #fecaca; background: #fef2f2; color: var(--kvt-danger); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .voortgang-alert-warn { border-color: #fdba74; background: #fff7ed; color: #9a3412; }
        .voortgang-muted { color: var(--kvt-muted); }
        .voortgang-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-left: 36px; }
        table.voortgang-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; min-width: 1280px; }
        table.voortgang-table th, table.voortgang-table td {
            border-bottom: 1px solid var(--kvt-line);
            border-left: 1px solid rgba(15, 23, 42, 0.05);
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
            background: #fff;
        }
        table.voortgang-table th:first-child, table.voortgang-table td:first-child { border-left: 0; }
        table.voortgang-table th {
            color: var(--kvt-muted);
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
            cursor: pointer;
            user-select: none;
        }
        table.voortgang-table th:hover { color: var(--kvt-text); }
        table.voortgang-table th.is-sorted-asc::after,
        table.voortgang-table th.is-sorted-desc::after {
            font-size: 0.7rem;
            margin-left: 4px;
            color: var(--kvt-main-blue);
        }
        table.voortgang-table th.is-sorted-asc::after { content: '▲'; }
        table.voortgang-table th.is-sorted-desc::after { content: '▼'; }
        table.voortgang-table td.num, table.voortgang-table th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.voortgang-table th:first-child, table.voortgang-table td:first-child { position: sticky; left: 0; z-index: 1; min-width: 110px; overflow: visible; }
        table.voortgang-table th:first-child { z-index: 3; }
        .voortgang-contract-cell { position: relative; overflow: visible; }
        /* Hit-area in de linker gutter zodat hover blijft tot je op ♻️ klikt. */
        .voortgang-contract-cell::before {
            content: '';
            position: absolute;
            left: -36px;
            top: 0;
            bottom: 0;
            width: 36px;
        }
        .voortgang-row-refresh {
            position: absolute;
            left: -32px;
            top: 50%;
            transform: translateY(-50%);
            width: 28px;
            height: 28px;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: #fff;
            color: var(--kvt-main-blue);
            font-size: 1rem;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            pointer-events: none;
            z-index: 5;
            box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.06);
        }
        .voortgang-row-refresh:hover,
        .voortgang-row-refresh:focus-visible {
            background: #e0f2fe;
        }
        .voortgang-row-refresh.is-busy {
            opacity: 1;
            pointer-events: none;
            animation: voortgang-refresh-spin 0.9s linear infinite;
        }
        @media (hover: hover) {
            table.voortgang-table tbody tr:hover .voortgang-row-refresh:not(:disabled) {
                opacity: 1;
                pointer-events: auto;
            }
        }
        @media (hover: none) {
            .voortgang-row-refresh {
                opacity: 0.85;
                pointer-events: auto;
            }
        }
        @keyframes voortgang-refresh-spin {
            from { transform: translateY(-50%) rotate(0deg); }
            to { transform: translateY(-50%) rotate(360deg); }
        }
        table.voortgang-table tbody tr.is-refreshing td { background: #f0f9ff; }
        .voortgang-instructions { max-width: 220px; white-space: pre-wrap; overflow-wrap: anywhere; }
        .voortgang-count { font: inherit; font-weight: 700; color: var(--kvt-main-blue); background: transparent; border: 0; padding: 0; cursor: pointer; text-decoration: underline; }
        .voortgang-proforma { font: inherit; font-weight: 700; color: var(--kvt-main-blue); background: transparent; border: 0; padding: 0; cursor: pointer; text-decoration: underline; font-variant-numeric: tabular-nums; }
        .voortgang-bc-link { font: inherit; font-weight: 700; color: var(--kvt-main-blue); text-decoration: underline; }
        .voortgang-bc-link:hover { color: var(--kvt-text); }
        .voortgang-row-hidden { display: none; }
        .voortgang-pager { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
        .voortgang-pager-top { margin: 0 0 14px; }
        .voortgang-pager-bottom { margin: 14px 0 0; }
        .voortgang-pager-controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .voortgang-page-numbers { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .voortgang-pager button { font: inherit; border: 1px solid var(--kvt-line); background: #fff; border-radius: 10px; padding: 10px 14px; cursor: pointer; color: var(--kvt-text); min-width: 44px; }
        .voortgang-pager button:disabled { opacity: 0.45; cursor: not-allowed; }
        .voortgang-pager button.is-current { background: var(--kvt-text); color: #fff; border-color: var(--kvt-text); cursor: default; }
        .voortgang-page-ellipsis { color: var(--kvt-muted); padding: 0 2px; user-select: none; }
        .voortgang-pager-status { color: var(--kvt-muted); font-size: 0.92rem; }
        .voortgang-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); display: none; align-items: flex-end; justify-content: center; z-index: 13000; padding: 0; }
        .voortgang-modal-backdrop.is-open { display: flex; }
        .voortgang-modal { width: min(920px, 100%); max-height: 92vh; overflow: auto; background: #fff; border-radius: 16px 16px 0 0; padding: 16px; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25); }
        .voortgang-modal .voortgang-table-wrap { padding-left: 0; }
        .voortgang-proforma-summary { margin: 0 0 12px; color: var(--kvt-text); font-weight: 700; line-height: 1.45; }
        .voortgang-proforma-amount { font: inherit; font-weight: 700; color: var(--kvt-main-blue); background: transparent; border: 0; padding: 0; cursor: pointer; text-decoration: underline; font-variant-numeric: tabular-nums; }
        .voortgang-modal-table th[title], .voortgang-modal-table td[title] { cursor: help; }
        .voortgang-modal-table th.num, .voortgang-modal-table td.num { white-space: nowrap; }
        #voortgang-proforma-lines-backdrop { z-index: 14000; }
        .voortgang-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: start; margin-bottom: 12px; position: sticky; top: 0; background: #fff; padding-bottom: 8px; border-bottom: 1px solid var(--kvt-line); }
        .voortgang-modal-close { border: 0; background: transparent; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--kvt-muted); padding: 4px 8px; }
        .voortgang-modal-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
        .voortgang-modal-table th, .voortgang-modal-table td { border-bottom: 1px solid var(--kvt-line); padding: 8px 6px; text-align: left; }
        .voortgang-company-list { display: grid; gap: 10px; margin-top: 8px; }
        .voortgang-company-option {
            font: inherit;
            font-weight: 700;
            text-align: left;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--kvt-line);
            border-radius: 10px;
            padding: 14px 16px;
            background: #fff;
            color: var(--kvt-text);
            cursor: pointer;
        }
        .voortgang-company-option:hover {
            border-color: var(--kvt-main-blue);
            background: #f0f9ff;
        }
        .voortgang-modal-body-text { color: var(--kvt-muted); margin: 0; line-height: 1.5; }
        .voortgang-modal-backdrop.is-locked { cursor: default; }
        @media (min-width: 720px) {
            .voortgang-form-grid { grid-template-columns: 1fr auto 180px; align-items: end; }
            .voortgang-modal-backdrop { align-items: center; padding: 24px; }
            .voortgang-modal { border-radius: 16px; }
        }
        @media (max-width: 900px) {
            .voortgang-header {
                grid-template-columns: 1fr;
                justify-items: stretch;
            }
            .voortgang-header img { justify-self: start; }
            .voortgang-header-dates { justify-content: flex-start; }
            .voortgang-header-actions { justify-self: stretch; justify-content: flex-start; }
        }
    </style>
</head>
<body>
<div class="voortgang-page">
    <header class="voortgang-header">
        <img src="logo-website.png" alt="KVT">
        <?php if ($cache !== null): ?>
            <div class="voortgang-header-dates">
                <label>
                    <?= voortgang_h(LOC('voortgang.label.date_from')) ?>
                    <input type="date" id="voortgang-date-from" value="<?= voortgang_h($dateFrom) ?>"<?= $dateMin !== '' ? ' min="' . voortgang_h($dateMin) . '"' : '' ?><?= $dateMax !== '' ? ' max="' . voortgang_h($dateMax) . '"' : '' ?>>
                </label>
                <label>
                    <?= voortgang_h(LOC('voortgang.label.date_to')) ?>
                    <input type="date" id="voortgang-date-to" value="<?= voortgang_h($dateTo) ?>"<?= $dateMin !== '' ? ' min="' . voortgang_h($dateMin) . '"' : '' ?><?= $dateMax !== '' ? ' max="' . voortgang_h($dateMax) . '"' : '' ?>>
                </label>
            </div>
        <?php else: ?>
            <div></div>
        <?php endif; ?>
        <div class="voortgang-header-actions">
            <?php if ($cache !== null): ?>
                <button
                    type="button"
                    class="voortgang-settings-btn"
                    id="voortgang-settings-open"
                    aria-label="<?= voortgang_h(LOC('voortgang.settings.aria')) ?>"
                    title="<?= voortgang_h(LOC('voortgang.settings.aria')) ?>"
                >⚙</button>
                <a class="voortgang-excel" id="voortgang-excel-link" href="<?= voortgang_h($excelUrl) ?>"><?= voortgang_h(LOC('voortgang.btn.excel')) ?></a>
            <?php endif; ?>
            <?php if ($companies !== [] && !$needsCompanyChoice): ?>
                <form method="get" action="index.php">
                    <label class="voortgang-muted" style="display:grid;gap:6px;font-weight:700;">
                        <?= voortgang_h(LOC('voortgang.label.company')) ?>
                        <select name="company" onchange="this.form.submit()">
                            <?php foreach ($companies as $companyOption): ?>
                                <option value="<?= voortgang_h($companyOption) ?>"<?= $companyOption === $company ? ' selected' : '' ?>><?= voortgang_h($companyOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
            <?php renderLanguageSwitcher(); ?>
        </div>
    </header>

    <section class="voortgang-card">
        <h1 class="brand-display"><?= voortgang_h(LOC('voortgang.hero.title')) ?></h1>
        <p class="voortgang-subtitle"><?= voortgang_h(LOC('voortgang.hero.subtitle')) ?></p>
        <?php if ($cachedAtLabel !== ''): ?>
            <p class="voortgang-meta"><?= voortgang_h(LOC('voortgang.cached_at', $cachedAtLabel)) ?> · <span id="voortgang-row-count"><?= voortgang_h(LOC('voortgang.row_count', count($rows))) ?></span></p>
        <?php endif; ?>

        <div class="voortgang-form">
            <div class="voortgang-form-grid">
                <label>
                    <?= voortgang_h(LOC('voortgang.label.search')) ?>
                    <input type="search" id="voortgang-filter-search" placeholder="<?= voortgang_h(LOC('voortgang.placeholder.search')) ?>" autocomplete="off">
                </label>
                <button
                    type="button"
                    class="voortgang-progress-toggle"
                    id="voortgang-progress-toggle"
                    data-state="all"
                    aria-label="<?= voortgang_h(LOC('voortgang.label.progress_filter')) ?>"
                >
                    <span class="voortgang-progress-current" id="voortgang-progress-current"><?= voortgang_h(LOC('voortgang.filter.all')) ?></span>
                    <span class="voortgang-progress-side" data-side="completed"><?= voortgang_h(LOC('voortgang.filter.completed')) ?></span>
                    <span class="voortgang-progress-track" aria-hidden="true"><span class="voortgang-progress-knob"></span></span>
                    <span class="voortgang-progress-side" data-side="incomplete"><?= voortgang_h(LOC('voortgang.filter.incomplete')) ?></span>
                </button>
                <label>
                    <?= voortgang_h(LOC('voortgang.label.page_size')) ?>
                    <select id="voortgang-page-size">
                        <?php foreach ($voortgangPageSizeOptions as $sizeOption): ?>
                            <option value="<?= (int) $sizeOption ?>"<?= (int) $sizeOption === $savedPageSize ? ' selected' : '' ?>><?= (int) $sizeOption ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
        </div>
    </section>

    <?php if ($exportError !== ''): ?>
        <div class="voortgang-alert"><?= voortgang_h($exportError) ?></div>
    <?php endif; ?>

    <?php if (!$needsCompanyChoice && $cache === null): ?>
        <div class="voortgang-alert"><?= voortgang_h(LOC('voortgang.empty.cache')) ?></div>
    <?php elseif (!$needsCompanyChoice && $cacheStale): ?>
        <div class="voortgang-alert voortgang-alert-warn"><?= voortgang_h(LOC('voortgang.stale.cache')) ?></div>
    <?php endif; ?>

    <?php if ($cache !== null && $rowsRaw === []): ?>
        <section class="voortgang-card">
            <p class="voortgang-muted"><?= voortgang_h(LOC('voortgang.empty.rows')) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($rowsRaw !== []): ?>
        <section class="voortgang-card">
            <div class="voortgang-pager voortgang-pager-top" hidden>
                <div class="voortgang-pager-status"></div>
                <div class="voortgang-pager-controls">
                    <button type="button" class="voortgang-page-prev"><?= voortgang_h(LOC('voortgang.pager.prev')) ?></button>
                    <div class="voortgang-page-numbers" aria-label="<?= voortgang_h(LOC('voortgang.pager.pages')) ?>"></div>
                    <button type="button" class="voortgang-page-next"><?= voortgang_h(LOC('voortgang.pager.next')) ?></button>
                </div>
            </div>
            <div class="voortgang-table-wrap">
                <table class="voortgang-table" id="voortgang-table">
                    <thead>
                        <tr>
                            <th data-sort="contract_no" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.contract_no')) ?></th>
                            <th data-sort="description" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.description')) ?></th>
                            <th data-sort="invoice_period" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.invoice_period')) ?></th>
                            <?php foreach (VOORTGANG_STATUSES as $status): ?>
                                <?php $statusSortKey = 'status_' . strtolower($status); ?>
                                <th class="num" data-sort="<?= voortgang_h($statusSortKey) ?>" data-sort-type="number"><?= voortgang_h($status) ?></th>
                            <?php endforeach; ?>
                            <th class="num" data-sort="total" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.total')) ?></th>
                            <th class="num is-sorted-desc" data-sort="progress" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.progress')) ?></th>
                            <th class="num" data-sort="total_sales" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.original_amount')) ?></th>
                            <th class="num" data-sort="total_revenue" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.invoiced_amount')) ?></th>
                            <th class="num" data-sort="total_cost" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.total_cost')) ?></th>
                            <th class="num" data-sort="open_proforma" data-sort-type="number"><?= voortgang_h(LOC('voortgang.col.open_proforma')) ?></th>
                            <th data-sort="instructions" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.instructions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rowsRaw as $rawRow): ?>
                            <?php
                            if (!is_array($rawRow)) {
                                continue;
                            }
                            $filteredItems = voortgang_filter_workorder_items(
                                is_array($rawRow['workorders'] ?? null) ? $rawRow['workorders'] : [],
                                $hidePdTaskCode,
                                $dateFrom,
                                $dateTo
                            );
                            $agg = voortgang_aggregate_workorder_items($filteredItems);
                            $counts = $agg['counts'];
                            $revenue = voortgang_normalized_revenue((float) ($rawRow['total_revenue'] ?? 0));
                            $rowHidden = $agg['total'] <= 0;
                            ?>
                            <tr
                                class="<?= $rowHidden ? 'voortgang-row-hidden' : '' ?>"
                                data-contract="<?= voortgang_h((string) ($rawRow['contract_no'] ?? '')) ?>"
                                data-progress="<?= voortgang_h((string) $agg['progress']) ?>"
                                data-filter-empty="<?= $rowHidden ? '1' : '0' ?>"
                                data-search="<?= voortgang_h(voortgang_row_search_text($rawRow)) ?>"
                                data-sort-contract_no="<?= voortgang_h((string) ($rawRow['contract_no'] ?? '')) ?>"
                                data-sort-description="<?= voortgang_h((string) ($rawRow['description'] ?? '')) ?>"
                                data-sort-invoice_period="<?= voortgang_h((string) ($rawRow['invoice_period'] ?? '')) ?>"
                                <?php foreach (VOORTGANG_STATUSES as $status): ?>
                                    data-sort-status_<?= voortgang_h(strtolower($status)) ?>="<?= (int) ($counts[$status] ?? 0) ?>"
                                <?php endforeach; ?>
                                data-sort-total="<?= (int) $agg['total'] ?>"
                                data-sort-progress="<?= voortgang_h((string) $agg['progress']) ?>"
                                data-sort-total_sales="<?= voortgang_h((string) ((float) ($rawRow['total_sales'] ?? 0))) ?>"
                                data-sort-total_revenue="<?= voortgang_h((string) $revenue) ?>"
                                data-sort-total_cost="<?= voortgang_h((string) ((float) ($rawRow['total_cost'] ?? 0))) ?>"
                                data-sort-open_proforma="<?= voortgang_h((string) ((float) ($agg['proforma_total'] ?? 0))) ?>"
                                data-sort-instructions="<?= voortgang_h((string) ($rawRow['instructions'] ?? '')) ?>"
                            >
                                <td class="voortgang-contract-cell">
                                    <button
                                        type="button"
                                        class="voortgang-row-refresh"
                                        aria-label="<?= voortgang_h(LOC('voortgang.refresh.aria')) ?>"
                                        title="<?= voortgang_h(LOC('voortgang.refresh.aria')) ?>"
                                    >♻️</button>
                                    <?= voortgang_h((string) ($rawRow['contract_no'] ?? '')) ?>
                                </td>
                                <td><?= voortgang_h((string) ($rawRow['description'] ?? '')) ?></td>
                                <td><?= voortgang_h((string) ($rawRow['invoice_period'] ?? '')) ?></td>
                                <?php foreach (VOORTGANG_STATUSES as $status): ?>
                                    <?= voortgang_count_cell((int) ($counts[$status] ?? 0), $status) ?>
                                <?php endforeach; ?>
                                <?= voortgang_count_cell((int) $agg['total'], 'Totaal') ?>
                                <td class="num"><?= voortgang_h(voortgang_format_percent((float) $agg['progress'])) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money((float) ($rawRow['total_sales'] ?? 0))) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money($revenue)) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money((float) ($rawRow['total_cost'] ?? 0))) ?></td>
                                <?= voortgang_proforma_cell((float) ($agg['proforma_total'] ?? 0)) ?>
                                <td class="voortgang-instructions"><?= voortgang_h((string) ($rawRow['instructions'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="voortgang-pager voortgang-pager-bottom" hidden>
                <div class="voortgang-pager-status"></div>
                <div class="voortgang-pager-controls">
                    <button type="button" class="voortgang-page-prev"><?= voortgang_h(LOC('voortgang.pager.prev')) ?></button>
                    <div class="voortgang-page-numbers" aria-label="<?= voortgang_h(LOC('voortgang.pager.pages')) ?>"></div>
                    <button type="button" class="voortgang-page-next"><?= voortgang_h(LOC('voortgang.pager.next')) ?></button>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<div id="voortgang-modal-backdrop" class="voortgang-modal-backdrop" aria-hidden="true">
    <div class="voortgang-modal" role="dialog" aria-modal="true" aria-labelledby="voortgang-modal-title">
        <div class="voortgang-modal-header">
            <h2 id="voortgang-modal-title"><?= voortgang_h(LOC('voortgang.modal.title')) ?></h2>
            <button type="button" class="voortgang-modal-close" id="voortgang-modal-close" aria-label="<?= voortgang_h(LOC('voortgang.modal.close')) ?>">&times;</button>
        </div>
        <div class="voortgang-table-wrap" id="voortgang-modal-body"></div>
    </div>
</div>

<div id="voortgang-proforma-lines-backdrop" class="voortgang-modal-backdrop" aria-hidden="true">
    <div class="voortgang-modal" role="dialog" aria-modal="true" aria-labelledby="voortgang-proforma-lines-title">
        <div class="voortgang-modal-header">
            <h2 id="voortgang-proforma-lines-title"><?= voortgang_h(LOC('voortgang.modal.proforma_lines_title')) ?></h2>
            <button type="button" class="voortgang-modal-close" id="voortgang-proforma-lines-close" aria-label="<?= voortgang_h(LOC('voortgang.modal.close')) ?>">&times;</button>
        </div>
        <div class="voortgang-table-wrap" id="voortgang-proforma-lines-body"></div>
    </div>
</div>

<div
    id="voortgang-company-pick-backdrop"
    class="voortgang-modal-backdrop<?= $needsCompanyChoice ? ' is-open is-locked' : '' ?>"
    aria-hidden="<?= $needsCompanyChoice ? 'false' : 'true' ?>"
>
    <div class="voortgang-modal" role="dialog" aria-modal="true" aria-labelledby="voortgang-company-pick-title">
        <div class="voortgang-modal-header">
            <h2 id="voortgang-company-pick-title"><?= voortgang_h(LOC('voortgang.company_pick.title')) ?></h2>
        </div>
        <p class="voortgang-modal-body-text"><?= voortgang_h(LOC('voortgang.company_pick.body')) ?></p>
        <div class="voortgang-company-list">
            <?php foreach ($companies as $companyOption): ?>
                <button
                    type="button"
                    class="voortgang-company-option"
                    data-company="<?= voortgang_h($companyOption) ?>"
                ><?= voortgang_h($companyOption) ?></button>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div
    id="voortgang-company-welcome-backdrop"
    class="voortgang-modal-backdrop<?= $showCompanyWelcome ? ' is-open' : '' ?>"
    aria-hidden="<?= $showCompanyWelcome ? 'false' : 'true' ?>"
>
    <div class="voortgang-modal" role="dialog" aria-modal="true" aria-labelledby="voortgang-company-welcome-title">
        <div class="voortgang-modal-header">
            <h2 id="voortgang-company-welcome-title"><?= voortgang_h(LOC('voortgang.company_welcome.title')) ?></h2>
            <button type="button" class="voortgang-modal-close" id="voortgang-company-welcome-close" aria-label="<?= voortgang_h(LOC('voortgang.modal.close')) ?>">&times;</button>
        </div>
        <p class="voortgang-modal-body-text"><?= voortgang_h(LOC('voortgang.company_welcome.body')) ?></p>
    </div>
</div>

<div id="voortgang-settings-backdrop" class="voortgang-modal-backdrop" aria-hidden="true">
    <div class="voortgang-modal" role="dialog" aria-modal="true" aria-labelledby="voortgang-settings-title">
        <div class="voortgang-modal-header">
            <h2 id="voortgang-settings-title"><?= voortgang_h(LOC('voortgang.settings.title')) ?></h2>
            <button type="button" class="voortgang-modal-close" id="voortgang-settings-close" aria-label="<?= voortgang_h(LOC('voortgang.modal.close')) ?>">&times;</button>
        </div>
        <label class="voortgang-settings-option">
            <input type="checkbox" id="voortgang-setting-hide-pd"<?= $hidePdTaskCode ? ' checked' : '' ?>>
            <span><?= voortgang_h(LOC('voortgang.settings.hide_pd')) ?></span>
        </label>
    </div>
</div>

<?php renderLanguageSwitcherScript(); ?>
<script>
(function () {
    var searchFilter = document.getElementById('voortgang-filter-search');
    var pageSizeSelect = document.getElementById('voortgang-page-size');
    var progressToggle = document.getElementById('voortgang-progress-toggle');
    var progressCurrent = document.getElementById('voortgang-progress-current');
    var pagers = Array.prototype.slice.call(document.querySelectorAll('.voortgang-pager'));
    var table = document.getElementById('voortgang-table');
    var rowCount = document.getElementById('voortgang-row-count');
    var backdrop = document.getElementById('voortgang-modal-backdrop');
    var modalTitle = document.getElementById('voortgang-modal-title');
    var modalBody = document.getElementById('voortgang-modal-body');
    var modalClose = document.getElementById('voortgang-modal-close');
    var linesBackdrop = document.getElementById('voortgang-proforma-lines-backdrop');
    var linesTitle = document.getElementById('voortgang-proforma-lines-title');
    var linesBody = document.getElementById('voortgang-proforma-lines-body');
    var linesClose = document.getElementById('voortgang-proforma-lines-close');
    var proformaBreakdownCache = {};
    var openProformaContract = '';
    var companyPickBackdrop = document.getElementById('voortgang-company-pick-backdrop');
    var companyWelcomeBackdrop = document.getElementById('voortgang-company-welcome-backdrop');
    var companyWelcomeClose = document.getElementById('voortgang-company-welcome-close');
    var settingsBackdrop = document.getElementById('voortgang-settings-backdrop');
    var settingsOpen = document.getElementById('voortgang-settings-open');
    var settingsClose = document.getElementById('voortgang-settings-close');
    var hidePdCheckbox = document.getElementById('voortgang-setting-hide-pd');
    var dateFromInput = document.getElementById('voortgang-date-from');
    var dateToInput = document.getElementById('voortgang-date-to');
    var excelLink = document.getElementById('voortgang-excel-link');
    var needsCompanyChoice = <?= $needsCompanyChoice ? 'true' : 'false' ?>;
    var pageSize = <?= (int) $savedPageSize ?>;
    var currentPage = 1;
    var sortKey = 'progress';
    var sortDir = 'desc';
    var progressStates = ['completed', 'all', 'incomplete'];
    var progressState = 'all';
    var contractData = <?= $contractDataJson ?>;
    var hidePdTaskCode = <?= $hidePdTaskCode ? 'true' : 'false' ?>;
    var dateMinBound = <?= json_encode($dateMin, JSON_UNESCAPED_UNICODE) ?>;
    var dateMaxBound = <?= json_encode($dateMax, JSON_UNESCAPED_UNICODE) ?>;
    var dateFrom = <?= json_encode($dateFrom, JSON_UNESCAPED_UNICODE) ?>;
    var dateTo = <?= json_encode($dateTo, JSON_UNESCAPED_UNICODE) ?>;
    var progressStatuses = <?= json_encode(array_values(VOORTGANG_PROGRESS_STATUSES), JSON_UNESCAPED_UNICODE) ?>;
    var pdTaskCode = <?= json_encode(VOORTGANG_HIDDEN_TASK_CODE_PD, JSON_UNESCAPED_UNICODE) ?>;
    var refreshInFlight = {};
    var labels = <?= json_encode([
        'row_count' => LOC('voortgang.row_count'),
        'page_status' => LOC('voortgang.pager.status'),
        'modal_title' => LOC('voortgang.modal.title'),
        'workorder_no' => LOC('voortgang.col.workorder_no'),
        'status' => LOC('voortgang.col.status'),
        'empty' => LOC('voortgang.modal.empty'),
        'proforma_title' => LOC('voortgang.modal.proforma_title'),
        'proforma_empty' => LOC('voortgang.modal.proforma_empty'),
        'proforma_no' => LOC('voortgang.col.proforma_no'),
        'proforma_amount' => LOC('voortgang.col.proforma_amount'),
        'proforma_amount_plain' => LOC('voortgang.col.amount'),
        'proforma_no_contract' => LOC('voortgang.col.proforma_no_contract'),
        'proforma_other_tooltip' => LOC('voortgang.proforma.other_tooltip'),
        'proforma_unassigned_tooltip' => LOC('voortgang.proforma.unassigned_tooltip'),
        'proforma_this_progress' => LOC('voortgang.proforma.this_progress'),
        'proforma_other_progress' => LOC('voortgang.proforma.other_progress'),
        'proforma_lines_title' => LOC('voortgang.modal.proforma_lines_title'),
        'contract_no' => LOC('voortgang.col.contract_no'),
        'modal_loading' => LOC('voortgang.modal.loading'),
        'total' => LOC('voortgang.col.total'),
        'filter_all' => LOC('voortgang.filter.all'),
        'filter_completed' => LOC('voortgang.filter.completed'),
        'filter_incomplete' => LOC('voortgang.filter.incomplete'),
        'refresh_aria' => LOC('voortgang.refresh.aria'),
        'refresh_failed' => LOC('voortgang.refresh.failed'),
        'refresh_removed' => LOC('voortgang.refresh.removed'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    var companyName = <?= json_encode($company, JSON_UNESCAPED_UNICODE) ?>;
    var statusList = <?= json_encode(array_values(VOORTGANG_STATUSES), JSON_UNESCAPED_UNICODE) ?>;
    var bcWeb = <?= json_encode($bcWebClient, JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function bcRecordUrl(pageId, recordNo) {
        var base = bcWeb && bcWeb.base ? String(bcWeb.base) : '';
        var no = String(recordNo || '');
        var page = Number(pageId || 0);
        if (!base || !no || !page) {
            return '';
        }
        var filter = "'No.' IS '" + no.replace(/'/g, "''") + "'";
        return base
            + '?company=' + encodeURIComponent(bcWeb.company || '')
            + '&page=' + encodeURIComponent(String(page))
            + '&filter=' + encodeURIComponent(filter);
    }

    function bcLinkHtml(pageId, recordNo) {
        var no = String(recordNo || '');
        if (!no) {
            return '';
        }
        var url = bcRecordUrl(pageId, no);
        if (!url) {
            return escapeHtml(no);
        }
        return '<a class="voortgang-bc-link" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">'
            + escapeHtml(no) + '</a>';
    }

    function formatMoneyJs(value) {
        var amount = Number(value);
        if (!isFinite(amount)) {
            amount = 0;
        }
        if (amount < 0) {
            amount = -amount;
        }
        return '€ ' + amount.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatPercentJs(value) {
        var amount = Number(value);
        if (!isFinite(amount)) {
            amount = 0;
        }
        if (Math.abs(amount - Math.round(amount)) < 0.05) {
            return Math.round(amount).toLocaleString('nl-NL') + '%';
        }
        return amount.toLocaleString('nl-NL', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    }

    function normalizedRevenueJs(value) {
        var amount = Number(value);
        if (!isFinite(amount)) {
            amount = 0;
        }
        return amount < 0 ? -amount : amount;
    }

    function countCellHtml(count, status) {
        var n = parseInt(count, 10) || 0;
        if (n <= 0) {
            return '<td class="num">0</td>';
        }
        return '<td class="num"><button type="button" class="voortgang-count" data-status="'
            + escapeHtml(status) + '">' + n + '</button></td>';
    }

    function proformaCellHtml(amount) {
        var value = Number(amount);
        if (!isFinite(value) || value <= 0) {
            return '<td class="num">-</td>';
        }
        return '<td class="num"><button type="button" class="voortgang-proforma">'
            + escapeHtml(formatMoneyJs(value)) + '</button></td>';
    }

    function rowSearchText(row) {
        return [
            row.contract_no || '',
            row.description || '',
            row.invoice_period || '',
            row.instructions || '',
            formatMoneyJs(row.total_sales || 0),
            formatMoneyJs(normalizedRevenueJs(row.total_revenue || 0)),
            formatMoneyJs(row.total_cost || 0),
            formatMoneyJs(row.open_proforma || 0)
        ].join(' ').toLowerCase();
    }

    function setBusyState(row, button, busy) {
        if (row) {
            row.classList.toggle('is-refreshing', !!busy);
        }
        if (button) {
            button.classList.toggle('is-busy', !!busy);
            button.disabled = !!busy;
        }
    }

    function applyRowPayload(tr, row) {
        var contractNo = row.contract_no || '';
        Object.keys(proformaBreakdownCache).forEach(function (key) {
            if (key === contractNo || key.indexOf(contractNo + '\n') === 0) {
                delete proformaBreakdownCache[key];
            }
        });
        contractData[contractNo] = {
            contract_no: contractNo,
            description: row.description || '',
            invoice_period: row.invoice_period || '',
            total_sales: Number(row.total_sales || 0),
            total_revenue: Number(row.total_revenue || 0),
            total_cost: Number(row.total_cost || 0),
            open_proforma: Number(row.open_proforma || 0),
            instructions: row.instructions || '',
            items: Array.isArray(row.workorders) ? row.workorders : []
        };
        renderContractRow(tr, contractNo);
    }

    function filterItems(items) {
        var list = Array.isArray(items) ? items : [];
        var out = [];
        list.forEach(function (item) {
            if (!item) {
                return;
            }
            var taskCode = String(item.task_code || '');
            if (hidePdTaskCode && taskCode.toUpperCase() === String(pdTaskCode || 'PD').toUpperCase()) {
                return;
            }
            var startDate = String(item.start_date || '');
            if (startDate) {
                if (dateFrom && startDate < dateFrom) {
                    return;
                }
                if (dateTo && startDate > dateTo) {
                    return;
                }
            }
            out.push({
                no: item.no || '',
                status: String(item.status || ''),
                task_code: taskCode,
                start_date: startDate,
                proforma_amount: Number(item.proforma_amount || 0),
                proformas: Array.isArray(item.proformas) ? item.proformas : []
            });
        });
        return out;
    }

    function aggregateItems(items) {
        var counts = {};
        statusList.forEach(function (status) {
            counts[status] = 0;
        });
        var total = 0;
        var proformaTotal = 0;
        items.forEach(function (item) {
            if (!item || !item.no) {
                return;
            }
            total += 1;
            var status = String(item.status || '');
            if (Object.prototype.hasOwnProperty.call(counts, status)) {
                counts[status] += 1;
            }
            var proformaAmount = Number(item.proforma_amount || 0);
            if (isFinite(proformaAmount)) {
                proformaTotal += proformaAmount;
            }
        });
        var done = 0;
        progressStatuses.forEach(function (status) {
            done += counts[status] || 0;
        });
        var progress = total > 0 ? Math.round((done / total) * 1000) / 10 : 0;
        return { counts: counts, total: total, progress: progress, proforma_total: proformaTotal };
    }

    function renderContractRow(tr, contractNo) {
        var data = contractData[contractNo];
        if (!data || !tr) {
            return;
        }
        var filtered = filterItems(data.items || []);
        var agg = aggregateItems(filtered);
        var revenue = normalizedRevenueJs(data.total_revenue || 0);
        var empty = agg.total <= 0;
        data.open_proforma = agg.proforma_total || 0;
        var html = '';

        tr.setAttribute('data-contract', contractNo);
        tr.setAttribute('data-progress', String(agg.progress));
        tr.setAttribute('data-filter-empty', empty ? '1' : '0');
        tr.setAttribute('data-search', rowSearchText(data));
        tr.setAttribute('data-sort-contract_no', contractNo);
        tr.setAttribute('data-sort-description', data.description || '');
        tr.setAttribute('data-sort-invoice_period', data.invoice_period || '');
        statusList.forEach(function (status) {
            tr.setAttribute('data-sort-status_' + String(status).toLowerCase(), String(agg.counts[status] || 0));
        });
        tr.setAttribute('data-sort-total', String(agg.total));
        tr.setAttribute('data-sort-progress', String(agg.progress));
        tr.setAttribute('data-sort-total_sales', String(Number(data.total_sales || 0)));
        tr.setAttribute('data-sort-total_revenue', String(revenue));
        tr.setAttribute('data-sort-total_cost', String(Number(data.total_cost || 0)));
        tr.setAttribute('data-sort-open_proforma', String(Number(agg.proforma_total || 0)));
        tr.setAttribute('data-sort-instructions', data.instructions || '');

        html += '<td class="voortgang-contract-cell">';
        html += '<button type="button" class="voortgang-row-refresh" aria-label="' + escapeHtml(labels.refresh_aria) + '" title="' + escapeHtml(labels.refresh_aria) + '">♻️</button>';
        html += escapeHtml(contractNo);
        html += '</td>';
        html += '<td>' + escapeHtml(data.description || '') + '</td>';
        html += '<td>' + escapeHtml(data.invoice_period || '') + '</td>';
        statusList.forEach(function (status) {
            html += countCellHtml(agg.counts[status] || 0, status);
        });
        html += countCellHtml(agg.total, 'Totaal');
        html += '<td class="num">' + escapeHtml(formatPercentJs(agg.progress)) + '</td>';
        html += '<td class="num">' + escapeHtml(formatMoneyJs(data.total_sales || 0)) + '</td>';
        html += '<td class="num">' + escapeHtml(formatMoneyJs(revenue)) + '</td>';
        html += '<td class="num">' + escapeHtml(formatMoneyJs(data.total_cost || 0)) + '</td>';
        html += proformaCellHtml(agg.proforma_total || 0);
        html += '<td class="voortgang-instructions">' + escapeHtml(data.instructions || '') + '</td>';
        tr.innerHTML = html;
    }

    function recomputeAllRowsFromFilters() {
        getAllRows().forEach(function (tr) {
            var contractNo = tr.getAttribute('data-contract') || '';
            if (contractNo) {
                renderContractRow(tr, contractNo);
            }
        });
        updateExcelLink();
        applyFilters(true);
    }

    function updateExcelLink() {
        if (!excelLink || !companyName) {
            return;
        }
        excelLink.href = 'index.php?export=excel&company=' + encodeURIComponent(companyName)
            + '&date_from=' + encodeURIComponent(dateFrom || '')
            + '&date_to=' + encodeURIComponent(dateTo || '');
    }

    function syncDateInputs() {
        if (dateFromInput) {
            dateFromInput.value = dateFrom || '';
        }
        if (dateToInput) {
            dateToInput.value = dateTo || '';
        }
    }

    function onDateRangeChange() {
        var nextFrom = dateFromInput ? (dateFromInput.value || '') : dateFrom;
        var nextTo = dateToInput ? (dateToInput.value || '') : dateTo;
        if (nextFrom && nextTo && nextFrom > nextTo) {
            if (dateFromInput && dateFromInput === document.activeElement) {
                nextTo = nextFrom;
            } else {
                nextFrom = nextTo;
            }
        }
        if (dateMinBound && nextFrom && nextFrom < dateMinBound) {
            nextFrom = dateMinBound;
        }
        if (dateMaxBound && nextTo && nextTo > dateMaxBound) {
            nextTo = dateMaxBound;
        }
        dateFrom = nextFrom;
        dateTo = nextTo;
        syncDateInputs();
        recomputeAllRowsFromFilters();
    }

    function saveHidePdSetting(enabled) {
        hidePdTaskCode = !!enabled;
        var url = 'index.php?action=save_settings&hide_pd_task_code=' + (hidePdTaskCode ? '1' : '0');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).catch(function () {});
        recomputeAllRowsFromFilters();
    }

    function openSettings() {
        if (!settingsBackdrop) {
            return;
        }
        settingsBackdrop.classList.add('is-open');
        settingsBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeSettings() {
        if (!settingsBackdrop) {
            return;
        }
        settingsBackdrop.classList.remove('is-open');
        settingsBackdrop.setAttribute('aria-hidden', 'true');
    }

    function refreshContractRow(tr) {
        var contractNo = tr.getAttribute('data-contract') || '';
        if (!contractNo || !companyName) {
            return;
        }
        if (refreshInFlight[contractNo]) {
            return;
        }

        var button = tr.querySelector('.voortgang-row-refresh');
        refreshInFlight[contractNo] = true;
        setBusyState(tr, button, true);

        var url = 'refresh_contract.php?company=' + encodeURIComponent(companyName)
            + '&contract=' + encodeURIComponent(contractNo)
            + '&_t=' + Date.now();

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (e) {
                    data = null;
                }
                return { okHttp: response.ok, data: data };
            });
        }).then(function (result) {
            var data = result.data || {};
            if (!data.ok) {
                window.alert((data && data.error) ? data.error : labels.refresh_failed);
                return;
            }
            if (data.removed) {
                delete contractData[contractNo];
                if (tr.parentNode) {
                    tr.parentNode.removeChild(tr);
                }
                window.alert(labels.refresh_removed);
                applyFilters(false);
                return;
            }
            if (!data.row) {
                window.alert(labels.refresh_failed);
                return;
            }
            applyRowPayload(tr, data.row);
            applyFilters(false);
        }).catch(function () {
            window.alert(labels.refresh_failed);
        }).then(function () {
            delete refreshInFlight[contractNo];
            var freshButton = tr.querySelector ? tr.querySelector('.voortgang-row-refresh') : null;
            setBusyState(tr, freshButton, false);
        });
    }

    function getAllRows() {
        if (!table || !table.tBodies[0]) {
            return [];
        }
        return Array.prototype.slice.call(table.tBodies[0].rows);
    }

    function getHeaderForSort(key) {
        if (!table) {
            return null;
        }
        return table.querySelector('thead th[data-sort="' + key + '"]');
    }

    function syncSortHeaders() {
        if (!table) {
            return;
        }
        Array.prototype.forEach.call(table.querySelectorAll('thead th[data-sort]'), function (th) {
            th.classList.remove('is-sorted-asc', 'is-sorted-desc');
            if (th.getAttribute('data-sort') === sortKey) {
                th.classList.add(sortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
            }
        });
    }

    function compareRows(a, b) {
        var header = getHeaderForSort(sortKey);
        var sortType = header ? (header.getAttribute('data-sort-type') || 'text') : 'text';
        var attr = 'data-sort-' + sortKey;
        var left = a.getAttribute(attr) || '';
        var right = b.getAttribute(attr) || '';
        var cmp = 0;

        if (sortType === 'number') {
            var leftNum = parseFloat(left);
            var rightNum = parseFloat(right);
            if (isNaN(leftNum)) {
                leftNum = 0;
            }
            if (isNaN(rightNum)) {
                rightNum = 0;
            }
            cmp = leftNum === rightNum ? 0 : (leftNum < rightNum ? -1 : 1);
        } else {
            cmp = left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
        }

        if (cmp === 0) {
            var leftContract = a.getAttribute('data-sort-contract_no') || '';
            var rightContract = b.getAttribute('data-sort-contract_no') || '';
            cmp = leftContract.localeCompare(rightContract, undefined, { numeric: true, sensitivity: 'base' });
        }

        return sortDir === 'asc' ? cmp : -cmp;
    }

    function sortRowsInPlace(rows) {
        rows.sort(compareRows);
        if (!table || !table.tBodies[0]) {
            return;
        }
        var body = table.tBodies[0];
        rows.forEach(function (row) {
            body.appendChild(row);
        });
    }

    function rowMatchesProgress(row) {
        var progress = parseFloat(row.getAttribute('data-progress') || '0');
        if (isNaN(progress)) {
            progress = 0;
        }
        if (progressState === 'completed') {
            return progress >= 99.95;
        }
        if (progressState === 'incomplete') {
            return progress < 99.95;
        }
        return true;
    }

    function rowMatchesFilters(row) {
        if ((row.getAttribute('data-filter-empty') || '0') === '1') {
            return false;
        }
        var searchValue = (searchFilter && searchFilter.value ? searchFilter.value : '').trim().toLowerCase();
        var searchText = (row.getAttribute('data-search') || '').toLowerCase();
        if (searchValue !== '' && searchText.indexOf(searchValue) === -1) {
            return false;
        }
        return rowMatchesProgress(row);
    }

    function syncProgressToggle() {
        if (!progressToggle) {
            return;
        }
        progressToggle.setAttribute('data-state', progressState);
        if (progressCurrent) {
            if (progressState === 'completed') {
                progressCurrent.textContent = labels.filter_completed;
            } else if (progressState === 'incomplete') {
                progressCurrent.textContent = labels.filter_incomplete;
            } else {
                progressCurrent.textContent = labels.filter_all;
            }
        }
        progressToggle.querySelectorAll('.voortgang-progress-side').forEach(function (side) {
            var sideState = side.getAttribute('data-side') || '';
            side.classList.toggle('is-active', sideState === progressState);
        });
    }

    function cycleProgressState() {
        var index = progressStates.indexOf(progressState);
        if (index < 0) {
            index = 1;
        }
        progressState = progressStates[(index + 1) % progressStates.length];
        syncProgressToggle();
        applyFilters(true);
    }

    function buildPageItems(current, last) {
        var pages = {};
        var i;
        pages[1] = true;
        pages[last] = true;
        for (i = current - 3; i <= current + 3; i++) {
            if (i >= 1 && i <= last) {
                pages[i] = true;
            }
        }

        var sorted = Object.keys(pages).map(Number).sort(function (a, b) { return a - b; });
        var items = [];
        for (i = 0; i < sorted.length; i++) {
            if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                items.push('ellipsis');
            }
            items.push(sorted[i]);
        }
        return items;
    }

    function renderPagerNumbers(container, current, last) {
        if (!container) {
            return;
        }
        var html = '';
        buildPageItems(current, last).forEach(function (item) {
            if (item === 'ellipsis') {
                html += '<span class="voortgang-page-ellipsis">…</span>';
                return;
            }
            var isCurrent = item === current;
            html += '<button type="button" class="voortgang-page-num' + (isCurrent ? ' is-current' : '') + '"'
                + ' data-page="' + item + '"'
                + (isCurrent ? ' aria-current="page" disabled' : '')
                + '>' + item + '</button>';
        });
        container.innerHTML = html;
    }

    function updatePagers(total, pageCount) {
        var statusText = labels.page_status
            ? String(labels.page_status)
                .replace('%1$d', String(currentPage))
                .replace('%2$d', String(pageCount))
                .replace('%3$d', String(total))
            : '';

        pagers.forEach(function (pagerEl) {
            pagerEl.hidden = total === 0;
            var status = pagerEl.querySelector('.voortgang-pager-status');
            var prev = pagerEl.querySelector('.voortgang-page-prev');
            var next = pagerEl.querySelector('.voortgang-page-next');
            var numbers = pagerEl.querySelector('.voortgang-page-numbers');
            if (status) {
                status.textContent = statusText;
            }
            if (prev) {
                prev.disabled = currentPage <= 1;
            }
            if (next) {
                next.disabled = currentPage >= pageCount || total === 0;
            }
            renderPagerNumbers(numbers, currentPage, pageCount);
        });
    }

    function applyFilters(resetPage) {
        if (!table) {
            return;
        }
        if (resetPage) {
            currentPage = 1;
        }

        var rows = getAllRows();
        sortRowsInPlace(rows);
        rows = getAllRows();
        var matching = rows.filter(rowMatchesFilters);
        var total = matching.length;
        var size = pageSize > 0 ? pageSize : total;
        var pageCount = size > 0 ? Math.max(1, Math.ceil(total / size)) : 1;
        if (currentPage > pageCount) {
            currentPage = pageCount;
        }
        if (currentPage < 1) {
            currentPage = 1;
        }

        var start = (currentPage - 1) * pageSize;
        var end = start + pageSize;

        rows.forEach(function (row) {
            row.classList.add('voortgang-row-hidden');
        });
        matching.forEach(function (row, index) {
            if (index >= start && index < end) {
                row.classList.remove('voortgang-row-hidden');
            }
        });

        if (rowCount && labels.row_count) {
            rowCount.textContent = String(labels.row_count).replace('%d', String(total));
        }

        syncSortHeaders();
        updatePagers(total, pageCount);
    }

    function setSortFromHeader(th) {
        var key = th.getAttribute('data-sort') || '';
        var type = th.getAttribute('data-sort-type') || 'text';
        if (key === '') {
            return;
        }
        if (sortKey === key) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey = key;
            sortDir = type === 'number' ? 'desc' : 'asc';
        }
        applyFilters(true);
    }

    function savePageSize(size) {
        var url = 'index.php?action=save_page_size&page_size=' + encodeURIComponent(String(size));
        if (window.fetch) {
            fetch(url, { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
        }
    }

    function closeLinesModal() {
        if (!linesBackdrop) {
            return;
        }
        linesBackdrop.classList.remove('is-open');
        linesBackdrop.setAttribute('aria-hidden', 'true');
    }

    function closeModal() {
        closeLinesModal();
        if (!backdrop) {
            return;
        }
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
        openProformaContract = '';
    }

    function closeCompanyWelcome() {
        if (!companyWelcomeBackdrop) {
            return;
        }
        companyWelcomeBackdrop.classList.remove('is-open');
        companyWelcomeBackdrop.setAttribute('aria-hidden', 'true');
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('welcome');
            window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
        }
    }

    function selectCompany(companyName) {
        if (!companyName) {
            return;
        }
        window.location.href = 'index.php?action=save_company&company=' + encodeURIComponent(companyName);
    }

    function workordersFor(contractNo, status) {
        var entry = contractData[contractNo] || {};
        var filtered = filterItems(entry.items || []);
        var items = [];
        filtered.forEach(function (item) {
            var itemStatus = String(item.status || '');
            if (status !== 'Totaal' && itemStatus !== status) {
                return;
            }
            items.push({ no: item.no || '', status: itemStatus });
        });
        items.sort(function (a, b) {
            return String(a.no).localeCompare(String(b.no), undefined, { numeric: true, sensitivity: 'base' });
        });
        return items;
    }

    function formatLabel(template, value) {
        return String(template || '').replace('%s', String(value == null ? '' : value));
    }

    function proformaWorkorderNos(contractNo) {
        var filtered = filterItems((contractData[contractNo] || {}).items || []);
        var nos = [];
        filtered.forEach(function (item) {
            if (item && item.no && Number(item.proforma_amount || 0) > 0) {
                nos.push(item.no);
            }
        });
        return nos;
    }

    function proformaCacheKey(contractNo, nos) {
        return contractNo + '\n' + nos.slice().sort().join(',');
    }

    function otherContractLabel(key) {
        return key === '' ? (labels.proforma_no_contract || '') : key;
    }

    function otherContractTooltip(key) {
        if (key === '') {
            return labels.proforma_unassigned_tooltip || '';
        }
        return formatLabel(labels.proforma_other_tooltip || '', key);
    }

    function otherContractKeys(breakdown) {
        if (breakdown && Array.isArray(breakdown.other_contracts) && breakdown.other_contracts.length) {
            return breakdown.other_contracts.slice();
        }
        var seen = {};
        ((breakdown && breakdown.items) || []).forEach(function (item) {
            Object.keys((item && item.others) || {}).forEach(function (key) {
                seen[key] = true;
            });
        });
        var keys = Object.keys(seen);
        keys.sort(function (a, b) {
            if (a === '' && b !== '') {
                return 1;
            }
            if (b === '' && a !== '') {
                return -1;
            }
            return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
        });
        return keys;
    }

    function amountButtonHtml(amount, attrs) {
        var value = Number(amount);
        if (!isFinite(value) || value <= 0) {
            return '<span class="voortgang-muted">-</span>';
        }
        return '<button type="button" class="voortgang-proforma-amount" ' + attrs + '>'
            + escapeHtml(formatMoneyJs(value)) + '</button>';
    }

    function progressFromLines(lines) {
        var seen = {};
        var total = 0;
        var done = 0;
        (lines || []).forEach(function (line) {
            var no = String((line && line.no) || '');
            if (!no || seen[no]) {
                return;
            }
            seen[no] = true;
            total += 1;
            if (progressStatuses.indexOf(String(line.status || '')) !== -1) {
                done += 1;
            }
        });
        if (total <= 0) {
            return 0;
        }
        return Math.round((done / total) * 1000) / 10;
    }

    function linesForAmountCell(item, group, otherContract) {
        return ((item && item.lines) || []).filter(function (line) {
            if (!line) {
                return false;
            }
            if (group === 'this') {
                return !!line.this_contract;
            }
            return !line.this_contract && String(line.contract_no || '') === String(otherContract || '');
        });
    }

    function renderProformaSummary(breakdown, otherKeys) {
        var html = '<p class="voortgang-proforma-summary">';
        html += escapeHtml(formatLabel(labels.proforma_this_progress || '', formatPercentJs(breakdown.this_progress || 0)));
        if (otherKeys.length) {
            var otherProgress = breakdown.other_progress;
            if (otherProgress == null) {
                var otherLines = [];
                (breakdown.items || []).forEach(function (item) {
                    (item.lines || []).forEach(function (line) {
                        if (line && !line.this_contract) {
                            otherLines.push(line);
                        }
                    });
                });
                otherProgress = progressFromLines(otherLines);
            }
            html += ' ' + escapeHtml(formatLabel(labels.proforma_other_progress || '', formatPercentJs(otherProgress)));
        }
        html += '</p>';
        return html;
    }

    function renderProformaTable(contractNo, breakdown, loading) {
        if (!modalBody) {
            return;
        }
        if (modalTitle) {
            modalTitle.textContent = labels.proforma_title + ' · ' + contractNo;
        }
        if (loading) {
            modalBody.innerHTML = '<p class="voortgang-muted">' + escapeHtml(labels.modal_loading || '…') + '</p>';
            return;
        }
        var items = (breakdown && Array.isArray(breakdown.items)) ? breakdown.items : [];
        if (items.length === 0) {
            modalBody.innerHTML = '<p class="voortgang-muted">' + escapeHtml(labels.proforma_empty) + '</p>';
            return;
        }
        var otherKeys = otherContractKeys(breakdown);
        var html = renderProformaSummary(breakdown || {}, otherKeys);
        html += '<table class="voortgang-modal-table"><thead><tr>';
        html += '<th>' + escapeHtml(labels.proforma_no || labels.workorder_no) + '</th>';
        html += '<th class="num">' + escapeHtml(labels.proforma_amount) + '</th>';
        otherKeys.forEach(function (key) {
            html += '<th class="num" title="' + escapeHtml(otherContractTooltip(key)) + '">'
                + escapeHtml(otherContractLabel(key)) + '</th>';
        });
        html += '</tr></thead><tbody>';
        items.forEach(function (item) {
            var pfNo = String((item && item.no) || '');
            html += '<tr><td>' + bcLinkHtml(bcWeb.invoice_page, pfNo) + '</td>';
            html += '<td class="num">' + amountButtonHtml(item.this_amount, 'data-proforma="'
                + escapeHtml(pfNo) + '" data-group="this"') + '</td>';
            otherKeys.forEach(function (key) {
                var otherAmount = Number(((item.others || {})[key]) || 0);
                html += '<td class="num" title="' + escapeHtml(otherContractTooltip(key)) + '">'
                    + amountButtonHtml(otherAmount, 'data-proforma="' + escapeHtml(pfNo)
                        + '" data-group="other" data-other-contract="' + escapeHtml(key) + '"')
                    + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        modalBody.innerHTML = html;
    }

    function fetchProformaBreakdown(contractNo) {
        var nos = proformaWorkorderNos(contractNo);
        var url = 'refresh_contract.php?mode=proforma&company=' + encodeURIComponent(companyName)
            + '&contract=' + encodeURIComponent(contractNo)
            + '&_t=' + Date.now();
        if (nos.length) {
            url += '&workorders=' + encodeURIComponent(nos.join(','));
        }
        return fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.text().then(function (text) {
                var data = null;
                try {
                    var jsonStart = text ? text.indexOf('{') : -1;
                    data = jsonStart >= 0 ? JSON.parse(text.slice(jsonStart)) : null;
                } catch (e) {
                    data = null;
                }
                return { okHttp: response.ok, data: data };
            });
        }).then(function (result) {
            var data = result.data || {};
            if (!data.ok || !Array.isArray(data.items)) {
                return null;
            }
            return {
                this_progress: Number(data.this_progress || 0),
                other_progress: data.other_progress == null ? null : Number(data.other_progress),
                other_contracts: Array.isArray(data.other_contracts) ? data.other_contracts : [],
                items: data.items
            };
        });
    }

    function openProforma(contractNo) {
        if (!modalBody || !backdrop) {
            return;
        }
        openProformaContract = contractNo;
        var cacheKey = proformaCacheKey(contractNo, proformaWorkorderNos(contractNo));
        var cached = proformaBreakdownCache[cacheKey];
        if (cached) {
            renderProformaTable(contractNo, cached, false);
            backdrop.classList.add('is-open');
            backdrop.setAttribute('aria-hidden', 'false');
            return;
        }
        renderProformaTable(contractNo, null, true);
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        if (!companyName) {
            renderProformaTable(contractNo, { items: [] }, false);
            return;
        }
        fetchProformaBreakdown(contractNo).then(function (breakdown) {
            if (openProformaContract !== contractNo) {
                return;
            }
            if (breakdown) {
                proformaBreakdownCache[cacheKey] = breakdown;
            }
            renderProformaTable(contractNo, breakdown, false);
        }).catch(function () {
            if (openProformaContract !== contractNo) {
                return;
            }
            renderProformaTable(contractNo, { items: [] }, false);
        });
    }

    function findProformaItem(breakdown, proformaNo) {
        var items = (breakdown && breakdown.items) || [];
        for (var i = 0; i < items.length; i += 1) {
            if (items[i] && String(items[i].no || '') === String(proformaNo || '')) {
                return items[i];
            }
        }
        return null;
    }

    function openProformaLines(proformaNo, group, otherContract) {
        if (!linesBody || !linesBackdrop) {
            return;
        }
        var cacheKey = proformaCacheKey(openProformaContract, proformaWorkorderNos(openProformaContract));
        var item = findProformaItem(proformaBreakdownCache[cacheKey], proformaNo);
        var lines = linesForAmountCell(item, group, otherContract);
        var columnLabel = group === 'this'
            ? (labels.proforma_amount || '')
            : otherContractLabel(otherContract);
        if (linesTitle) {
            linesTitle.textContent = (labels.proforma_lines_title || labels.proforma_title)
                + (proformaNo ? ' · ' + proformaNo : '')
                + (columnLabel ? ' · ' + columnLabel : '');
        }
        var html = '<p class="voortgang-proforma-summary">'
            + escapeHtml(formatPercentJs(progressFromLines(lines)))
            + '</p>';
        if (lines.length === 0) {
            html += '<p class="voortgang-muted">' + escapeHtml(labels.empty) + '</p>';
        } else {
            html += '<table class="voortgang-modal-table"><thead><tr>';
            html += '<th>' + escapeHtml(labels.workorder_no) + '</th>';
            html += '<th>' + escapeHtml(labels.contract_no) + '</th>';
            html += '<th class="num">' + escapeHtml(labels.proforma_amount_plain || labels.proforma_amount) + '</th>';
            html += '<th>' + escapeHtml(labels.status) + '</th>';
            html += '</tr></thead><tbody>';
            lines.forEach(function (line) {
                var contractNo = String(line.contract_no || '');
                html += '<tr><td>' + bcLinkHtml(bcWeb.workorder_page, line.no) + '</td>';
                html += '<td>' + (contractNo
                    ? escapeHtml(contractNo)
                    : escapeHtml(labels.proforma_no_contract || '—')) + '</td>';
                html += '<td class="num">' + escapeHtml(formatMoneyJs(line.amount)) + '</td>';
                html += '<td>' + escapeHtml(line.status || '') + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        linesBody.innerHTML = html;
        linesBackdrop.classList.add('is-open');
        linesBackdrop.setAttribute('aria-hidden', 'false');
    }

    function openWorkorders(contractNo, status) {
        if (!modalBody || !backdrop) {
            return;
        }
        var items = workordersFor(contractNo, status);
        var titleStatus = status === 'Totaal' ? labels.total : status;
        if (modalTitle) {
            modalTitle.textContent = labels.modal_title + ' · ' + contractNo + ' · ' + titleStatus;
        }
        if (items.length === 0) {
            modalBody.innerHTML = '<p class="voortgang-muted">' + escapeHtml(labels.empty) + '</p>';
        } else {
            var html = '<table class="voortgang-modal-table"><thead><tr>';
            html += '<th>' + escapeHtml(labels.workorder_no) + '</th>';
            html += '<th>' + escapeHtml(labels.status) + '</th>';
            html += '</tr></thead><tbody>';
            items.forEach(function (item) {
                html += '<tr><td>' + bcLinkHtml(bcWeb.workorder_page, item.no) + '</td><td>' + escapeHtml(item.status) + '</td></tr>';
            });
            html += '</tbody></table>';
            modalBody.innerHTML = html;
        }
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
    }

    if (searchFilter) {
        searchFilter.addEventListener('input', function () { applyFilters(true); });
        searchFilter.addEventListener('change', function () { applyFilters(true); });
    }

    if (dateFromInput) {
        dateFromInput.addEventListener('change', onDateRangeChange);
    }
    if (dateToInput) {
        dateToInput.addEventListener('change', onDateRangeChange);
    }

    if (settingsOpen) {
        settingsOpen.addEventListener('click', openSettings);
    }
    if (settingsClose) {
        settingsClose.addEventListener('click', closeSettings);
    }
    if (settingsBackdrop) {
        settingsBackdrop.addEventListener('click', function (event) {
            if (event.target === settingsBackdrop) {
                closeSettings();
            }
        });
    }
    if (hidePdCheckbox) {
        hidePdCheckbox.addEventListener('change', function () {
            saveHidePdSetting(!!hidePdCheckbox.checked);
        });
    }

    if (progressToggle) {
        progressToggle.addEventListener('click', cycleProgressState);
        syncProgressToggle();
    }

    if (pageSizeSelect) {
        pageSizeSelect.addEventListener('change', function () {
            pageSize = parseInt(pageSizeSelect.value, 10);
            if (isNaN(pageSize) || pageSize < 1) {
                pageSize = 100;
            }
            savePageSize(pageSize);
            applyFilters(true);
        });
    }

    pagers.forEach(function (pagerEl) {
        pagerEl.addEventListener('click', function (event) {
            var target = event.target.closest('button');
            if (!target || target.disabled) {
                return;
            }
            if (target.classList.contains('voortgang-page-prev')) {
                currentPage -= 1;
                applyFilters(false);
                return;
            }
            if (target.classList.contains('voortgang-page-next')) {
                currentPage += 1;
                applyFilters(false);
                return;
            }
            if (target.classList.contains('voortgang-page-num')) {
                var page = parseInt(target.getAttribute('data-page') || '0', 10);
                if (!isNaN(page) && page > 0) {
                    currentPage = page;
                    applyFilters(false);
                }
            }
        });
    });

    if (table) {
        table.addEventListener('click', function (event) {
            var refreshButton = event.target.closest('.voortgang-row-refresh');
            if (refreshButton) {
                event.preventDefault();
                event.stopPropagation();
                var refreshRow = refreshButton.closest('tr');
                if (refreshRow) {
                    refreshContractRow(refreshRow);
                }
                return;
            }

            var proformaButton = event.target.closest('.voortgang-proforma');
            if (proformaButton) {
                event.preventDefault();
                event.stopPropagation();
                var proformaRow = proformaButton.closest('tr');
                if (proformaRow) {
                    openProforma(proformaRow.getAttribute('data-contract') || '');
                }
                return;
            }

            var header = event.target.closest('thead th[data-sort]');
            if (header) {
                setSortFromHeader(header);
                return;
            }

            var button = event.target.closest('.voortgang-count');
            if (!button) {
                return;
            }
            var row = button.closest('tr');
            if (!row) {
                return;
            }
            openWorkorders(row.getAttribute('data-contract') || '', button.getAttribute('data-status') || '');
        });
    }

    if (modalClose) {
        modalClose.addEventListener('click', closeModal);
    }
    if (backdrop) {
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) {
                closeModal();
            }
        });
    }
    if (modalBody) {
        modalBody.addEventListener('click', function (event) {
            var amountButton = event.target.closest('.voortgang-proforma-amount');
            if (!amountButton) {
                return;
            }
            event.preventDefault();
            openProformaLines(
                amountButton.getAttribute('data-proforma') || '',
                amountButton.getAttribute('data-group') || 'this',
                amountButton.getAttribute('data-other-contract') || ''
            );
        });
    }
    if (linesClose) {
        linesClose.addEventListener('click', closeLinesModal);
    }
    if (linesBackdrop) {
        linesBackdrop.addEventListener('click', function (event) {
            if (event.target === linesBackdrop) {
                closeLinesModal();
            }
        });
    }
    if (companyPickBackdrop) {
        companyPickBackdrop.addEventListener('click', function (event) {
            var option = event.target.closest('.voortgang-company-option');
            if (!option) {
                return;
            }
            selectCompany(option.getAttribute('data-company') || '');
        });
    }
    if (companyWelcomeClose) {
        companyWelcomeClose.addEventListener('click', closeCompanyWelcome);
    }
    if (companyWelcomeBackdrop) {
        companyWelcomeBackdrop.addEventListener('click', function (event) {
            if (event.target === companyWelcomeBackdrop) {
                closeCompanyWelcome();
            }
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        if (needsCompanyChoice) {
            return;
        }
        if (settingsBackdrop && settingsBackdrop.classList.contains('is-open')) {
            closeSettings();
            return;
        }
        if (companyWelcomeBackdrop && companyWelcomeBackdrop.classList.contains('is-open')) {
            closeCompanyWelcome();
            return;
        }
        if (linesBackdrop && linesBackdrop.classList.contains('is-open')) {
            closeLinesModal();
            return;
        }
        closeModal();
    });

    updateExcelLink();
    applyFilters(true);
})();
</script>
</body>
</html>
