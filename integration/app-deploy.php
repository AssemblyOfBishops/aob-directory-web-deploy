<?php
/**
 * GitHub webhook receiver: a push to the `deploy` branch makes www pull it.
 *
 * Lives at public_html/directorycore/app-deploy.php. GitHub POSTs here with
 * an HMAC-SHA256 signature over the raw body; the shared secret is read from
 * ~/deploy/webhook-secret (outside the docroot, created once on the host, the
 * same value pasted into the GitHub webhook). Anything unsigned or for another
 * branch is ignored with a 2xx so GitHub does not keep retrying.
 *
 * The actual work is integration/deploy.sh. PHP-FPM on this host has exec()
 * and friends disabled, so the receiver cannot run it directly: it drops a
 * trigger file (~/deploy/pending) that a once-a-minute cron picks up
 * (`deploy.sh poll`). Where proc_open is available it is also kicked off
 * immediately. A second cron pulls unconditionally every 30 minutes as a
 * fallback for missed deliveries.
 */

declare(strict_types=1);

$home = getenv('HOME') ?: dirname(__DIR__, 2);
$secretFile = $home . '/deploy/webhook-secret';
$script = $home . '/deploy/aob-directory-web/integration/deploy.sh';
$trigger = $home . '/deploy/pending';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "POST only\n";
    exit;
}

$secret = is_readable($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
if ($secret === '') {
    http_response_code(500);
    echo "webhook secret not configured\n";
    exit;
}

$body = (string) file_get_contents('php://input');
$given = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if ($given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "bad signature\n";
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    echo "pong\n";
    exit;
}

$payload = json_decode($body, true);
$ref = is_array($payload) ? ($payload['ref'] ?? '') : '';
if ($event !== 'push' || $ref !== 'refs/heads/deploy') {
    echo "ignored: {$event} {$ref}\n";
    exit;
}

$after = substr((string) ($payload['after'] ?? ''), 0, 12);
if (@file_put_contents($trigger, $after . ' ' . gmdate('c') . "\n") === false) {
    http_response_code(500);
    echo "could not write trigger at {$trigger}\n";
    exit;
}

// Fast path where the host allows it: run the deploy now, detached. The cron
// poll still finds the trigger consumed (deploy.sh removes it) or, if this
// fails, still present -- either way the deploy happens within a minute.
$started = false;
if (function_exists('proc_open') && is_executable($script)) {
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = @proc_open([$script, 'webhook'], $spec, $pipes);
    if (is_resource($proc)) {
        $started = true;
        // Not waiting on it: the handle is dropped and the child keeps running.
    }
}
echo "deploy " . ($started ? "started" : "queued") . " for {$after}\n";
