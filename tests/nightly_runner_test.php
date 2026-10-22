<?php
/**
 * Nightly-runner: run-lock tegen dubbele runs, stale-detectie en status.
 * Run: php tests/nightly_runner_test.php
 */
declare(strict_types=1);
ini_set('log_errors', '0');
require dirname(__DIR__) . '/web/nightly_runner.php';

function nr_fail(string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); }

$dir = sys_get_temp_dir() . '/nightly_runner_test_' . getmypid();
@mkdir($dir);

if (nightly_status_view($dir)['state'] !== 'idle') nr_fail('zonder status hoort idle');

[$h, $r] = nightly_try_acquire_run_lock($dir);
if ($h === null || $r !== 'acquired') nr_fail('eerste lock hoort te lukken');
[$h2, $r2] = nightly_try_acquire_run_lock($dir);
if ($h2 !== null || $r2 !== 'running') nr_fail('tweede run hoort geweigerd te worden');

nightly_write_status(['state' => 'running', 'pid' => getmypid(), 'started_at' => time()], $dir);
$v = nightly_status_view($dir);
if (!$v['running'] || !$v['lock_held'] || $v['stale']) nr_fail('lopende run hoort zichtbaar te zijn');

// Heartbeat ouder dan 30 min => stale, ook met lock.
$v = nightly_status_view($dir, time() + LACHESIS_NIGHTLY_STALE_SECONDS + 5);
if ($v['state'] !== 'stale') nr_fail('run zonder heartbeat >30 min hoort stale');

nightly_release_run_lock($h);
// Status zegt running maar niemand houdt de lock (dode run) => stale, en nieuwe lock lukt.
$v = nightly_status_view($dir);
if ($v['state'] !== 'stale' || $v['running']) nr_fail('achtergebleven running-status hoort stale');
[$h3] = nightly_try_acquire_run_lock($dir);
if ($h3 === null) nr_fail('achtergebleven lockbestand mag niet blokkeren');
nightly_release_run_lock($h3);

nightly_write_status(['state' => 'done', 'started_at' => time() - 10, 'finished_at' => time(), 'companies' => [['ok' => true, 'company' => 'KVT']]], $dir);
$v = nightly_status_view($dir);
if ($v['state'] !== 'done' || $v['running'] || $v['companies'][0]['company'] !== 'KVT') nr_fail('klaar-status hoort resultaten te tonen');

array_map('unlink', glob($dir . '/*'));
rmdir($dir);
echo "OK nightly_runner_test\n";
