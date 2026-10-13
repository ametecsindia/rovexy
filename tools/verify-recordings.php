<?php
// Post-restore check: every recording marked Available must exist in storage with the recorded size and SHA-256.
// Exit code 0 = all usable; 1 = problems listed. Works without the Laravel framework (PDO only).
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE')),
    getenv('DB_USERNAME'), getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root = rtrim((string) getenv('RECORDINGS_ROOT'), '/');
$ok = 0; $bad = [];
foreach ($pdo->query("SELECT id, company_id, object_key, size_bytes, sha256 FROM recordings WHERE status = 'available'") as $r) {
    $path = $root . '/' . $r['object_key'];
    if (!str_starts_with($r['object_key'], "companies/{$r['company_id']}/")) { $bad[] = "#{$r['id']}: key outside company prefix"; continue; }
    if (!is_file($path)) { $bad[] = "#{$r['id']}: missing {$r['object_key']}"; continue; }
    if (filesize($path) !== (int) $r['size_bytes'] || hash_file('sha256', $path) !== $r['sha256']) { $bad[] = "#{$r['id']}: size/checksum mismatch"; continue; }
    $ok++;
}
$counts = $pdo->query("SELECT (SELECT COUNT(*) FROM companies) c, (SELECT COUNT(*) FROM calls) calls, (SELECT COUNT(*) FROM customers) cust")->fetch(PDO::FETCH_ASSOC);
echo "Restored: {$counts['c']} companies, {$counts['calls']} calls, {$counts['cust']} customers; recordings verified: {$ok}\n";
foreach ($bad as $b) echo "PROBLEM {$b}\n";
exit($bad ? 1 : 0);
