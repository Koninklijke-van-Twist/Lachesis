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
if ($requestedCompany !== '' && in_array($requestedCompany, $companies, true)) {
    $company = $requestedCompany;
    if ($prefEmail !== '' && $requestedCompany !== $savedCompany) {
        saveUserPref($prefEmail, 'company', $requestedCompany);
    }
} elseif ($savedCompany !== '' && in_array($savedCompany, $companies, true)) {
    $company = $savedCompany;
} else {
    $company = (string) ($companies[0] ?? '');
}

$cache = $company !== '' ? voortgang_read_company_cache($company) : null;
$cachedAt = (int) ($cache['_meta']['cached_at'] ?? 0);
$cacheStale = $cache !== null && $cachedAt > 0 && (time() - $cachedAt) > 129600;
$rows = is_array($cache['rows'] ?? null) ? $cache['rows'] : [];
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

$workorderMap = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $contractNo = (string) ($row['contract_no'] ?? '');
    if ($contractNo === '') {
        continue;
    }
    $workorderMap[$contractNo] = [
        'workorders' => is_array($row['workorders'] ?? null) ? $row['workorders'] : [],
        'other' => is_array($row['other_workorders'] ?? null) ? $row['other_workorders'] : [],
    ];
}

$workorderMapJson = json_encode($workorderMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
if ($workorderMapJson === false) {
    $workorderMapJson = '{}';
}

$excelUrl = 'index.php?export=excel&company=' . rawurlencode($company);

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
        .voortgang-header { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .voortgang-header img { max-height: 42px; width: auto; }
        .voortgang-header-actions { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-left: auto; }
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
        .voortgang-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
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
        table.voortgang-table th:first-child, table.voortgang-table td:first-child { position: sticky; left: 0; z-index: 1; min-width: 110px; }
        table.voortgang-table th:first-child { z-index: 3; }
        .voortgang-instructions { max-width: 220px; white-space: pre-wrap; overflow-wrap: anywhere; }
        .voortgang-count { font: inherit; font-weight: 700; color: var(--kvt-main-blue); background: transparent; border: 0; padding: 0; cursor: pointer; text-decoration: underline; }
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
        .voortgang-modal { width: min(720px, 100%); max-height: 92vh; overflow: auto; background: #fff; border-radius: 16px 16px 0 0; padding: 16px; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.25); }
        .voortgang-modal-header { display: flex; justify-content: space-between; gap: 12px; align-items: start; margin-bottom: 12px; position: sticky; top: 0; background: #fff; padding-bottom: 8px; border-bottom: 1px solid var(--kvt-line); }
        .voortgang-modal-close { border: 0; background: transparent; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--kvt-muted); padding: 4px 8px; }
        .voortgang-modal-table { width: 100%; border-collapse: collapse; font-size: 0.86rem; }
        .voortgang-modal-table th, .voortgang-modal-table td { border-bottom: 1px solid var(--kvt-line); padding: 8px 6px; text-align: left; }
        @media (min-width: 720px) {
            .voortgang-form-grid { grid-template-columns: 1fr auto 180px; align-items: end; }
            .voortgang-modal-backdrop { align-items: center; padding: 24px; }
            .voortgang-modal { border-radius: 16px; }
        }
    </style>
</head>
<body>
<div class="voortgang-page">
    <header class="voortgang-header">
        <img src="logo-website.png" alt="KVT">
        <div class="voortgang-header-actions">
            <?php if ($cache !== null): ?>
                <a class="voortgang-excel" href="<?= voortgang_h($excelUrl) ?>"><?= voortgang_h(LOC('voortgang.btn.excel')) ?></a>
            <?php endif; ?>
            <?php if ($companies !== []): ?>
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

    <?php if ($cache === null): ?>
        <div class="voortgang-alert"><?= voortgang_h(LOC('voortgang.empty.cache')) ?></div>
    <?php elseif ($cacheStale): ?>
        <div class="voortgang-alert voortgang-alert-warn"><?= voortgang_h(LOC('voortgang.stale.cache')) ?></div>
    <?php endif; ?>

    <?php if ($cache !== null && $rows === []): ?>
        <section class="voortgang-card">
            <p class="voortgang-muted"><?= voortgang_h(LOC('voortgang.empty.rows')) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($rows !== []): ?>
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
                            <th data-sort="open_proforma" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.open_proforma')) ?></th>
                            <th data-sort="instructions" data-sort-type="text"><?= voortgang_h(LOC('voortgang.col.instructions')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            if (!is_array($row)) {
                                continue;
                            }
                            $counts = is_array($row['counts'] ?? null) ? $row['counts'] : [];
                            $revenue = voortgang_normalized_revenue((float) ($row['total_revenue'] ?? 0));
                            ?>
                            <tr
                                data-contract="<?= voortgang_h((string) ($row['contract_no'] ?? '')) ?>"
                                data-progress="<?= voortgang_h((string) ((float) ($row['progress'] ?? 0))) ?>"
                                data-search="<?= voortgang_h(voortgang_row_search_text($row)) ?>"
                                data-sort-contract_no="<?= voortgang_h((string) ($row['contract_no'] ?? '')) ?>"
                                data-sort-description="<?= voortgang_h((string) ($row['description'] ?? '')) ?>"
                                data-sort-invoice_period="<?= voortgang_h((string) ($row['invoice_period'] ?? '')) ?>"
                                <?php foreach (VOORTGANG_STATUSES as $status): ?>
                                    data-sort-status_<?= voortgang_h(strtolower($status)) ?>="<?= (int) ($counts[$status] ?? 0) ?>"
                                <?php endforeach; ?>
                                data-sort-total="<?= (int) ($row['total'] ?? 0) ?>"
                                data-sort-progress="<?= voortgang_h((string) ((float) ($row['progress'] ?? 0))) ?>"
                                data-sort-total_sales="<?= voortgang_h((string) ((float) ($row['total_sales'] ?? 0))) ?>"
                                data-sort-total_revenue="<?= voortgang_h((string) $revenue) ?>"
                                data-sort-total_cost="<?= voortgang_h((string) ((float) ($row['total_cost'] ?? 0))) ?>"
                                data-sort-open_proforma="<?= voortgang_h((string) ($row['open_proforma'] ?? '')) ?>"
                                data-sort-instructions="<?= voortgang_h((string) ($row['instructions'] ?? '')) ?>"
                            >
                                <td><?= voortgang_h((string) ($row['contract_no'] ?? '')) ?></td>
                                <td><?= voortgang_h((string) ($row['description'] ?? '')) ?></td>
                                <td><?= voortgang_h((string) ($row['invoice_period'] ?? '')) ?></td>
                                <?php foreach (VOORTGANG_STATUSES as $status): ?>
                                    <?= voortgang_count_cell((int) ($counts[$status] ?? 0), $status) ?>
                                <?php endforeach; ?>
                                <?= voortgang_count_cell((int) ($row['total'] ?? 0), 'Totaal') ?>
                                <td class="num"><?= voortgang_h(voortgang_format_percent((float) ($row['progress'] ?? 0))) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money((float) ($row['total_sales'] ?? 0))) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money($revenue)) ?></td>
                                <td class="num"><?= voortgang_h(voortgang_format_money((float) ($row['total_cost'] ?? 0))) ?></td>
                                <td><?= voortgang_h((string) ($row['open_proforma'] ?? '')) ?></td>
                                <td class="voortgang-instructions"><?= voortgang_h((string) ($row['instructions'] ?? '')) ?></td>
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
    var pageSize = <?= (int) $savedPageSize ?>;
    var currentPage = 1;
    var sortKey = 'progress';
    var sortDir = 'desc';
    var progressStates = ['completed', 'all', 'incomplete'];
    var progressState = 'all';
    var workorderMap = <?= $workorderMapJson ?>;
    var labels = <?= json_encode([
        'row_count' => LOC('voortgang.row_count'),
        'page_status' => LOC('voortgang.pager.status'),
        'modal_title' => LOC('voortgang.modal.title'),
        'workorder_no' => LOC('voortgang.col.workorder_no'),
        'status' => LOC('voortgang.col.status'),
        'empty' => LOC('voortgang.modal.empty'),
        'total' => LOC('voortgang.col.total'),
        'filter_all' => LOC('voortgang.filter.all'),
        'filter_completed' => LOC('voortgang.filter.completed'),
        'filter_incomplete' => LOC('voortgang.filter.incomplete'),
    ], JSON_UNESCAPED_UNICODE) ?>;

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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

    function closeModal() {
        if (!backdrop) {
            return;
        }
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
    }

    function workordersFor(contractNo, status) {
        var entry = workorderMap[contractNo] || {};
        var lists = entry.workorders || {};
        var items = [];
        if (status === 'Totaal') {
            Object.keys(lists).forEach(function (key) {
                (lists[key] || []).forEach(function (no) {
                    items.push({ no: no, status: key });
                });
            });
            (entry.other || []).forEach(function (extra) {
                items.push({ no: extra.no || '', status: extra.status || '' });
            });
        } else {
            (lists[status] || []).forEach(function (no) {
                items.push({ no: no, status: status });
            });
        }
        items.sort(function (a, b) {
            return String(a.no).localeCompare(String(b.no), undefined, { numeric: true, sensitivity: 'base' });
        });
        return items;
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
                html += '<tr><td>' + escapeHtml(item.no) + '</td><td>' + escapeHtml(item.status) + '</td></tr>';
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
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    applyFilters(true);
})();
</script>
</body>
</html>
