<?php
// Minimal stand-in for Laravel's Schema builder (MySQL grammar subset) so the migrations' DDL can be executed against
// a real MySQL/MariaDB server while Packagist is unreachable. It validates table/column/index/FK definitions and
// migration ordering. It is NOT Laravel and does not replace running `php artisan migrate`.
namespace Illuminate\Database\Migrations { abstract class Migration {} }

namespace Illuminate\Database\Schema {
    final class Col {
        public bool $nullable = false; public $default = null; public bool $hasDefault = false; public bool $useCurrent = false;
        public bool $unique = false; public bool $index = false; public ?array $fk = null;
        public function __construct(public string $name, public string $type, private Blueprint $bp) {}
        public function nullable(bool $v = true) { $this->nullable = $v; return $this; }
        public function default($v) { $this->default = $v; $this->hasDefault = true; return $this; }
        public function useCurrent() { $this->useCurrent = true; return $this; }
        public function unique() { $this->unique = true; return $this; }
        public function index() { $this->index = true; return $this; }
        public function primary() { $this->bp->extra[] = "PRIMARY KEY (`{$this->name}`)"; return $this; }
        public function constrained(?string $table = null, string $col = 'id') {
            $base = preg_replace('/_id$/', '', $this->name);
            $table ??= str_ends_with($base, 'y') ? substr($base, 0, -1) . 'ies' : $base . 's';
            $this->fk = ['table' => $table, 'col' => $col, 'on_delete' => null]; return $this;
        }
        public function cascadeOnDelete() { $this->fk['on_delete'] = 'CASCADE'; return $this; }
        public function nullOnDelete() { $this->fk['on_delete'] = 'SET NULL'; return $this; }
        public function sql(): string {
            $s = "`{$this->name}` {$this->type}" . ($this->nullable ? ' NULL' : ' NOT NULL');
            if ($this->useCurrent) $s .= ' DEFAULT CURRENT_TIMESTAMP';
            elseif ($this->hasDefault) $s .= ' DEFAULT ' . (is_bool($this->default) ? (int) $this->default : (is_int($this->default) ? $this->default : "'" . addslashes((string) $this->default) . "'"));
            return $s;
        }
    }
    final class Blueprint {
        public array $cols = []; public array $extra = [];
        public function __construct(public string $table) {}
        private function add(string $n, string $t) { return $this->cols[] = new Col($n, $t, $this); }
        public function id() { $c = $this->add('id', 'BIGINT UNSIGNED AUTO_INCREMENT'); $this->extra[] = 'PRIMARY KEY (`id`)'; return $c; }
        public function foreignId($n) { return $this->add($n, 'BIGINT UNSIGNED'); }
        public function string($n, $l = 255) { return $this->add($n, "VARCHAR($l)"); }
        public function char($n, $l = 255) { return $this->add($n, "CHAR($l)"); }
        public function uuid($n = 'uuid') { return $this->add($n, 'CHAR(36)'); }
        public function text($n) { return $this->add($n, 'TEXT'); }
        public function mediumText($n) { return $this->add($n, 'MEDIUMTEXT'); }
        public function longText($n) { return $this->add($n, 'LONGTEXT'); }
        public function json($n) { return $this->add($n, 'JSON'); }
        public function boolean($n) { return $this->add($n, 'TINYINT(1)'); }
        public function integer($n) { return $this->add($n, 'INT'); }
        public function unsignedInteger($n) { return $this->add($n, 'INT UNSIGNED'); }
        public function unsignedTinyInteger($n) { return $this->add($n, 'TINYINT UNSIGNED'); }
        public function unsignedSmallInteger($n) { return $this->add($n, 'SMALLINT UNSIGNED'); }
        public function unsignedBigInteger($n) { return $this->add($n, 'BIGINT UNSIGNED'); }
        public function decimal($n, $p = 8, $s = 2) { return $this->add($n, "DECIMAL($p,$s)"); }
        public function float($n) { return $this->add($n, 'DOUBLE'); }
        public function timestamp($n) { return $this->add($n, 'TIMESTAMP'); }
        public function date($n) { return $this->add($n, 'DATE'); }
        public function timestamps() { $this->timestamp('created_at')->nullable(); $this->timestamp('updated_at')->nullable(); }
        public function rememberToken() { return $this->string('remember_token', 100)->nullable(); }
        public function primary($cols) { $this->extra[] = 'PRIMARY KEY (' . $this->list($cols) . ')'; }
        public function unique($cols) { $this->extra[] = "UNIQUE KEY `{$this->table}_" . implode('_', (array) $cols) . "_unique` (" . $this->list($cols) . ')'; }
        public function index($cols) { $this->extra[] = "KEY `{$this->table}_" . implode('_', (array) $cols) . "_index` (" . $this->list($cols) . ')'; }
        private function list($c) { return implode(', ', array_map(fn ($x) => "`$x`", (array) $c)); }
        public function toSql(): string {
            $lines = array_map(fn (Col $c) => $c->sql(), $this->cols);
            foreach ($this->cols as $c) {
                if ($c->unique) $lines[] = "UNIQUE KEY `{$this->table}_{$c->name}_unique` (`{$c->name}`)";
                if ($c->index) $lines[] = "KEY `{$this->table}_{$c->name}_index` (`{$c->name}`)";
                if ($c->fk) $lines[] = "CONSTRAINT `{$this->table}_{$c->name}_foreign` FOREIGN KEY (`{$c->name}`) REFERENCES `{$c->fk['table']}` (`{$c->fk['col']}`)" . ($c->fk['on_delete'] ? " ON DELETE {$c->fk['on_delete']}" : '');
            }
            return "CREATE TABLE `{$this->table}` (\n  " . implode(",\n  ", array_merge($lines, $this->extra)) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
    }
}

namespace Illuminate\Support\Facades {
    final class Schema {
        public static \PDO $pdo; public static array $log = [];
        public static function create(string $t, \Closure $fn): void { $b = new \Illuminate\Database\Schema\Blueprint($t); $fn($b); self::run($b->toSql()); }
        public static function dropIfExists(string $t): void { self::run("DROP TABLE IF EXISTS `$t`"); }
        private static function run(string $sql): void { self::$log[] = $sql; self::$pdo->exec($sql); }
    }
}
