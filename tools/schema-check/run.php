<?php
// Usage: php run.php <dsn-db-name>  — applies every migration (up), then rolls all back (down), then re-applies.
require __DIR__ . '/shim.php';
use Illuminate\Support\Facades\Schema;
$db = $argv[1] ?? 'ammp_schema';
$pdo = new PDO('mysql:host=127.0.0.1', getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `$db`; CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `$db`");
$pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
Schema::$pdo = $pdo;
$files = glob(__DIR__ . '/../../backend/database/migrations/*.php');
sort($files);
$migs = array_map(fn ($f) => [basename($f), require $f], $files);
foreach ($migs as [$n, $m]) { $m->up(); echo "up   $n\n"; }
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (array_reverse($migs) as [$n, $m]) { $m->down(); echo "down $n\n"; }
$left = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($left) { fwrite(STDERR, 'Tables left after rollback: ' . implode(',', $left) . "\n"); exit(1); }
foreach ($migs as [$n, $m]) { $m->up(); }
$fks = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '$db'")->fetchColumn();
echo count($tables) . " tables, {$fks} foreign keys created; rollback clean; re-applied OK\n";
