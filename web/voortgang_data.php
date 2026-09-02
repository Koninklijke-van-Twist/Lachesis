<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/voortgang_config.php';
require_once __DIR__ . '/bc_data.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

function voortgang_cache_base_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'voortgang'
        . DIRECTORY_SEPARATOR . 'v' . VOORTGANG_CACHE_VERSION;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function voortgang_company_slug(string $company): string
{
    $slug = strtolower(trim($company));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim((string) $slug, '_');

    return $slug !== '' ? $slug : 'company';
}

function voortgang_company_cache_files(string $company): array
{
    $base = voortgang_cache_base_dir() . DIRECTORY_SEPARATOR . voortgang_company_slug($company);

    return [
        'meta' => $base . '.meta.json',
        'rows' => $base . '.rows.json',
    ];
}

function voortgang_scalar_string(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_scalar($value) || $value === null) {
        return trim((string) $value);
    }

    return '';
}

function voortgang_scalar_float(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = str_replace(',', '.', voortgang_scalar_string($value));
    if ($text === '' || !is_numeric($text)) {
        return 0.0;
    }

    return (float) $text;
}

function voortgang_bc_auth(string $company = ''): array
{
    global $baseUrl;

    $companyName = trim($company);
    if ($companyName !== '') {
        auth_set_current_company_context($companyName, 1);
        $env = auth_get_environment_for_company($companyName, 1);
    } else {
        $env = auth_get_primary_environment();
    }

    $env = trim((string) $env);
    $authConfig = $env !== '' ? auth_get_auth_for_environment($env) : [];

    if ($env === '') {
        throw new RuntimeException('Environment ontbreekt in auth-configuratie.');
    }

    if ($authConfig === []) {
        throw new RuntimeException('Geen auth-configuratie gevonden voor environment: ' . $env);
    }

    return [
        'baseUrl' => (string) ($baseUrl ?? ''),
        'environment' => $env,
        'auth' => $authConfig,
    ];
}

function voortgang_resolve_next_url(string $currentUrl, mixed $next): string
{
    $nextUrl = trim((string) $next);
    if ($nextUrl === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $nextUrl) === 1) {
        return $nextUrl;
    }

    $parts = parse_url($currentUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $nextUrl;
    }

    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }

    if (str_starts_with($nextUrl, '/')) {
        return $origin . $nextUrl;
    }

    $path = (string) ($parts['path'] ?? '/');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

    return $origin . $dir . '/' . $nextUrl;
}

function voortgang_replace_cache_file(string $tmpPath, string $finalPath): void
{
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Tijdelijk cachebestand ontbreekt: ' . $tmpPath);
    }

    if (is_file($finalPath) && !@unlink($finalPath) && is_file($finalPath)) {
        throw new RuntimeException('Oud cachebestand kon niet worden vervangen: ' . $finalPath);
    }

    if (!@rename($tmpPath, $finalPath)) {
        throw new RuntimeException('Cachebestand kon niet worden geplaatst: ' . $finalPath);
    }
}

function voortgang_write_json_file(string $path, mixed $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Cache JSON encoderen mislukt');
    }

    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json, LOCK_EX);
    voortgang_replace_cache_file($tmp, $path);
}

function voortgang_empty_counts(): array
{
    $counts = [];
    foreach (VOORTGANG_STATUSES as $status) {
        $counts[$status] = 0;
    }

    return $counts;
}

function voortgang_empty_workorders(): array
{
    $lists = [];
    foreach (VOORTGANG_STATUSES as $status) {
        $lists[$status] = [];
    }

    return $lists;
}

function voortgang_progress_percent(array $counts, int $total): float
{
    if ($total <= 0) {
        return 0.0;
    }

    $done = 0;
    foreach (VOORTGANG_PROGRESS_STATUSES as $status) {
        $done += (int) ($counts[$status] ?? 0);
    }

    return round(($done / $total) * 100, 1);
}

function voortgang_odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Demeter-ODataClient/1.0 (Windows; nl-NL)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
            'Prefer: odata.maxpagesize=' . (string) VOORTGANG_ODATA_PAGE_SIZE,
        ],
    ]);

    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ':' . $auth['pass']);
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ':' . $auth['pass']);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('HTTP ' . $code . ' from OData: ' . $raw);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from OData');
    }

    return $json;
}

function voortgang_paginate_entity(string $company, string $entitySet, array $query, callable $onRow): array
{
    $ctx = voortgang_bc_auth($company);
    if ($ctx['baseUrl'] === '') {
        throw new RuntimeException('baseUrl ontbreekt in auth-configuratie.');
    }

    $kept = 0;
    $read = 0;
    $pages = 0;
    $url = bc_company_entity_url($ctx['baseUrl'], $ctx['environment'], $company, $entitySet, $query);

    while ($url !== '') {
        $resp = voortgang_odata_get_json($url, $ctx['auth']);
        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new RuntimeException("OData response missing 'value' array");
        }

        $rows = $resp['value'];
        $nextLink = voortgang_resolve_next_url($url, $resp['@odata.nextLink'] ?? '');
        unset($resp);

        $pages++;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $read++;
            if ($onRow($row)) {
                $kept++;
            }
        }

        unset($rows, $row);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $url = $nextLink;
    }

    return [
        'kept' => $kept,
        'read' => $read,
        'pages' => $pages,
    ];
}

function voortgang_ensure_row(array &$rows, string $contractNo): void
{
    if (isset($rows[$contractNo])) {
        return;
    }

    $rows[$contractNo] = [
        'contract_no' => $contractNo,
        'description' => '',
        'invoice_period' => '',
        'counts' => voortgang_empty_counts(),
        'workorders' => voortgang_empty_workorders(),
        'other_workorders' => [],
        'total' => 0,
        'progress' => 0.0,
        'total_sales' => 0.0,
        'total_revenue' => 0.0,
        'total_cost' => 0.0,
        'open_proforma' => '',
        'instructions' => '',
    ];
}

function voortgang_fetch_workorders_into_rows(string $company, array &$rows): array
{
    return voortgang_paginate_entity(
        $company,
        VOORTGANG_WORKORDERS_ENTITY,
        [
            '$select' => VOORTGANG_WORKORDERS_SELECT,
            '$filter' => "Contract_No ne ''",
        ],
        static function (array $row) use (&$rows): bool {
            $contractNo = voortgang_scalar_string($row['Contract_No'] ?? '');
            $no = voortgang_scalar_string($row['No'] ?? '');
            if ($contractNo === '' || $no === '') {
                return false;
            }

            voortgang_ensure_row($rows, $contractNo);
            $status = voortgang_scalar_string($row['Status'] ?? '');
            $rows[$contractNo]['total']++;

            if (isset($rows[$contractNo]['counts'][$status])) {
                $rows[$contractNo]['counts'][$status]++;
                $rows[$contractNo]['workorders'][$status][] = $no;
            } else {
                $rows[$contractNo]['other_workorders'][] = [
                    'no' => $no,
                    'status' => $status,
                ];
            }

            return true;
        }
    );
}

function voortgang_fetch_contracts_into_rows(string $company, array &$rows): array
{
    return voortgang_paginate_entity(
        $company,
        VOORTGANG_CONTRACTS_ENTITY,
        [
            '$select' => VOORTGANG_CONTRACTS_SELECT,
        ],
        static function (array $row) use (&$rows): bool {
            $contractNo = voortgang_scalar_string($row['Contract_No'] ?? '');
            if ($contractNo === '' || !isset($rows[$contractNo])) {
                return false;
            }

            $rows[$contractNo]['description'] = voortgang_scalar_string($row['Description'] ?? '');
            $rows[$contractNo]['invoice_period'] = voortgang_scalar_string($row['Invoice_Period'] ?? '');
            $rows[$contractNo]['instructions'] = voortgang_scalar_string($row['KVT_Memo_Internal_Use_Only'] ?? '');
            $rows[$contractNo]['total_sales'] = voortgang_scalar_float($row['KVT_Total_Sales_Price'] ?? 0);
            // BC levert KVT_Total_Revenue negatief; toon/sla op als positief gefactureerd bedrag.
            $rows[$contractNo]['total_revenue'] = -voortgang_scalar_float($row['KVT_Total_Revenue'] ?? 0);
            $rows[$contractNo]['total_cost'] = voortgang_scalar_float($row['KVT_Total_Cost'] ?? 0);

            return true;
        }
    );
}

function voortgang_finalize_rows(array $rows): array
{
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $total = (int) ($row['total'] ?? 0);
        if ($total <= 0) {
            continue;
        }

        $counts = is_array($row['counts'] ?? null) ? $row['counts'] : voortgang_empty_counts();
        $workorders = is_array($row['workorders'] ?? null) ? $row['workorders'] : voortgang_empty_workorders();
        foreach (VOORTGANG_STATUSES as $status) {
            if (!isset($counts[$status])) {
                $counts[$status] = 0;
            }
            if (!isset($workorders[$status]) || !is_array($workorders[$status])) {
                $workorders[$status] = [];
            }
            natcasesort($workorders[$status]);
            $workorders[$status] = array_values($workorders[$status]);
        }

        $row['counts'] = $counts;
        $row['workorders'] = $workorders;
        $row['other_workorders'] = is_array($row['other_workorders'] ?? null) ? array_values($row['other_workorders']) : [];
        $row['progress'] = voortgang_progress_percent($counts, $total);
        $list[] = $row;
    }

    usort($list, static function (array $a, array $b): int {
        $progress = ((float) ($b['progress'] ?? 0)) <=> ((float) ($a['progress'] ?? 0));
        if ($progress !== 0) {
            return $progress;
        }

        return strnatcasecmp((string) ($a['contract_no'] ?? ''), (string) ($b['contract_no'] ?? ''));
    });

    return $list;
}

function voortgang_refresh_company(string $company): array
{
    $files = voortgang_company_cache_files($company);
    $tmpRows = $files['rows'] . '.tmp';

    try {
        $rows = [];
        $workorderStats = voortgang_fetch_workorders_into_rows($company, $rows);
        $contractStats = voortgang_fetch_contracts_into_rows($company, $rows);
        $finalRows = voortgang_finalize_rows($rows);

        voortgang_write_json_file($tmpRows, $finalRows);
        voortgang_replace_cache_file($tmpRows, $files['rows']);

        $meta = [
            'version' => VOORTGANG_CACHE_VERSION,
            'company' => $company,
            'cached_at' => time(),
            'contract_count' => count($finalRows),
            'workorder_count' => (int) ($workorderStats['kept'] ?? 0),
            'workorder_read' => (int) ($workorderStats['read'] ?? 0),
            'workorder_pages' => (int) ($workorderStats['pages'] ?? 0),
            'contract_read' => (int) ($contractStats['read'] ?? 0),
            'contract_matched' => (int) ($contractStats['kept'] ?? 0),
            'contract_pages' => (int) ($contractStats['pages'] ?? 0),
        ];
        voortgang_write_json_file($files['meta'], $meta);

        return $meta;
    } catch (Throwable $error) {
        @unlink($tmpRows);
        @unlink($files['meta'] . '.tmp');
        throw $error;
    }
}

function voortgang_read_company_meta(string $company): ?array
{
    $files = voortgang_company_cache_files($company);
    if (!is_file($files['meta'])) {
        return null;
    }

    $raw = @file_get_contents($files['meta']);
    if ($raw === false || $raw === '') {
        return null;
    }

    $meta = json_decode($raw, true);
    if (!is_array($meta) || (int) ($meta['version'] ?? 0) !== VOORTGANG_CACHE_VERSION) {
        return null;
    }

    return $meta;
}

function voortgang_read_company_rows(string $company): array
{
    $files = voortgang_company_cache_files($company);
    if (!is_file($files['rows'])) {
        return [];
    }

    $raw = @file_get_contents($files['rows']);
    if ($raw === false || $raw === '') {
        return [];
    }

    $rows = json_decode($raw, true);

    return is_array($rows) ? $rows : [];
}

function voortgang_read_company_cache(string $company): ?array
{
    $meta = voortgang_read_company_meta($company);
    if ($meta === null) {
        return null;
    }

    $files = voortgang_company_cache_files($company);
    if (!is_file($files['rows'])) {
        return null;
    }

    return [
        '_meta' => $meta,
        'files' => $files,
        'rows' => voortgang_read_company_rows($company),
    ];
}

function voortgang_cached_companies(): array
{
    $dir = voortgang_cache_base_dir();
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    $companies = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.meta.json')) {
            continue;
        }

        $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . $entry);
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        $name = trim((string) ($meta['company'] ?? ''));
        if ($name !== '' && (int) ($meta['version'] ?? 0) === VOORTGANG_CACHE_VERSION) {
            $companies[$name] = $name;
        }
    }

    $names = array_values($companies);
    natcasesort($names);

    return array_values($names);
}

function voortgang_status_workorders(array $row, string $status): array
{
    if ($status === 'Totaal') {
        $all = [];
        $lists = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
        foreach (VOORTGANG_STATUSES as $known) {
            foreach (($lists[$known] ?? []) as $no) {
                $all[] = ['no' => (string) $no, 'status' => $known];
            }
        }
        foreach (($row['other_workorders'] ?? []) as $extra) {
            if (!is_array($extra)) {
                continue;
            }
            $all[] = [
                'no' => voortgang_scalar_string($extra['no'] ?? ''),
                'status' => voortgang_scalar_string($extra['status'] ?? ''),
            ];
        }

        usort($all, static function (array $a, array $b): int {
            return strnatcasecmp((string) ($a['no'] ?? ''), (string) ($b['no'] ?? ''));
        });

        return $all;
    }

    $nos = $row['workorders'][$status] ?? [];
    if (!is_array($nos)) {
        return [];
    }

    $list = [];
    foreach ($nos as $no) {
        $list[] = ['no' => (string) $no, 'status' => $status];
    }

    return $list;
}
