<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';

/**
 * Page load
 */

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
    'ok' => true,
    'skipped' => true,
    'reason' => 'Contractvoortgang ververst via nightly.php.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
