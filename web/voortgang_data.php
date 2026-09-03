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

function voortgang_parse_odata_date(mixed $value): string
{
    $text = voortgang_scalar_string($value);
    if ($text === '') {
        return '';
    }

    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $match) !== 1) {
        return '';
    }

    $date = $match[1];
    if ($date < '1900-01-01') {
        return '';
    }

    $parts = explode('-', $date);
    if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
        return '';
    }

    return $date;
}

function voortgang_empty_counts(): array
{
    $counts = [];
    foreach (VOORTGANG_STATUSES as $status) {
        $counts[$status] = 0;
    }

    return $counts;
}

/**
 * @param list<array<string, mixed>> $items
 * @return list<array{no:string,status:string,task_code:string,start_date:string,proforma_amount:float,proformas:list<array{no:string,amount:float}>}>
 */
function voortgang_filter_workorder_items(array $items, bool $hidePd, string $dateFrom, string $dateTo): array
{
    $dateFrom = trim($dateFrom);
    $dateTo = trim($dateTo);
    $filtered = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $taskCode = voortgang_scalar_string($item['task_code'] ?? '');
        if ($hidePd && strcasecmp($taskCode, VOORTGANG_HIDDEN_TASK_CODE_PD) === 0) {
            continue;
        }

        $startDate = voortgang_parse_odata_date($item['start_date'] ?? '');
        if ($startDate !== '') {
            if ($dateFrom !== '' && $startDate < $dateFrom) {
                continue;
            }
            if ($dateTo !== '' && $startDate > $dateTo) {
                continue;
            }
        }

        $filtered[] = [
            'no' => voortgang_scalar_string($item['no'] ?? ''),
            'status' => voortgang_scalar_string($item['status'] ?? ''),
            'task_code' => $taskCode,
            'start_date' => $startDate,
            'proforma_amount' => voortgang_scalar_float($item['proforma_amount'] ?? 0),
            'proformas' => voortgang_normalize_proforma_documents($item['proformas'] ?? []),
        ];
    }

    return $filtered;
}

/**
 * @param list<array<string, mixed>> $items
 * @return array{counts:array<string,int>,total:int,progress:float,proforma_total:float}
 */
function voortgang_aggregate_workorder_items(array $items): array
{
    $counts = voortgang_empty_counts();
    $total = 0;
    $proformaTotal = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $no = voortgang_scalar_string($item['no'] ?? '');
        if ($no === '') {
            continue;
        }

        $total++;
        $status = voortgang_scalar_string($item['status'] ?? '');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $proformaTotal += voortgang_scalar_float($item['proforma_amount'] ?? 0);
    }

    return [
        'counts' => $counts,
        'total' => $total,
        'progress' => voortgang_progress_percent($counts, $total),
        'proforma_total' => $proformaTotal,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{min:string,max:string}
 */
function voortgang_workorder_date_bounds_from_rows(array $rows): array
{
    $min = '';
    $max = '';

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $date = voortgang_parse_odata_date($item['start_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if ($min === '' || $date < $min) {
                $min = $date;
            }
            if ($max === '' || $date > $max) {
                $max = $date;
            }
        }
    }

    return ['min' => $min, 'max' => $max];
}

function voortgang_apply_filters_to_rows(array $rows, bool $hidePd, string $dateFrom, string $dateTo): array
{
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
        $filtered = voortgang_filter_workorder_items($items, $hidePd, $dateFrom, $dateTo);
        $agg = voortgang_aggregate_workorder_items($filtered);
        if ($agg['total'] <= 0) {
            continue;
        }

        $row['workorders'] = $filtered;
        $row['counts'] = $agg['counts'];
        $row['total'] = $agg['total'];
        $row['progress'] = $agg['progress'];
        $row['open_proforma'] = (float) ($agg['proforma_total'] ?? 0);
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

function voortgang_bc_webclient_base_from_environment(string $environment): string
{
    global $baseUrl;

    $environment = trim($environment);
    $rawBase = trim((string) ($baseUrl ?? ''));
    $scheme = parse_url($rawBase, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($rawBase, PHP_URL_HOST);
    if (!is_string($host) || $host === '' || $environment === '') {
        return '';
    }

    return $scheme . '://' . $host . '/' . rawurlencode($environment) . '/';
}

/**
 * Webclient-environment uit auth.php $environment, nooit uit $auth_list-fallback of stale cache.
 */
function voortgang_bc_webclient_environment(string $company = '', string $cachedEnvironment = ''): string
{
    $active = auth_get_active_environments();
    if ($active === []) {
        return '';
    }

    $cachedEnvironment = trim($cachedEnvironment);
    if ($cachedEnvironment !== '' && in_array($cachedEnvironment, $active, true)) {
        return $cachedEnvironment;
    }

    $company = trim($company);
    $knownMap = is_array($GLOBALS['demeter_company_environment_map'] ?? null)
        ? $GLOBALS['demeter_company_environment_map']
        : [];
    if ($company !== '' && isset($knownMap[$company])) {
        $mapped = trim((string) $knownMap[$company]);
        if ($mapped !== '' && in_array($mapped, $active, true)) {
            return $mapped;
        }
    }

    return (string) $active[0];
}

function voortgang_bc_webclient_base(string $company): string
{
    return voortgang_bc_webclient_base_from_environment(voortgang_bc_webclient_environment($company));
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
        'workorders' => [],
        'total' => 0,
        'progress' => 0.0,
        'total_sales' => 0.0,
        'total_revenue' => 0.0,
        'total_cost' => 0.0,
        'open_proforma' => 0.0,
        'instructions' => '',
    ];
}

function voortgang_append_workorder_item(array &$rows, string $contractNo, array $bcRow): bool
{
    $no = voortgang_scalar_string($bcRow['No'] ?? '');
    if ($contractNo === '' || $no === '') {
        return false;
    }

    voortgang_ensure_row($rows, $contractNo);
    $rows[$contractNo]['workorders'][] = [
        'no' => $no,
        'status' => voortgang_scalar_string($bcRow['Status'] ?? ''),
        'task_code' => voortgang_scalar_string($bcRow['Task_Code'] ?? ''),
        'start_date' => voortgang_parse_odata_date($bcRow['Start_Date'] ?? ''),
        'proforma_amount' => 0.0,
        'proformas' => [],
    ];

    return true;
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

            return voortgang_append_workorder_item($rows, $contractNo, $row);
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

/**
 * @param list<mixed> $documents
 * @return list<array{no:string,amount:float}>
 */
function voortgang_normalize_proforma_documents(mixed $documents): array
{
    if (!is_array($documents)) {
        return [];
    }

    $list = [];
    foreach ($documents as $item) {
        if (!is_array($item)) {
            continue;
        }
        $no = voortgang_scalar_string($item['no'] ?? '');
        $amount = voortgang_scalar_float($item['amount'] ?? 0);
        if ($no === '' || $amount <= 0) {
            continue;
        }
        $list[] = [
            'no' => $no,
            'amount' => $amount,
        ];
    }

    usort($list, static function (array $a, array $b): int {
        return strnatcasecmp($a['no'], $b['no']);
    });

    return array_values($list);
}

/**
 * @param array<string, float> $documents
 * @return list<array{no:string,amount:float}>
 */
function voortgang_proforma_documents_from_map(array $documents): array
{
    $list = [];
    foreach ($documents as $no => $amount) {
        $list[] = [
            'no' => voortgang_scalar_string((string) $no),
            'amount' => voortgang_scalar_float($amount),
        ];
    }

    return voortgang_normalize_proforma_documents($list);
}

/**
 * @param list<string> $values
 */
function voortgang_odata_eq_or_filter(string $field, array $values): string
{
    $parts = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $parts[] = $field . " eq '" . bc_escape_odata_string($value) . "'";
    }
    if ($parts === []) {
        return '';
    }
    if (count($parts) === 1) {
        return $parts[0];
    }

    return '(' . implode(' or ', $parts) . ')';
}

/**
 * @param list<string>|null $workorderNos null = hele bedrijf, [] = niets
 * @return array<string, array{amount:float, documents: array<string, float>}>
 */
function voortgang_fetch_proforma_map(string $company, ?array $workorderNos = null): array
{
    if (is_array($workorderNos) && $workorderNos === []) {
        return [];
    }

    $map = [];
    $docType = bc_escape_odata_string(VOORTGANG_PROFORMA_DOCUMENT_TYPE);
    $chunks = [null];
    if (is_array($workorderNos)) {
        $unique = [];
        foreach ($workorderNos as $no) {
            $no = trim((string) $no);
            if ($no !== '') {
                $unique[$no] = $no;
            }
        }
        $chunks = array_chunk(array_values($unique), 40);
        if ($chunks === []) {
            return [];
        }
    }

    $accumulate = static function (array $row) use (&$map): bool {
        $workorderNo = voortgang_scalar_string($row['Job_Task_No'] ?? '');
        if ($workorderNo === '') {
            return false;
        }

        $amount = voortgang_scalar_float($row['Line_Amount'] ?? 0);
        $documentNo = voortgang_scalar_string($row['Document_No'] ?? '');
        if (!isset($map[$workorderNo])) {
            $map[$workorderNo] = [
                'amount' => 0.0,
                'documents' => [],
            ];
        }
        $map[$workorderNo]['amount'] += $amount;
        if ($documentNo !== '') {
            $map[$workorderNo]['documents'][$documentNo] =
                ($map[$workorderNo]['documents'][$documentNo] ?? 0.0) + $amount;
        }

        return true;
    };

    foreach ($chunks as $chunk) {
        $filter = "Document_Type eq '" . $docType . "' and Job_Task_No ne ''";
        if (is_array($chunk)) {
            $woFilter = voortgang_odata_eq_or_filter('Job_Task_No', $chunk);
            if ($woFilter === '') {
                continue;
            }
            $filter = "Document_Type eq '" . $docType . "' and " . $woFilter;
        }

        voortgang_paginate_entity(
            $company,
            VOORTGANG_PROFORMA_ENTITY,
            [
                '$select' => VOORTGANG_PROFORMA_SELECT,
                '$filter' => $filter,
            ],
            $accumulate
        );
    }

    return $map;
}

/**
 * @param list<string> $workorderNos
 * @return list<array{no:string,amount:float}>
 */
function voortgang_proforma_documents_for_workorders(string $company, array $workorderNos): array
{
    $map = voortgang_fetch_proforma_map($company, $workorderNos);
    $combined = [];
    foreach ($map as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $documents = is_array($entry['documents'] ?? null) ? $entry['documents'] : [];
        foreach ($documents as $no => $amount) {
            $no = voortgang_scalar_string((string) $no);
            if ($no === '') {
                continue;
            }
            $combined[$no] = ($combined[$no] ?? 0.0) + voortgang_scalar_float($amount);
        }
    }

    return voortgang_proforma_documents_from_map($combined);
}

/**
 * @param list<string> $values
 * @return list<string>
 */
function voortgang_unique_nonempty_strings(array $values): array
{
    $unique = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $unique[$value] = $value;
        }
    }

    return array_values($unique);
}

/**
 * @param array<string, string> $statusByWorkorder
 */
function voortgang_progress_from_status_map(array $statusByWorkorder): float
{
    $counts = voortgang_empty_counts();
    $total = 0;
    foreach ($statusByWorkorder as $status) {
        $total++;
        $status = voortgang_scalar_string($status);
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    return voortgang_progress_percent($counts, $total);
}

/**
 * @param list<string> $keys
 * @return list<string>
 */
function voortgang_sort_other_contract_keys(array $keys): array
{
    usort($keys, static function (string $a, string $b): int {
        if ($a === '' && $b !== '') {
            return 1;
        }
        if ($b === '' && $a !== '') {
            return -1;
        }

        return strnatcasecmp($a, $b);
    });

    return array_values($keys);
}

/**
 * @param list<string> $documentNos
 * @return array<string, array<string, float>>
 */
function voortgang_fetch_proforma_amounts_by_document(string $company, array $documentNos): array
{
    $documentNos = voortgang_unique_nonempty_strings($documentNos);
    if ($documentNos === []) {
        return [];
    }

    $byDocument = [];
    $docType = bc_escape_odata_string(VOORTGANG_PROFORMA_DOCUMENT_TYPE);
    foreach (array_chunk($documentNos, 40) as $chunk) {
        $docFilter = voortgang_odata_eq_or_filter('Document_No', $chunk);
        if ($docFilter === '') {
            continue;
        }

        voortgang_paginate_entity(
            $company,
            VOORTGANG_PROFORMA_ENTITY,
            [
                '$select' => VOORTGANG_PROFORMA_SELECT,
                '$filter' => "Document_Type eq '" . $docType . "' and Job_Task_No ne '' and " . $docFilter,
            ],
            static function (array $row) use (&$byDocument): bool {
                $documentNo = voortgang_scalar_string($row['Document_No'] ?? '');
                $workorderNo = voortgang_scalar_string($row['Job_Task_No'] ?? '');
                if ($documentNo === '' || $workorderNo === '') {
                    return false;
                }

                $amount = voortgang_scalar_float($row['Line_Amount'] ?? 0);
                if (!isset($byDocument[$documentNo])) {
                    $byDocument[$documentNo] = [];
                }
                $byDocument[$documentNo][$workorderNo] =
                    ($byDocument[$documentNo][$workorderNo] ?? 0.0) + $amount;

                return true;
            }
        );
    }

    return $byDocument;
}

/**
 * @param list<string> $workorderNos
 * @return array<string, array{no:string,contract_no:string,status:string}>
 */
function voortgang_fetch_workorders_by_nos(string $company, array $workorderNos): array
{
    $workorderNos = voortgang_unique_nonempty_strings($workorderNos);
    if ($workorderNos === []) {
        return [];
    }

    $map = [];
    foreach (array_chunk($workorderNos, 40) as $chunk) {
        $woFilter = voortgang_odata_eq_or_filter('No', $chunk);
        if ($woFilter === '') {
            continue;
        }

        voortgang_paginate_entity(
            $company,
            VOORTGANG_WORKORDERS_ENTITY,
            [
                '$select' => VOORTGANG_WORKORDERS_SELECT,
                '$filter' => $woFilter,
            ],
            static function (array $row) use (&$map): bool {
                $no = voortgang_scalar_string($row['No'] ?? '');
                if ($no === '') {
                    return false;
                }

                $map[$no] = [
                    'no' => $no,
                    'contract_no' => voortgang_scalar_string($row['Contract_No'] ?? ''),
                    'status' => voortgang_scalar_string($row['Status'] ?? ''),
                ];

                return true;
            }
        );
    }

    return $map;
}

/**
 * @param list<string> $workorderNos
 * @return array{
 *   this_progress:float,
 *   other_progress:float|null,
 *   other_contracts:list<string>,
 *   items:list<array{
 *     no:string,
 *     this_amount:float,
 *     others:array<string, float>,
 *     lines:list<array{no:string,contract_no:string,amount:float,status:string,this_contract:bool}>
 *   }>
 * }
 */
function voortgang_proforma_breakdown_for_workorders(string $company, string $thisContractNo, array $workorderNos): array
{
    $thisContractNo = trim($thisContractNo);
    $thisWorkorderNos = voortgang_unique_nonempty_strings($workorderNos);
    $empty = [
        'this_progress' => 0.0,
        'other_progress' => null,
        'other_contracts' => [],
        'items' => [],
    ];
    if ($thisContractNo === '' || $thisWorkorderNos === []) {
        return $empty;
    }

    $thisWoSet = array_fill_keys($thisWorkorderNos, true);
    $proformaMap = voortgang_fetch_proforma_map($company, $thisWorkorderNos);
    $documentNos = [];
    foreach ($proformaMap as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $documents = is_array($entry['documents'] ?? null) ? $entry['documents'] : [];
        foreach ($documents as $documentNo => $amount) {
            $documentNo = voortgang_scalar_string((string) $documentNo);
            if ($documentNo === '' || voortgang_scalar_float($amount) <= 0) {
                continue;
            }
            $documentNos[$documentNo] = $documentNo;
        }
    }
    $documentNos = array_values($documentNos);
    if ($documentNos === []) {
        return $empty;
    }

    $amountsByDocument = voortgang_fetch_proforma_amounts_by_document($company, $documentNos);
    $allWorkorderNos = [];
    foreach ($amountsByDocument as $perWorkorder) {
        foreach ($perWorkorder as $workorderNo => $_amount) {
            $workorderNo = voortgang_scalar_string((string) $workorderNo);
            if ($workorderNo !== '') {
                $allWorkorderNos[$workorderNo] = $workorderNo;
            }
        }
    }
    $workorderMeta = voortgang_fetch_workorders_by_nos($company, array_values($allWorkorderNos));

    $thisStatuses = [];
    $otherStatuses = [];
    $otherContractKeys = [];
    $items = [];

    foreach ($documentNos as $documentNo) {
        $perWorkorder = is_array($amountsByDocument[$documentNo] ?? null) ? $amountsByDocument[$documentNo] : [];
        $thisAmount = 0.0;
        $others = [];
        $lines = [];

        foreach ($perWorkorder as $workorderNo => $amount) {
            $workorderNo = voortgang_scalar_string((string) $workorderNo);
            $amount = round(voortgang_scalar_float($amount), 2);
            if ($workorderNo === '' || $amount <= 0) {
                continue;
            }

            $meta = is_array($workorderMeta[$workorderNo] ?? null) ? $workorderMeta[$workorderNo] : [];
            $contractNo = voortgang_scalar_string($meta['contract_no'] ?? '');
            $status = voortgang_scalar_string($meta['status'] ?? '');
            $isThis = isset($thisWoSet[$workorderNo]);
            if (!$isThis && $contractNo === $thisContractNo) {
                continue;
            }

            $lines[] = [
                'no' => $workorderNo,
                'contract_no' => $isThis ? $thisContractNo : $contractNo,
                'amount' => $amount,
                'status' => $status,
                'this_contract' => $isThis,
            ];

            if ($isThis) {
                $thisAmount = round($thisAmount + $amount, 2);
                $thisStatuses[$workorderNo] = $status;
                continue;
            }

            $others[$contractNo] = round(($others[$contractNo] ?? 0.0) + $amount, 2);
            $otherContractKeys[$contractNo] = $contractNo;
            $otherStatuses[$workorderNo] = $status;
        }

        if ($thisAmount <= 0 && $others === []) {
            continue;
        }

        usort($lines, static function (array $a, array $b): int {
            return strnatcasecmp($a['no'], $b['no']);
        });

        $items[] = [
            'no' => $documentNo,
            'this_amount' => $thisAmount,
            'others' => $others,
            'lines' => array_values($lines),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp($a['no'], $b['no']);
    });

    $hasOther = $otherStatuses !== [];

    return [
        'this_progress' => voortgang_progress_from_status_map($thisStatuses),
        'other_progress' => $hasOther ? voortgang_progress_from_status_map($otherStatuses) : null,
        'other_contracts' => voortgang_sort_other_contract_keys(array_values($otherContractKeys)),
        'items' => array_values($items),
    ];
}

/**
 * @param array<string, array{amount?:float, documents?:array<string, float>}> $proformaMap
 */
function voortgang_apply_proforma_map_to_rows(array &$rows, array $proformaMap): void
{
    foreach ($rows as &$row) {
        if (!is_array($row)) {
            continue;
        }

        $total = 0.0;
        $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $no = voortgang_scalar_string($item['no'] ?? '');
            $entry = ($no !== '' && isset($proformaMap[$no]) && is_array($proformaMap[$no]))
                ? $proformaMap[$no]
                : [];
            $amount = voortgang_scalar_float($entry['amount'] ?? 0);
            $documents = is_array($entry['documents'] ?? null) ? $entry['documents'] : [];
            $item['proforma_amount'] = $amount;
            $item['proformas'] = voortgang_proforma_documents_from_map($documents);
            $total += $amount;
        }
        unset($item);

        $row['workorders'] = $items;
        $row['open_proforma'] = $total;
    }
    unset($row);
}

function voortgang_finalize_rows(array $rows): array
{
    $list = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
        usort($items, static function (array $a, array $b): int {
            return strnatcasecmp((string) ($a['no'] ?? ''), (string) ($b['no'] ?? ''));
        });
        $items = array_values($items);

        $agg = voortgang_aggregate_workorder_items($items);
        if ($agg['total'] <= 0) {
            continue;
        }

        $row['workorders'] = $items;
        $row['counts'] = $agg['counts'];
        $row['total'] = $agg['total'];
        $row['progress'] = $agg['progress'];
        $row['open_proforma'] = (float) ($agg['proforma_total'] ?? 0);
        $list[] = $row;
    }

    return voortgang_sort_cached_rows($list);
}

function voortgang_refresh_company(string $company): array
{
    $files = voortgang_company_cache_files($company);
    $tmpRows = $files['rows'] . '.tmp';

    try {
        $rows = [];
        $workorderStats = voortgang_fetch_workorders_into_rows($company, $rows);
        $proformaMap = voortgang_fetch_proforma_map($company);
        voortgang_apply_proforma_map_to_rows($rows, $proformaMap);
        $contractStats = voortgang_fetch_contracts_into_rows($company, $rows);
        $finalRows = voortgang_finalize_rows($rows);
        $dateBounds = voortgang_workorder_date_bounds_from_rows($finalRows);

        voortgang_write_json_file($tmpRows, $finalRows);
        voortgang_replace_cache_file($tmpRows, $files['rows']);

        $meta = [
            'version' => VOORTGANG_CACHE_VERSION,
            'company' => $company,
            'environment' => (string) (voortgang_bc_auth($company)['environment'] ?? ''),
            'cached_at' => time(),
            'contract_count' => count($finalRows),
            'workorder_count' => (int) ($workorderStats['kept'] ?? 0),
            'workorder_read' => (int) ($workorderStats['read'] ?? 0),
            'workorder_pages' => (int) ($workorderStats['pages'] ?? 0),
            'contract_read' => (int) ($contractStats['read'] ?? 0),
            'contract_matched' => (int) ($contractStats['kept'] ?? 0),
            'contract_pages' => (int) ($contractStats['pages'] ?? 0),
            'date_min' => $dateBounds['min'],
            'date_max' => $dateBounds['max'],
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
    $items = is_array($row['workorders'] ?? null) ? $row['workorders'] : [];
    $list = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $no = voortgang_scalar_string($item['no'] ?? '');
        $itemStatus = voortgang_scalar_string($item['status'] ?? '');
        if ($no === '') {
            continue;
        }
        if ($status !== 'Totaal' && $itemStatus !== $status) {
            continue;
        }
        $list[] = [
            'no' => $no,
            'status' => $itemStatus,
        ];
    }

    usort($list, static function (array $a, array $b): int {
        return strnatcasecmp((string) ($a['no'] ?? ''), (string) ($b['no'] ?? ''));
    });

    return $list;
}

function voortgang_contract_token(string $contractNo): string
{
    $token = preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($contractNo)) ?? '';
    $token = trim($token, '_');

    return $token !== '' ? $token : 'contract';
}

function voortgang_refresh_job_paths(string $company, string $contractNo): array
{
    $base = voortgang_cache_base_dir() . DIRECTORY_SEPARATOR
        . voortgang_company_slug($company) . '.refresh.' . voortgang_contract_token($contractNo);

    return [
        'lock' => $base . '.lock',
        'status' => $base . '.json',
    ];
}

function voortgang_rows_write_lock_path(string $company): string
{
    return voortgang_cache_base_dir() . DIRECTORY_SEPARATOR
        . voortgang_company_slug($company) . '.rows.write.lock';
}

function voortgang_read_refresh_status(string $statusPath): ?array
{
    if (!is_file($statusPath)) {
        return null;
    }

    $raw = @file_get_contents($statusPath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $status = json_decode($raw, true);

    return is_array($status) ? $status : null;
}

function voortgang_write_refresh_status(string $statusPath, array $status): void
{
    $json = json_encode($status, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Refresh-status JSON encoderen mislukt');
    }

    file_put_contents($statusPath, $json, LOCK_EX);
}

function voortgang_sort_cached_rows(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $progress = ((float) ($b['progress'] ?? 0)) <=> ((float) ($a['progress'] ?? 0));
        if ($progress !== 0) {
            return $progress;
        }

        return strnatcasecmp((string) ($a['contract_no'] ?? ''), (string) ($b['contract_no'] ?? ''));
    });

    return $rows;
}

/**
 * Haalt één contract + werkorders live uit BC en bouwt een cache-rij.
 * Null = geen werkorders meer (rij moet uit cache).
 */
function voortgang_build_contract_row_from_bc(string $company, string $contractNo): ?array
{
    $contractNo = trim($contractNo);
    if ($contractNo === '') {
        throw new InvalidArgumentException('Contractnummer ontbreekt.');
    }

    $escaped = bc_escape_odata_string($contractNo);
    $rows = [];

    voortgang_paginate_entity(
        $company,
        VOORTGANG_WORKORDERS_ENTITY,
        [
            '$select' => VOORTGANG_WORKORDERS_SELECT,
            '$filter' => "Contract_No eq '" . $escaped . "'",
        ],
        static function (array $row) use (&$rows, $contractNo): bool {
            $rowContract = voortgang_scalar_string($row['Contract_No'] ?? '');
            if ($rowContract !== $contractNo) {
                return false;
            }

            return voortgang_append_workorder_item($rows, $contractNo, $row);
        }
    );

    if (!isset($rows[$contractNo]) || !is_array($rows[$contractNo]['workorders'] ?? null) || $rows[$contractNo]['workorders'] === []) {
        return null;
    }

    $workorderNos = [];
    foreach ($rows[$contractNo]['workorders'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $no = voortgang_scalar_string($item['no'] ?? '');
        if ($no !== '') {
            $workorderNos[] = $no;
        }
    }
    $proformaMap = voortgang_fetch_proforma_map($company, $workorderNos);
    voortgang_apply_proforma_map_to_rows($rows, $proformaMap);

    voortgang_paginate_entity(
        $company,
        VOORTGANG_CONTRACTS_ENTITY,
        [
            '$select' => VOORTGANG_CONTRACTS_SELECT,
            '$filter' => "Contract_No eq '" . $escaped . "'",
        ],
        static function (array $row) use (&$rows, $contractNo): bool {
            if (voortgang_scalar_string($row['Contract_No'] ?? '') !== $contractNo || !isset($rows[$contractNo])) {
                return false;
            }

            $rows[$contractNo]['description'] = voortgang_scalar_string($row['Description'] ?? '');
            $rows[$contractNo]['invoice_period'] = voortgang_scalar_string($row['Invoice_Period'] ?? '');
            $rows[$contractNo]['instructions'] = voortgang_scalar_string($row['KVT_Memo_Internal_Use_Only'] ?? '');
            $rows[$contractNo]['total_sales'] = voortgang_scalar_float($row['KVT_Total_Sales_Price'] ?? 0);
            $rows[$contractNo]['total_revenue'] = -voortgang_scalar_float($row['KVT_Total_Revenue'] ?? 0);
            $rows[$contractNo]['total_cost'] = voortgang_scalar_float($row['KVT_Total_Cost'] ?? 0);

            return true;
        }
    );

    $final = voortgang_finalize_rows($rows);

    return $final[0] ?? null;
}

/**
 * Vervangt of verwijdert één contractrij in de company-cache (met write-lock).
 */
function voortgang_upsert_contract_row_in_cache(string $company, string $contractNo, ?array $row): void
{
    $lockPath = voortgang_rows_write_lock_path($company);
    $lock = fopen($lockPath, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Cache write-lock kon niet worden geopend.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cache write-lock kon niet worden verkregen.');
        }

        $files = voortgang_company_cache_files($company);
        $list = voortgang_read_company_rows($company);
        $next = [];
        $replaced = false;

        foreach ($list as $existing) {
            if (!is_array($existing)) {
                continue;
            }
            if ((string) ($existing['contract_no'] ?? '') === $contractNo) {
                $replaced = true;
                if ($row !== null) {
                    $next[] = $row;
                }
                continue;
            }
            $next[] = $existing;
        }

        if (!$replaced && $row !== null) {
            $next[] = $row;
        }

        $next = voortgang_sort_cached_rows($next);
        voortgang_write_json_file($files['rows'], $next);

        $meta = voortgang_read_company_meta($company);
        if (!is_array($meta)) {
            $meta = [
                'version' => VOORTGANG_CACHE_VERSION,
                'company' => $company,
                'cached_at' => time(),
            ];
        }
        try {
            $meta['environment'] = (string) (voortgang_bc_auth($company)['environment'] ?? '');
        } catch (Throwable $ignored) {
        }
        $bounds = voortgang_workorder_date_bounds_from_rows($next);
        $meta['date_min'] = $bounds['min'];
        $meta['date_max'] = $bounds['max'];
        $meta['contract_count'] = count($next);
        voortgang_write_json_file($files['meta'], $meta);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function voortgang_refresh_contract_as_leader(string $company, string $contractNo, string $statusPath): array
{
    $startedAt = time();
    voortgang_write_refresh_status($statusPath, [
        'state' => 'running',
        'started_at' => $startedAt,
        'company' => $company,
        'contract_no' => $contractNo,
    ]);

    try {
        $row = voortgang_build_contract_row_from_bc($company, $contractNo);
        voortgang_upsert_contract_row_in_cache($company, $contractNo, $row);

        $result = [
            'ok' => true,
            'shared' => false,
            'removed' => $row === null,
            'contract_no' => $contractNo,
            'row' => $row,
            'refreshed_at' => time(),
        ];
        voortgang_write_refresh_status($statusPath, [
            'state' => 'done',
            'started_at' => $startedAt,
            'finished_at' => time(),
            'company' => $company,
            'contract_no' => $contractNo,
            'result' => $result,
        ]);

        return $result;
    } catch (Throwable $error) {
        $result = [
            'ok' => false,
            'shared' => false,
            'removed' => false,
            'contract_no' => $contractNo,
            'error' => $error->getMessage(),
            'refreshed_at' => time(),
        ];
        voortgang_write_refresh_status($statusPath, [
            'state' => 'error',
            'started_at' => $startedAt,
            'finished_at' => time(),
            'company' => $company,
            'contract_no' => $contractNo,
            'result' => $result,
        ]);

        return $result;
    }
}

/**
 * Ververst één contract uit BC. Gelijktijdige clients meeliften op dezelfde job.
 */
function voortgang_refresh_contract(string $company, string $contractNo): array
{
    $company = trim($company);
    $contractNo = trim($contractNo);
    if ($company === '' || $contractNo === '') {
        throw new InvalidArgumentException('Bedrijf of contractnummer ontbreekt.');
    }

    $paths = voortgang_refresh_job_paths($company, $contractNo);
    $lock = fopen($paths['lock'], 'c+');
    if ($lock === false) {
        throw new RuntimeException('Refresh-lock kon niet worden geopend.');
    }

    $requestStarted = time();
    $waitedForLock = false;

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            $waitedForLock = true;
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Refresh-lock kon niet worden verkregen.');
            }
        }

        if ($waitedForLock) {
            $status = voortgang_read_refresh_status($paths['status']);
            $state = is_array($status) ? (string) ($status['state'] ?? '') : '';
            $finishedAt = is_array($status) ? (int) ($status['finished_at'] ?? 0) : 0;
            if (($state === 'done' || $state === 'error') && $finishedAt >= ($requestStarted - 1)) {
                $result = is_array($status['result'] ?? null) ? $status['result'] : null;
                if (is_array($result)) {
                    $result['shared'] = true;

                    return $result;
                }
            }
        }

        return voortgang_refresh_contract_as_leader($company, $contractNo, $paths['status']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
