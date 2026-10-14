<?php
/**
 * Update progress while ROVEXY is in maintenance mode (every Laravel route answers 503 then). Boots nothing: reads
 * storage/app/updates/state.json and answers only to the poll token the console received when it started the install.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');
$f = __DIR__ . '/../storage/app/updates/state.json';
$s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
$t = (string) ($_GET['t'] ?? '');
if (!is_array($s) || empty($s['poll_token']) || $t === '' || !hash_equals((string) $s['poll_token'], $t)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
echo json_encode(['ok' => true, 'phase' => $s['phase'] ?? null, 'percent' => $s['percent'] ?? 0, 'message' => $s['message'] ?? null,
    'current_version' => $s['current_version'] ?? null, 'log' => array_slice($s['log'] ?? [], -20)]);
