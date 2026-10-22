<?php
/**
 * Nightly-run-beheer voor webaanroepen: run-lock (flock, stale-detectie) en een
 * statusbestand zodat een achtergrondrun te volgen is via nightly.php?status=1.
 * Bevat alleen functies; nightly.php bepaalt wanneer ze gebruikt worden.
 */

const LACHESIS_NIGHTLY_STALE_SECONDS = 1800;

function nightly_run_dir(?string $dir = null): string
{
    $dir = $dir ?? (function_exists('voortgang_cache_base_dir') ? voortgang_cache_base_dir() : sys_get_temp_dir());
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return rtrim($dir, DIRECTORY_SEPARATOR);
}

function nightly_run_lock_path(?string $dir = null): string
{
    return nightly_run_dir($dir) . DIRECTORY_SEPARATOR . 'nightly.run.lock';
}

function nightly_run_status_path(?string $dir = null): string
{
    return nightly_run_dir($dir) . DIRECTORY_SEPARATOR . 'nightly.status.json';
}

function nightly_read_status(?string $dir = null): array
{
    $raw = @file_get_contents(nightly_run_status_path($dir));
    $data = is_string($raw) ? json_decode($raw, true) : null;

    return is_array($data) ? $data : [];
}

function nightly_write_status(array $status, ?string $dir = null): void
{
    $path = nightly_run_status_path($dir);
    $tmp = $path . '.' . getmypid() . '.tmp';
    $status['updated_at'] = time();
    if (@file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $path);
    }
}

function nightly_pid_alive(int $pid): ?bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0) || (function_exists('posix_get_last_error') && posix_get_last_error() === 1);
    }
    if (is_dir('/proc/' . $pid)) {
        return true;
    }

    return is_dir('/proc/self') ? false : null; // onbekend zonder posix en /proc
}

/**
 * Probeert de run-lock te nemen. flock wordt door het OS vrijgegeven als het proces
 * sterft, dus een achtergebleven lockbestand van een dode run blokkeert nooit.
 * Geeft [handle|null, reden] terug.
 */
function nightly_try_acquire_run_lock(?string $dir = null): array
{
    $path = nightly_run_lock_path($dir);
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return [null, 'lock_unavailable'];
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);

        return [null, 'running'];
    }
    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    fflush($handle);
    @touch($path);

    return [$handle, 'acquired'];
}

function nightly_release_run_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Is er nu een run actief? Kijkt naar de flock (betrouwbaar) en markeert een status
 * die 'running' zegt zonder levende houder of ouder dan de stale-grens als stale.
 */
function nightly_status_view(?string $dir = null, ?int $now = null): array
{
    $now = $now ?? time();
    $status = nightly_read_status($dir);
    $lockHeld = false;
    $path = nightly_run_lock_path($dir);
    if (is_file($path)) {
        $handle = @fopen($path, 'c+');
        if ($handle !== false) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                flock($handle, LOCK_UN);
            } else {
                $lockHeld = true;
            }
            fclose($handle);
        }
    }

    $state = (string) ($status['state'] ?? 'idle');
    $startedAt = (int) ($status['started_at'] ?? 0);
    $heartbeat = (int) ($status['updated_at'] ?? $startedAt);
    $stale = false;
    if ($state === 'running') {
        $pidAlive = nightly_pid_alive((int) ($status['pid'] ?? 0));
        if (!$lockHeld || $pidAlive === false) {
            $stale = true; // proces is weg zonder af te ronden
        } elseif ($heartbeat > 0 && ($now - $heartbeat) > LACHESIS_NIGHTLY_STALE_SECONDS) {
            $stale = true; // houder leeft maar doet al >30 min niets meer
        }
        if ($stale) {
            $state = 'stale';
        }
    }

    $status['state'] = $state;
    $status['running'] = $state === 'running';
    $status['lock_held'] = $lockHeld;
    $status['stale'] = $stale;
    $status['now'] = $now;
    if ($startedAt > 0) {
        $status['elapsed_seconds'] = (int) (($status['finished_at'] ?? 0) ?: $now) - $startedAt;
    }

    return $status;
}

/**
 * Stuurt de response naar de browser en laat PHP daarna doorwerken.
 */
function nightly_detach_response(array $payload, int $httpStatus = 202): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!headers_sent()) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Connection: close');
        header('Content-Length: ' . strlen((string) $body));
        header('X-Accel-Buffering: no');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    flush();
}
