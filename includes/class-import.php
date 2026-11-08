<?php
/**
 * Move an existing site's database from MySQL or MariaDB onto a Dolt server.
 *
 * Copies every table (structure and rows), checks row counts, records a
 * baseline snapshot and creates the branches you name. Afterwards you point
 * wp-config.php at the Dolt server and the site carries on as before, with
 * branches. Nothing on the source is changed.
 */
defined('ABSPATH') || exit;

class WPBR_Import
{
    private mysqli $src;
    private mysqli $dst;
    private string $dst_db;

    public function __construct(private array $from, private array $to)
    {
        $this->src = self::connect($from, 'source');
        $this->dst = self::connect($to, 'target', false);
        $this->dst_db = $to['db'];
    }

    /** mysql://user:pass@host:3306/dbname (pass and port optional). */
    public static function parse_dsn(string $dsn): array
    {
        $p = parse_url($dsn);
        if (!$p || empty($p['host']) || empty($p['path'])) {
            throw new InvalidArgumentException('Expected mysql://user:password@host:port/database, got ' . $dsn);
        }
        return [
            'host' => $p['host'], 'port' => (int) ($p['port'] ?? 3306),
            'user' => rawurldecode($p['user'] ?? 'root'), 'pass' => rawurldecode($p['pass'] ?? ''),
            'db'   => ltrim($p['path'], '/'),
        ];
    }

    /** The database this WordPress site is reading from right now. */
    public static function current_site(): array
    {
        $host = DB_HOST; $port = 3306;
        if (str_contains($host, ':')) { [$host, $p] = explode(':', $host, 2); if (is_numeric($p)) $port = (int) $p; }
        return ['host' => $host, 'port' => $port, 'user' => DB_USER, 'pass' => DB_PASSWORD, 'db' => explode('/', DB_NAME, 2)[0]];
    }

    private static function connect(array $c, string $what, bool $select_db = true): mysqli
    {
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
        if (!@$m->real_connect($c['host'], $c['user'], $c['pass'], $select_db ? $c['db'] : null, $c['port'])) {
            throw new RuntimeException("Could not connect to the $what ({$c['host']}:{$c['port']}): " . $m->connect_error);
        }
        $m->set_charset('utf8mb4');
        return $m;
    }

    /** Tables on the source with row counts and sizes, for a dry run and for the plan. */
    public function plan(): array
    {
        $db = $this->src->real_escape_string($this->from['db']);
        $rows = $this->src->query("SELECT TABLE_NAME AS t, TABLE_ROWS AS est, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$db' AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $n = (int) $this->src->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $r['t']) . '`')->fetch_row()[0];
            $out[] = ['table' => $r['t'], 'rows' => $n, 'bytes' => (int) $r['bytes']];
        }
        return $out;
    }

    public function target_is_dolt(): bool
    {
        try { $r = $this->dst->query('SELECT dolt_version()'); return $r && $r->fetch_row(); } catch (Throwable $e) { return false; }
    }

    public function target_has_tables(): array
    {
        $db = $this->dst->real_escape_string($this->dst_db);
        $r = $this->dst->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$db'");
        return $r ? array_column($r->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME') : [];
    }

    /**
     * Copy everything. $log receives progress lines. Returns per-table results.
     * @param string[] $branches  branch names to create from the baseline
     */
    public function run(callable $log, int $batch = 1000, bool $replace = false, array $branches = []): array
    {
        $plan = $this->plan();
        $this->dst->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $this->dst_db) . '`');
        $this->dst->select_db($this->dst_db);
        $existing = $this->target_has_tables();
        if ($existing && !$replace) {
            throw new RuntimeException('The target database already has ' . count($existing) . ' table(s). Use --replace to drop and reload them.');
        }
        $this->dst->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->dst->query('SET UNIQUE_CHECKS = 0');
        $results = [];
        foreach ($plan as $p) {
            $t = $p['table'];
            $q = '`' . str_replace('`', '``', $t) . '`';
            $log(sprintf('%-40s %8d rows', $t, $p['rows']));
            if (in_array($t, $existing, true)) $this->dst->query("DROP TABLE IF EXISTS $q");
            $this->create_table($t);
            $copied = $this->copy_rows($t, $batch, $log);
            $got = (int) $this->dst->query("SELECT COUNT(*) FROM $q")->fetch_row()[0];
            $results[] = ['table' => $t, 'source' => $p['rows'], 'copied' => $copied, 'target' => $got, 'ok' => $got === $p['rows']];
            if ($got !== $p['rows']) $log("  MISMATCH: source {$p['rows']}, target $got");
        }
        $this->dst->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->dst->query('SET UNIQUE_CHECKS = 1');

        // Baseline snapshot and branches.
        $msg = 'Imported from ' . $this->from['host'] . '/' . $this->from['db'] . ' on ' . gmdate('Y-m-d H:i') . ' UTC';
        if (!$this->dst->query("CALL dolt_commit('-A', '-m', '" . $this->dst->real_escape_string($msg) . "')")) {
            throw new RuntimeException('Baseline snapshot failed: ' . $this->dst->error);
        }
        $head = $this->dst->query('SELECT hash FROM dolt_branches WHERE name = active_branch()')->fetch_row()[0] ?? '';
        $log('Baseline snapshot ' . substr($head, 0, 8));
        foreach ($branches as $b) {
            $b = trim($b);
            if ($b === '') continue;
            $this->dst->query("CALL dolt_branch('" . $this->dst->real_escape_string($b) . "')");
            $log("Branch $b created");
        }
        return $results;
    }

    private function create_table(string $t): void
    {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $ddl = $this->src->query("SHOW CREATE TABLE $q")->fetch_row()[1];
        // Things MySQL writes that Dolt does not need or does not accept.
        $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl);
        $ddl = preg_replace('/\s+ROW_FORMAT=\w+/i', '', $ddl);
        $ddl = preg_replace('/\s+KEY_BLOCK_SIZE=\d+/i', '', $ddl);
        $ddl = preg_replace('/\s+STATS_\w+=\w+/i', '', $ddl);
        $ddl = preg_replace('/\s+PAGE_COMPRESSED=\w+/i', '', $ddl);          // MariaDB
        $ddl = preg_replace('/\s+PAGE_COMPRESSION_LEVEL=\d+/i', '', $ddl);  // MariaDB
        $ddl = preg_replace('/\s+COLLATE\s*=?\s*utf8mb4_0900_ai_ci/i', ' COLLATE=utf8mb4_unicode_ci', $ddl);
        $ddl = preg_replace('/\s+COLLATE\s+utf8mb4_0900_ai_ci/i', ' COLLATE utf8mb4_unicode_ci', $ddl);
        $ddl = str_ireplace('utf8mb3', 'utf8', $ddl);
        $ddl = preg_replace('/\s+COLLATE\s*=?\s*utf8mb4_uca1400_ai_ci/i', ' COLLATE=utf8mb4_unicode_ci', $ddl); // MariaDB 11
        $ddl = preg_replace('/\s+COLLATE\s+utf8mb4_uca1400_ai_ci/i', ' COLLATE utf8mb4_unicode_ci', $ddl);
        if (!$this->dst->query($ddl)) {
            // Last resort: drop every table option after the closing parenthesis.
            $bare = preg_replace('/\)\s*ENGINE=.*$/is', ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $ddl);
            if (!$this->dst->query($bare)) {
                throw new RuntimeException("Could not create $t on the target: " . $this->dst->error . "\n$ddl");
            }
        }
    }

    private function copy_rows(string $t, int $batch, callable $log): int
    {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $res = $this->src->query("SELECT * FROM $q", MYSQLI_USE_RESULT);
        if (!$res) throw new RuntimeException("Could not read $t: " . $this->src->error);
        $fields = $res->fetch_fields();
        $cols = implode(', ', array_map(fn($f) => '`' . str_replace('`', '``', $f->name) . '`', $fields));
        $binary = [];
        foreach ($fields as $i => $f) $binary[$i] = ($f->flags & MYSQLI_BINARY_FLAG) && in_array($f->type, [MYSQLI_TYPE_TINY_BLOB, MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING], true);
        $n = 0; $values = [];
        $flush = function () use (&$values, &$n, $q, $cols, $t) {
            if (!$values) return;
            if (!$this->dst->query("INSERT INTO $q ($cols) VALUES " . implode(',', $values))) {
                throw new RuntimeException("Insert into $t failed: " . $this->dst->error);
            }
            $n += count($values);
            $values = [];
        };
        while ($row = $res->fetch_row()) {
            $vals = [];
            foreach ($row as $i => $v) {
                if ($v === null) $vals[] = 'NULL';
                elseif ($binary[$i]) $vals[] = "0x" . bin2hex($v);
                else $vals[] = "'" . $this->dst->real_escape_string($v) . "'";
            }
            $values[] = '(' . implode(',', $vals) . ')';
            if (count($values) >= $batch) {
                $flush();
                if ($n % ($batch * 20) === 0) $log("  $n rows");
            }
        }
        $flush();
        $res->free();
        return $n;
    }

    public function close(): void
    {
        @$this->src->close();
        @$this->dst->close();
    }
}
