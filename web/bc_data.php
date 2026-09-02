<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

function bc_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function bc_company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = bc_escape_odata_string($company);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function bc_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    global $baseUrl;

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $url = bc_company_entity_url($baseUrl, $environment, $company, $entitySet, $query);

    return odata_get_all($url, $auth, $ttl);
}

function bc_try_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    try {
        return bc_fetch_rows($company, $entitySet, $query, $ttl);
    } catch (Throwable $error) {
        return [];
    }
}

function bc_default_companies(): array
{
    return [
        'Koninklijke van Twist',
        'Hunter van Twist',
        'KVT Gas',
    ];
}

function bc_companies_for_page(int $ttl = 3600): array
{
    try {
        $result = auth_discover_companies_across_active_environments($ttl);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
        if ($companies !== []) {
            return $companies;
        }
    } catch (Throwable $ignored) {
    }

    return bc_default_companies();
}
