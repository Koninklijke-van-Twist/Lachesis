<?php

declare(strict_types=1);

// Guard: contracten > 0 maar geen (gekoppelde) werkorders => fout, cache blijft staan.
ini_set('log_errors', '0');
require dirname(__DIR__) . '/web/voortgang_data.php';

function guard_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$throws = static function (array $wo, array $ct): bool {
    try {
        voortgang_assert_refresh_plausible('KVT', $wo, $ct);
        return false;
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'niet overschreven');
    }
};

if (!$throws(['read' => 0, 'kept' => 0, 'pages' => 1], ['read' => 1236, 'kept' => 0])) {
    guard_fail('0 werkorders bij 1236 contracten hoort te falen');
}
if (!$throws(['read' => 5000, 'kept' => 0], ['read' => 766, 'kept' => 0])) {
    guard_fail('werkorders zonder contract bij contracten > 0 hoort te falen');
}
if ($throws(['read' => 5000, 'kept' => 800], ['read' => 1236, 'kept' => 700])) {
    guard_fail('normale run mag niet falen');
}
if ($throws(['read' => 0, 'kept' => 0], ['read' => 0, 'kept' => 0])) {
    guard_fail('bedrijf zonder contracten mag leeg zijn');
}

// Werkorder-query mag geen taal-/veldafhankelijk BC-filter op Contract_No meer bevatten.
$src = (string) file_get_contents(dirname(__DIR__) . '/web/voortgang_data.php');
if (str_contains($src, '\'$filter\' => "Contract_No ne')) {
    guard_fail("AppWerkorders-query gebruikt nog Contract_No ne '' (leeg via Mímir-tunnel)");
}
$f = voortgang_odata_eq_or_filter('Contract_No', ['400', '401']);
if (!str_contains($f, "Contract_No eq '400'") || !str_contains($f, ' or ')) {
    guard_fail('chunkfilter hoort Contract_No eq ... or ... te zijn');
}

echo "OK refresh_guard_test\n";
