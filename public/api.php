<?php
declare(strict_types=1);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function out($data, int $code = 200): never { http_response_code($code); echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }
function fail(string $m, int $code = 400): never { out(['error' => $m], $code); }

/** Encrypt plaintext DB password using a split key stored only in an HttpOnly browser cookie. */
function seal_secret(string $plain): string {
    global $isHttps;
    $key = sodium_crypto_secretbox_keygen();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
    $hexKey = sodium_bin2hex($key);
    setcookie('fandejo_key', $hexKey, ['expires' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
    $_COOKIE['fandejo_key'] = $hexKey;
    return sodium_bin2hex($nonce . $cipher);
}
function open_secret(string $sealed): ?string {
    $hexKey = $_COOKIE['fandejo_key'] ?? ($_COOKIE['typhon_key'] ?? '');
    if (strlen($hexKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES * 2 || $sealed === '') return null;
    try {
        $raw = sodium_hex2bin($sealed);
        $key = sodium_hex2bin($hexKey);
        if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) return null;
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        return $plain === false ? null : $plain;
    } catch (Throwable) { return null; }
}
function clear_auth(): void {
    global $isHttps;
    $_SESSION = [];
    setcookie('fandejo_key', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
    setcookie('typhon_key', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function q(string $id, string $drv): string {
    return $drv === 'mysql' ? '`' . str_replace('`', '``', $id) . '`' : '"' . str_replace('"', '""', $id) . '"';
}

function connect(array $c, ?string $db = null): PDO {
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($c['driver'] === 'sqlite') {
        return new PDO('sqlite:' . $c['path'], null, null, $opts);
    }
    $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4" . ($db ? ";dbname=$db" : '');
    return new PDO($dsn, $c['user'], $c['pass'], $opts);
}

function rows(PDO $p, string $sql, array $args = []): array { $s = $p->prepare($sql); $s->execute($args); return $s->fetchAll(); }

function run_sql(PDO $p, string $sql): array {
    $t = microtime(true);
    $s = $p->query($sql);
    $res = ['ms' => round((microtime(true) - $t) * 1000, 2)];
    if ($s->columnCount() > 0) {
        $res['rows'] = $s->fetchAll(PDO::FETCH_ASSOC);
        $res['columns'] = array_map(fn($i) => $s->getColumnMeta($i)['name'], range(0, $s->columnCount() - 1));
    } else {
        $res['affected'] = $s->rowCount();
    }
    return $res;
}


const PRIV_GLOBAL = ['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','REFERENCES','INDEX','ALTER','CREATE TEMPORARY TABLES','LOCK TABLES','CREATE VIEW','SHOW VIEW','CREATE ROUTINE','ALTER ROUTINE','EXECUTE','EVENT','TRIGGER','FILE','PROCESS','RELOAD','SHUTDOWN','SHOW DATABASES','SUPER','CREATE USER','REPLICATION SLAVE','REPLICATION CLIENT'];
const PRIV_DB = ['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','REFERENCES','INDEX','ALTER','CREATE TEMPORARY TABLES','LOCK TABLES','CREATE VIEW','SHOW VIEW','CREATE ROUTINE','ALTER ROUTINE','EXECUTE','EVENT','TRIGGER'];
const PRIV_TABLE = ['SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','REFERENCES','INDEX','ALTER','CREATE VIEW','SHOW VIEW','TRIGGER'];

function priv_catalog(string $db, string $tb): array {
    return $db === '*' ? PRIV_GLOBAL : ($tb === '*' ? PRIV_DB : PRIV_TABLE);
}
function unq(string $s): string { // `a``b` -> a`b ; \_ -> _
    if ($s === '*') return '*';
    $s = str_replace('``', '`', substr($s, 1, -1));
    return str_replace(['\\_', '\\%'], ['_', '%'], $s);
}
function scope_sql(string $db, string $tb): string {
    if ($db === '*') return '*.*';
    $d = '`' . str_replace(['`', '_', '%'], ['``', '\\_', '\\%'], $db) . '`';
    return $d . '.' . ($tb === '*' ? '*' : '`' . str_replace('`', '``', $tb) . '`');
}
function acct_sql(PDO $p, string $u, string $h): string { return $p->quote($u) . '@' . $p->quote($h); }

/** Parse SHOW GRANTS into [ ['db','table','privs'=>[], 'all'=>bool, 'option'=>bool, 'columns'=>[raw..]] ] */
function parse_grants(PDO $p, string $u, string $h): array {
    return parse_grant_lines(array_column($p->query('SHOW GRANTS FOR ' . acct_sql($p, $u, $h))->fetchAll(PDO::FETCH_NUM), 0));
}
function parse_grant_lines(array $lines): array {
    $out = [];
    $id = '(\*|`(?:[^`]|``)*`)';
    foreach ($lines as $g) {
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(?:(?:TABLE|FUNCTION|PROCEDURE)\s+)?' . $id . '\.' . $id . '\s+TO\s/s', $g, $m)) continue;
        $db = unq($m[2]); $tb = unq($m[3]);
        $k = "$db\0$tb";
        $out[$k] ??= ['db' => $db, 'table' => $tb, 'privs' => [], 'all' => false, 'option' => false, 'columns' => [], 'other' => []];
        $out[$k]['option'] = $out[$k]['option'] || (bool)preg_match('/WITH GRANT OPTION\s*$/i', $g) || (bool)preg_match('/\bWITH\b.*\bGRANT OPTION\b/i', $g);
        $parts = []; $buf = ''; $depth = 0;
        foreach (str_split($m[1]) as $ch) {
            if ($ch === '(') $depth++; if ($ch === ')') $depth--;
            if ($ch === ',' && !$depth) { $parts[] = trim($buf); $buf = ''; } else $buf .= $ch;
        }
        if (trim($buf) !== '') $parts[] = trim($buf);
        $cat = priv_catalog($db, $tb);
        foreach ($parts as $pt) {
            $up = strtoupper($pt);
            if ($up === 'ALL PRIVILEGES' || $up === 'ALL') { $out[$k]['all'] = true; $out[$k]['privs'] = array_values(array_unique(array_merge($out[$k]['privs'], $cat))); }
            elseif ($up === 'USAGE') continue;
            elseif (str_contains($pt, '(')) $out[$k]['columns'][] = $pt;
            elseif (in_array($up, $cat, true)) $out[$k]['privs'][] = $up;
            else $out[$k]['other'][] = $up;
        }
    }
    $list = array_values($out);
    usort($list, fn($a, $b) => [$a['db'] !== '*', $a['db'], $a['table'] !== '*', $a['table']] <=> [$b['db'] !== '*', $b['db'], $b['table'] !== '*', $b['table']]);
    foreach ($list as &$e) { $e['privs'] = array_values(array_unique($e['privs'])); $cat = priv_catalog($e['db'], $e['table']); $e['all'] = $e['all'] || ($e['privs'] && !array_diff($cat, $e['privs'])); }
    return $list;
}

const COL_TYPES = ['TINYINT','SMALLINT','MEDIUMINT','INT','BIGINT','DECIMAL','FLOAT','DOUBLE','BIT','BOOLEAN','DATE','TIME','DATETIME','TIMESTAMP','YEAR','CHAR','VARCHAR','BINARY','VARBINARY','TINYTEXT','TEXT','MEDIUMTEXT','LONGTEXT','TINYBLOB','BLOB','MEDIUMBLOB','LONGBLOB','JSON','ENUM','SET','INTEGER','REAL','NUMERIC'];
const COL_NUMERIC = ['TINYINT','SMALLINT','MEDIUMINT','INT','BIGINT','DECIMAL','FLOAT','DOUBLE','INTEGER','REAL','NUMERIC'];
const COL_TEXT = ['CHAR','VARCHAR','TINYTEXT','TEXT','MEDIUMTEXT','LONGTEXT','ENUM','SET'];

function parse_coltype(string $t): array {
    $o = ['base' => '', 'length' => '', 'values' => [], 'unsigned' => false];
    if (!preg_match('/^([a-z]+)(?:\((.*)\))?(.*)$/is', trim($t), $m)) return $o;
    $o['base'] = strtoupper($m[1]);
    $o['unsigned'] = str_contains(strtolower($m[3] ?? ''), 'unsigned');
    if (in_array($o['base'], ['ENUM', 'SET'], true)) {
        preg_match_all("/'((?:[^']|'')*)'/", $m[2] ?? '', $mm);
        $o['values'] = array_map(fn($v) => str_replace("''", "'", $v), $mm[1]);
    } else $o['length'] = $m[2] ?? '';
    return $o;
}
function parse_default(?string $d, bool $nullable, string $extra, bool $maria, string $drv): array {
    if ($drv === 'sqlite') {
        if ($d === null) return ['mode' => 'none', 'value' => ''];
        if (preg_match("/^'(.*)'$/s", $d, $m)) return ['mode' => 'value', 'value' => str_replace("''", "'", $m[1])];
        return is_numeric($d) ? ['mode' => 'value', 'value' => $d] : ['mode' => 'expr', 'value' => $d];
    }
    if ($d === null) return ['mode' => $nullable ? 'null' : 'none', 'value' => ''];
    if ($maria) {
        if (strtoupper($d) === 'NULL') return ['mode' => 'null', 'value' => ''];
        if (preg_match("/^'(.*)'$/s", $d, $m)) return ['mode' => 'value', 'value' => str_replace("''", "'", $m[1])];
        return is_numeric($d) ? ['mode' => 'value', 'value' => $d] : ['mode' => 'expr', 'value' => $d];
    }
    return str_contains($extra, 'DEFAULT_GENERATED') ? ['mode' => 'expr', 'value' => $d] : ['mode' => 'value', 'value' => $d];
}
/** Build a column definition (type + attributes) from request data. Throws on invalid input. */
function col_def(PDO $p, string $drv, array $in): string {
    $base = strtoupper(trim((string)($in['type'] ?? '')));
    if (!in_array($base, COL_TYPES, true)) throw new InvalidArgumentException("Unsupported type '$base'");
    $t = $base;
    if (in_array($base, ['ENUM', 'SET'], true)) {
        $vals = array_values(array_filter(array_map('strval', $in['values'] ?? []), fn($v) => $v !== ''));
        if (!$vals) throw new InvalidArgumentException("$base needs at least one value");
        $t .= '(' . implode(',', array_map(fn($v) => $p->quote($v), $vals)) . ')';
    } else {
        $len = trim((string)($in['length'] ?? ''));
        if ($len !== '') {
            if (!preg_match('/^\d+(,\d+)?$/', $len)) throw new InvalidArgumentException('Invalid length');
            $t .= "($len)";
        }
    }
    $d = $t;
    if ($drv === 'mysql' && !empty($in['unsigned']) && in_array($base, COL_NUMERIC, true)) $d .= ' UNSIGNED';
    $coll = trim((string)($in['collation'] ?? ''));
    if ($drv === 'mysql' && $coll !== '' && in_array($base, COL_TEXT, true)) {
        if (!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/i', $coll)) throw new InvalidArgumentException('Invalid collation');
        $d .= ' CHARACTER SET ' . explode('_', $coll)[0] . ' COLLATE ' . $coll;
    }
    $d .= empty($in['null']) ? ' NOT NULL' : ' NULL';
    $mode = $in['default_mode'] ?? 'none';
    if ($mode === 'null') $d .= ' DEFAULT NULL';
    elseif ($mode === 'value') $d .= ' DEFAULT ' . $p->quote((string)($in['default'] ?? ''));
    elseif ($mode === 'expr') {
        $e = trim((string)($in['default'] ?? ''));
        if (!preg_match('/^[A-Za-z_]+(\([\w\s,.\']*\))?$/', $e)) throw new InvalidArgumentException('Invalid default expression');
        $d .= " DEFAULT $e";
    }
    if ($drv === 'mysql' && !empty($in['on_update']) && in_array($base, ['DATETIME', 'TIMESTAMP'], true)) $d .= ' ON UPDATE CURRENT_TIMESTAMP';
    if ($drv === 'mysql' && !empty($in['ai'])) $d .= ' AUTO_INCREMENT';
    if ($drv === 'mysql' && trim((string)($in['comment'] ?? '')) !== '') $d .= ' COMMENT ' . $p->quote((string)$in['comment']);
    return $d;
}
function col_pos(string $drv, array $in): string {
    if ($drv !== 'mysql') return '';
    $a = (string)($in['after'] ?? '');
    return $a === '' ? '' : ($a === '__FIRST__' ? ' FIRST' : ' AFTER ' . q($a, $drv));
}

const FK_RULES = ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION', 'SET DEFAULT'];
function col_list(string $drv, array $cols, bool $lengths = false): string {
    if (!$cols) throw new InvalidArgumentException('Pick at least one column');
    return '(' . implode(', ', array_map(function ($c) use ($drv, $lengths) {
        $c = is_array($c) ? $c : ['name' => $c];
        $n = trim((string)($c['name'] ?? '')); if ($n === '') throw new InvalidArgumentException('Column name missing');
        $len = trim((string)($c['length'] ?? ''));
        if ($len !== '' && !preg_match('/^\d+$/', $len)) throw new InvalidArgumentException('Invalid prefix length');
        return q($n, $drv) . ($lengths && $len !== '' && $drv === 'mysql' ? "($len)" : '');
    }, $cols)) . ')';
}
function list_indexes(PDO $p, string $drv, string $T, ?string $tb): array {
    $out = [];
    if ($drv === 'mysql') {
        foreach (rows($p, "SHOW INDEX FROM $T") as $r) {
            $k = $r['Key_name'];
            $out[$k] ??= ['name' => $k, 'unique' => !$r['Non_unique'], 'type' => $k === 'PRIMARY' ? 'PRIMARY' : (str_contains(strtoupper($r['Index_type']), 'FULLTEXT') ? 'FULLTEXT' : (str_contains(strtoupper($r['Index_type']), 'SPATIAL') ? 'SPATIAL' : (!$r['Non_unique'] ? 'UNIQUE' : 'INDEX'))), 'method' => $r['Index_type'], 'columns' => [], 'cardinality' => $r['Cardinality'], 'droppable' => true];
            $out[$k]['columns'][] = ['name' => $r['Column_name'], 'length' => $r['Sub_part'], 'desc' => ($r['Collation'] ?? 'A') === 'D'];
        }
    } else {
        $pk = array_values(array_map(fn($x) => ['name' => $x['name'], 'length' => null, 'desc' => false], array_filter(rows($p, 'PRAGMA table_info(' . q($tb, $drv) . ')'), fn($x) => $x['pk'] > 0)));
        if ($pk) $out['PRIMARY'] = ['name' => 'PRIMARY', 'unique' => true, 'type' => 'PRIMARY', 'method' => 'ROWID', 'columns' => $pk, 'cardinality' => null, 'droppable' => false];
        foreach (rows($p, 'PRAGMA index_list(' . q($tb, $drv) . ')') as $ix) {
            if ($ix['origin'] === 'pk') continue;
            $out[$ix['name']] = ['name' => $ix['name'], 'unique' => (bool)$ix['unique'], 'type' => $ix['unique'] ? 'UNIQUE' : 'INDEX', 'method' => 'BTREE', 'cardinality' => null, 'droppable' => $ix['origin'] === 'c',
                'columns' => array_map(fn($c) => ['name' => $c['name'], 'length' => null, 'desc' => false], rows($p, 'PRAGMA index_info(' . q($ix['name'], $drv) . ')'))];
        }
    }
    return array_values($out);
}

function list_fks(PDO $p, string $drv, ?string $db, ?string $tb): array {
    $out = [];
    if ($drv === 'mysql') {
        $rs = rows($p, "SELECT k.CONSTRAINT_NAME name, k.COLUMN_NAME col, k.REFERENCED_TABLE_SCHEMA rdb, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc, r.UPDATE_RULE ur, r.DELETE_RULE dr
            FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION", [$db, $tb]);
        foreach ($rs as $r) {
            $out[$r['name']] ??= ['name' => $r['name'], 'ref_db' => $r['rdb'], 'ref_table' => $r['rt'], 'on_update' => $r['ur'], 'on_delete' => $r['dr'], 'pairs' => [], 'droppable' => true];
            $out[$r['name']]['pairs'][] = ['col' => $r['col'], 'ref' => $r['rc']];
        }
    } else {
        foreach (rows($p, 'PRAGMA foreign_key_list(' . q($tb, $drv) . ')') as $r) {
            $k = 'fk_' . $r['id'];
            $out[$k] ??= ['name' => $k, 'ref_db' => null, 'ref_table' => $r['table'], 'on_update' => $r['on_update'], 'on_delete' => $r['on_delete'], 'pairs' => [], 'droppable' => false];
            $out[$k]['pairs'][] = ['col' => $r['from'], 'ref' => $r['to']];
        }
    }
    return array_values($out);
}

/** Find foreign keys on text columns involving $table (or all tables in $db when $table is null) and return [dropStmts, addStmts]. */
function fk_text_conversion_guards(PDO $p, string $db, ?string $table = null): array {
    $sql = "SELECT k.TABLE_SCHEMA sdb, k.TABLE_NAME stb, k.CONSTRAINT_NAME name, k.COLUMN_NAME col,
                   k.REFERENCED_TABLE_SCHEMA rdb, k.REFERENCED_TABLE_NAME rtb, k.REFERENCED_COLUMN_NAME rcol,
                   r.UPDATE_RULE ur, r.DELETE_RULE dr, c1.CHARACTER_SET_NAME cs1, c2.CHARACTER_SET_NAME cs2
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            JOIN information_schema.COLUMNS c1
              ON c1.TABLE_SCHEMA = k.TABLE_SCHEMA AND c1.TABLE_NAME = k.TABLE_NAME AND c1.COLUMN_NAME = k.COLUMN_NAME
            JOIN information_schema.COLUMNS c2
              ON c2.TABLE_SCHEMA = k.REFERENCED_TABLE_SCHEMA AND c2.TABLE_NAME = k.REFERENCED_TABLE_NAME AND c2.COLUMN_NAME = k.REFERENCED_COLUMN_NAME
            WHERE (k.TABLE_SCHEMA = ? OR k.REFERENCED_TABLE_SCHEMA = ?) AND k.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION";
    $fks = [];
    foreach (rows($p, $sql, [$db, $db]) as $r) {
        if ($r['cs1'] === null && $r['cs2'] === null) continue; // numeric/date FK — unaffected by charset conversion
        if ($table !== null && !(($r['sdb'] === $db && $r['stb'] === $table) || ($r['rdb'] === $db && $r['rtb'] === $table))) continue;
        $key = $r['sdb'] . '.' . $r['stb'] . '.' . $r['name'];
        $fks[$key] ??= ['sdb' => $r['sdb'], 'stb' => $r['stb'], 'name' => $r['name'], 'rdb' => $r['rdb'], 'rtb' => $r['rtb'], 'ur' => $r['ur'], 'dr' => $r['dr'], 'cols' => [], 'refs' => []];
        $fks[$key]['cols'][] = $r['col'];
        $fks[$key]['refs'][] = $r['rcol'];
    }
    $drops = []; $adds = [];
    foreach ($fks as $f) {
        $ST = q($f['sdb'], 'mysql') . '.' . q($f['stb'], 'mysql');
        $RT = q($f['rdb'], 'mysql') . '.' . q($f['rtb'], 'mysql');
        $drops[] = "ALTER TABLE $ST DROP FOREIGN KEY " . q($f['name'], 'mysql');
        $adds[] = "ALTER TABLE $ST ADD CONSTRAINT " . q($f['name'], 'mysql') . ' FOREIGN KEY ' . col_list('mysql', $f['cols'])
            . " REFERENCES $RT " . col_list('mysql', $f['refs']) . " ON DELETE {$f['dr']} ON UPDATE {$f['ur']}";
    }
    return [$drops, $adds];
}

/** Build two-step MODIFY via BINARY statements for a table to fix UTF-8 bytes stored inside latin1 columns without double-encoding. */
function binary_relabel_table_sql(PDO $p, string $db, string $tb, string $cs, string $coll): array {
    $T = q($db, 'mysql') . '.' . q($tb, 'mysql');
    $maria = stripos((string)$p->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
    $binMap = ['CHAR' => 'BINARY', 'VARCHAR' => 'VARBINARY', 'TINYTEXT' => 'TINYBLOB', 'TEXT' => 'BLOB', 'MEDIUMTEXT' => 'MEDIUMBLOB', 'LONGTEXT' => 'LONGBLOB'];
    $step1 = []; $step2 = [];
    foreach (rows($p, "SHOW FULL COLUMNS FROM $T") as $x) {
        $pt = parse_coltype($x['Type']);
        if (!isset($binMap[$pt['base']])) continue;
        $bType = $binMap[$pt['base']] . ($pt['length'] !== '' ? "({$pt['length']})" : '');
        $nullSql = $x['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        $def = parse_default($x['Default'], $x['Null'] === 'YES', $x['Extra'], $maria, 'mysql');
        $defSql = $def['mode'] === 'null' ? ' DEFAULT NULL' : ($def['mode'] === 'value' ? ' DEFAULT ' . $p->quote($def['value']) : ($def['mode'] === 'expr' ? " DEFAULT {$def['value']}" : ''));
        $cmtSql = ($x['Comment'] ?? '') !== '' ? ' COMMENT ' . $p->quote($x['Comment']) : '';
        $step1[] = 'MODIFY ' . q($x['Field'], 'mysql') . " $bType$nullSql";
        $step2[] = 'MODIFY ' . q($x['Field'], 'mysql') . " {$x['Type']} CHARACTER SET $cs COLLATE $coll$nullSql$defSql$cmtSql";
    }
    $out = [];
    if ($step1) $out[] = "ALTER TABLE $T " . implode(', ', $step1);
    $out[] = "ALTER TABLE $T DEFAULT CHARACTER SET $cs COLLATE $coll" . ($step2 ? ', ' . implode(', ', $step2) : '');
    return $out;
}

function table_cols(PDO $p, string $drv, string $T, ?string $tb): array {
    return array_column(rows($p, $drv === 'sqlite' ? 'PRAGMA table_info(' . q((string)$tb, $drv) . ')' : "SHOW COLUMNS FROM $T"), $drv === 'sqlite' ? 'name' : 'Field');
}
/** WHERE / ORDER BY for the table browser search+sort; fills $args. */
function filter_sql(string $drv, array $cols, array $in, array &$args): array {
    $where = ''; $args = [];
    if (($in['search'] ?? '') !== '') {
        $parts = [];
        foreach ($cols as $col) { $parts[] = 'CAST(' . q($col, $drv) . ' AS ' . ($drv === 'mysql' ? 'CHAR' : 'TEXT') . ') LIKE ?'; $args[] = '%' . $in['search'] . '%'; }
        $where = ' WHERE ' . implode(' OR ', $parts);
    }
    $order = (!empty($in['sort']) && in_array($in['sort'], $cols, true)) ? ' ORDER BY ' . q($in['sort'], $drv) . (($in['dir'] ?? '') === 'desc' ? ' DESC' : ' ASC') : '';
    return [$where, $order];
}

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$a = $_GET['a'] ?? '';

if ($a === 'session') {
    $ok = isset($_SESSION['c']['sealed_pass']) && open_secret($_SESSION['c']['sealed_pass']) !== null;
    if (!$ok && isset($_SESSION['c'])) clear_auth();
    out(['auth' => $ok, 'driver' => $ok ? $_SESSION['c']['driver'] : null, 'user' => $ok ? $_SESSION['c']['user'] : null]);
}
if ($a === 'login') {
    $c = ['driver' => ($in['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql',
          'host' => $in['host'] ?? '127.0.0.1', 'port' => (int)($in['port'] ?? 3306),
          'user' => $in['user'] ?? '', 'pass' => (string)($in['pass'] ?? ''), 'path' => $in['path'] ?? ''];
    if ($c['driver'] === 'sqlite' && $c['path'] === '') fail('SQLite file path required');
    try { connect($c); } catch (Throwable $e) { fail($e->getMessage(), 401); }
    session_regenerate_id(true);
    $sealed = seal_secret($c['pass']);
    unset($c['pass']);
    $c['sealed_pass'] = $sealed;
    $_SESSION['c'] = $c;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    out(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}
if (!isset($_SESSION['c']['sealed_pass'])) fail('Not authenticated', 401);
if ($a === 'logout') { clear_auth(); out(['ok' => true]); }
if ($a === 'csrf') out(['csrf' => $_SESSION['csrf']]);
if (($_SERVER['HTTP_X_CSRF'] ?? '') !== $_SESSION['csrf']) fail('Bad CSRF token', 403);

$c = $_SESSION['c'];
$plainPass = open_secret($c['sealed_pass']);
if ($plainPass === null) { clear_auth(); fail('Session key missing or expired; please sign in again', 401); }
$c['pass'] = $plainPass;
$drv = $c['driver'];
$db = $in['db'] ?? ($_GET['db'] ?? null);
$tb = $in['table'] ?? ($_GET['table'] ?? null);

try {
    $p = connect($c, $drv === 'mysql' ? $db : null);
    $T = $tb !== null ? ($drv === 'mysql' ? q((string)$db, $drv) . '.' : '') . q((string)$tb, $drv) : '';

    switch ($a) {
    case 'server_info':
        $info = [
            'driver' => $drv,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
            'extensions' => array_values(array_intersect(['PDO', 'pdo_mysql', 'pdo_sqlite', 'mysqli', 'sqlite3', 'sodium', 'json', 'mbstring', 'openssl', 'zlib'], get_loaded_extensions())),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ];
        if ($drv === 'mysql') {
            $ver = (string)$p->query('SELECT VERSION()')->fetchColumn();
            $cu = (string)$p->query('SELECT CURRENT_USER()')->fetchColumn();
            $u = (string)$p->query('SELECT USER()')->fetchColumn();
            $vars = [];
            foreach (rows($p, "SHOW VARIABLES WHERE Variable_name IN ('character_set_server','collation_server','max_connections','port','socket','datadir','version_comment','innodb_buffer_pool_size')") as $r) {
                $vars[$r['Variable_name']] = $r['Value'];
            }
            $stat = [];
            foreach (rows($p, "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions','Slow_queries','Bytes_received','Bytes_sent')") as $r) {
                $stat[$r['Variable_name']] = $r['Value'];
            }
            $at = strrpos($cu, '@');
            $myUser = $at !== false ? substr($cu, 0, $at) : $c['user'];
            $myHost = $at !== false ? substr($cu, $at + 1) : 'localhost';
            $info += [
                'server_name' => (stripos($ver, 'mariadb') !== false ? 'MariaDB' : 'MySQL'),
                'server_version' => $ver,
                'version_comment' => $vars['version_comment'] ?? '',
                'host' => $c['host'] . ':' . $c['port'],
                'user' => $u,
                'current_user' => $cu,
                'my_user' => $myUser,
                'my_host' => $myHost,
                'charset' => ($vars['character_set_server'] ?? 'utf8mb4') . ' (' . ($vars['collation_server'] ?? '') . ')',
                'default_collation' => $vars['collation_server'] ?? 'utf8mb4_unicode_ci',
                'datadir' => $vars['datadir'] ?? '',
                'socket' => $vars['socket'] ?? '',
                'max_connections' => (int)($vars['max_connections'] ?? 0),
                'uptime' => (int)($stat['Uptime'] ?? 0),
                'threads' => (int)($stat['Threads_connected'] ?? 0),
                'questions' => (int)($stat['Questions'] ?? 0),
                'slow_queries' => (int)($stat['Slow_queries'] ?? 0),
            ];
        } else {
            $ver = (string)$p->query('SELECT sqlite_version()')->fetchColumn();
            $info += [
                'server_name' => 'SQLite',
                'server_version' => $ver,
                'host' => $c['path'],
                'user' => get_current_user() . '@local',
                'current_user' => 'file owner',
                'charset' => (string)$p->query('PRAGMA encoding')->fetchColumn(),
                'file_size' => is_file($c['path']) ? filesize($c['path']) : 0,
                'journal_mode' => (string)$p->query('PRAGMA journal_mode')->fetchColumn(),
                'foreign_keys_on' => (bool)$p->query('PRAGMA foreign_keys')->fetchColumn(),
            ];
        }
        out($info);

    case 'change_own_password':
        if ($drv !== 'mysql') fail('MySQL/MariaDB only');
        if (($in['pass'] ?? '') === '') fail('Password required');
        $cu = (string)$p->query('SELECT CURRENT_USER()')->fetchColumn();
        $at = strrpos($cu, '@');
        $u = $at !== false ? substr($cu, 0, $at) : $c['user'];
        $h = $at !== false ? substr($cu, $at + 1) : 'localhost';
        $p->exec('ALTER USER ' . acct_sql($p, $u, $h) . ' IDENTIFIED BY ' . $p->quote($in['pass']));
        $_SESSION['c']['sealed_pass'] = seal_secret((string)$in['pass']);
        out(['ok' => true]);

    case 'databases':
        if ($drv === 'sqlite') out(['databases' => ['main']]);
        $r = rows($p, "SELECT s.SCHEMA_NAME name, s.DEFAULT_CHARACTER_SET_NAME charset, s.DEFAULT_COLLATION_NAME collation, COUNT(t.TABLE_NAME) tables, COALESCE(SUM(t.DATA_LENGTH+t.INDEX_LENGTH),0) size
                       FROM information_schema.SCHEMATA s LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=s.SCHEMA_NAME GROUP BY s.SCHEMA_NAME, s.DEFAULT_CHARACTER_SET_NAME, s.DEFAULT_COLLATION_NAME ORDER BY 1");
        out(['databases' => $r]);

    case 'server_meta':
        if ($drv !== 'mysql') out(['engines' => [], 'collations' => [], 'default_engine' => '', 'default_collation' => '']);
        $engs = []; $defEng = 'InnoDB';
        foreach (rows($p, 'SHOW ENGINES') as $e) {
            if (in_array(strtoupper($e['Support'] ?? ''), ['YES', 'DEFAULT'], true) && !in_array(strtoupper($e['Engine']), ['PERFORMANCE_SCHEMA'], true)) {
                $engs[] = $e['Engine'];
                if (strtoupper($e['Support']) === 'DEFAULT') $defEng = $e['Engine'];
            }
        }
        $colls = [];
        foreach (rows($p, 'SHOW COLLATION') as $cl) {
            $cs = $cl['Charset'];
            if (in_array($cs, ['utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'ascii', 'binary'], true)) {
                $colls[$cs][] = $cl['Collation'];
            }
        }
        foreach ($colls as &$list) sort($list);
        $defColl = (string)$p->query("SELECT @@collation_server")->fetchColumn() ?: 'utf8mb4_unicode_ci';
        out(['engines' => $engs, 'collations' => $colls, 'default_engine' => $defEng, 'default_collation' => $defColl]);

    case 'create_db':
        $coll = trim((string)($in['collation'] ?? 'utf8mb4_unicode_ci'));
        if ($coll !== '' && !preg_match('/^[a-z0-9]+_[a-z0-9_]+$/i', $coll)) fail('Invalid collation');
        $cs = $coll !== '' ? explode('_', $coll)[0] : 'utf8mb4';
        $p->exec('CREATE DATABASE ' . q($in['name'], $drv) . " CHARACTER SET $cs" . ($coll !== '' ? " COLLATE $coll" : ''));
        out(['ok' => true]);

    case 'alter_db':
        if ($drv !== 'mysql') fail('MySQL/MariaDB only');
        $coll = trim((string)($in['collation'] ?? ''));
        if (!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/i', $coll)) fail('Invalid collation');
        $cs = explode('_', $coll)[0];
        $mode = $in['mode'] ?? (!empty($in['convert_all']) ? 'convert' : 'db_only'); // db_only | table_defaults | convert | binary
        $stmts = ['ALTER DATABASE ' . q((string)$db, $drv) . " CHARACTER SET $cs COLLATE $coll"];
        $tblCount = 0; $fkDrops = []; $fkAdds = [];
        if ($mode !== 'db_only') {
            $tbls = rows($p, "SELECT TABLE_NAME name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1", [$db]);
            $tblCount = count($tbls);
            if (in_array($mode, ['convert', 'binary'], true) && $tblCount > 0) {
                [$fkDrops, $fkAdds] = fk_text_conversion_guards($p, (string)$db, null);
                array_push($stmts, ...$fkDrops);
            }
            foreach ($tbls as $t) {
                $QT = q((string)$db, $drv) . '.' . q($t['name'], $drv);
                if ($mode === 'table_defaults') {
                    $stmts[] = "ALTER TABLE $QT DEFAULT CHARACTER SET $cs COLLATE $coll";
                } elseif ($mode === 'binary') {
                    array_push($stmts, ...binary_relabel_table_sql($p, (string)$db, $t['name'], $cs, $coll));
                } else {
                    $stmts[] = "ALTER TABLE $QT CONVERT TO CHARACTER SET $cs COLLATE $coll";
                }
            }
            if ($fkAdds) array_push($stmts, ...$fkAdds);
        }
        $warnings = [];
        if (empty($in['dry'])) {
            if ($tblCount > 0) $p->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($stmts as $s) {
                    try { $p->exec($s); }
                    catch (Throwable $e) {
                        if (str_starts_with($s, 'ALTER DATABASE')) throw $e;
                        $warnings[] = $e->getMessage() . " [$s]";
                    }
                }
            } finally {
                if ($tblCount > 0) $p->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        out(['ok' => true, 'statements' => $stmts, 'converted_tables' => $tblCount, 'fk_guards' => count($fkDrops), 'warnings' => $warnings]);

    case 'drop_db':
        $p->exec('DROP DATABASE ' . q($db, $drv)); out(['ok' => true]);

    case 'tables':
        if ($drv === 'sqlite') {
            $r = rows($p, "SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($r as &$t) { $t['rows'] = $t['type'] === 'table' ? (int)$p->query('SELECT COUNT(*) FROM ' . q($t['name'], $drv))->fetchColumn() : null; $t['size'] = null; $t['engine'] = null; $t['collation'] = null; $t['comment'] = ''; }
        } else {
            $r = rows($p, "SELECT TABLE_NAME name, TABLE_TYPE type, ENGINE engine, TABLE_COLLATION collation, TABLE_COMMENT comment, TABLE_ROWS `rows`, DATA_LENGTH+INDEX_LENGTH size
                           FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY 1", [$db]);
        }
        out(['tables' => $r]);

    case 'structure':
        if ($drv === 'sqlite') {
            $cols = array_map(fn($x) => ['name' => $x['name'], 'type' => $x['type'], 'null' => !$x['notnull'], 'key' => $x['pk'] ? 'PRI' : '', 'default' => $x['dflt_value'], 'extra' => '', 'parsed' => parse_coltype($x['type']), 'def' => parse_default($x['dflt_value'], !$x['notnull'], '', false, 'sqlite'), 'ai' => false, 'on_update' => false, 'collation' => null, 'comment' => ''],
                rows($p, 'PRAGMA table_info(' . q($tb, $drv) . ')'));
            $idx = rows($p, 'PRAGMA index_list(' . q($tb, $drv) . ')');
            $create = $p->query("SELECT sql FROM sqlite_master WHERE name=" . $p->quote($tb))->fetchColumn();
        } else {
            $maria = stripos((string)$p->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
            $cols = array_map(function ($x) use ($maria, $drv) {
                $pt = parse_coltype($x['Type']);
                return ['name' => $x['Field'], 'type' => $x['Type'], 'null' => $x['Null'] === 'YES', 'key' => $x['Key'], 'default' => $x['Default'], 'extra' => $x['Extra'],
                    'parsed' => $pt, 'def' => parse_default($x['Default'], $x['Null'] === 'YES', $x['Extra'], $maria, $drv),
                    'ai' => stripos($x['Extra'], 'auto_increment') !== false, 'on_update' => stripos($x['Extra'], 'on update') !== false, 'collation' => $x['Collation'] ?? null, 'comment' => $x['Comment'] ?? ''];
            }, rows($p, "SHOW FULL COLUMNS FROM $T"));
            $idx = rows($p, "SHOW INDEX FROM $T");
            $create = rows($p, "SHOW CREATE TABLE $T")[0]['Create Table'] ?? '';
        }
        out(['columns' => $cols, 'indexes' => $idx, 'create' => $create]);

    case 'browse':
        $page = max(1, (int)($in['page'] ?? 1)); $per = min(500, max(5, (int)($in['per'] ?? 50)));
        $rawCols = rows($p, $drv === 'sqlite' ? 'PRAGMA table_info(' . q($tb, $drv) . ')' : "SHOW COLUMNS FROM $T");
        $cols = array_column($rawCols, $drv === 'sqlite' ? 'name' : 'Field');
        $colMeta = [];
        foreach ($rawCols as $rc) {
            $cn = $drv === 'sqlite' ? $rc['name'] : $rc['Field'];
            $ct = $drv === 'sqlite' ? $rc['type'] : $rc['Type'];
            $colMeta[$cn] = parse_coltype($ct) + ['raw_type' => $ct, 'null' => $drv === 'sqlite' ? !$rc['notnull'] : ($rc['Null'] === 'YES')];
        }
        $where = ''; $args = [];
        if (!empty($in['search'])) {
            $parts = [];
            foreach ($cols as $col) { $parts[] = ($drv === 'mysql' ? 'CAST(' . q($col, $drv) . ' AS CHAR)' : 'CAST(' . q($col, $drv) . ' AS TEXT)') . ' LIKE ?'; $args[] = '%' . $in['search'] . '%'; }
            $where = ' WHERE ' . implode(' OR ', $parts);
        }
        $order = '';
        if (!empty($in['sort']) && in_array($in['sort'], $cols, true)) $order = ' ORDER BY ' . q($in['sort'], $drv) . (($in['dir'] ?? '') === 'desc' ? ' DESC' : ' ASC');
        $total = (int)rows($p, "SELECT COUNT(*) n FROM $T$where", $args)[0]['n'];
        $data = rows($p, "SELECT * FROM $T$where$order LIMIT $per OFFSET " . (($page - 1) * $per), $args);
        $pk = [];
        if ($drv === 'sqlite') { foreach ($rawCols as $x) if ($x['pk']) $pk[] = $x['name']; }
        else { foreach ($rawCols as $x) if ($x['Key'] === 'PRI') $pk[] = $x['Field']; }
        out(['rows' => $data, 'columns' => $cols, 'col_meta' => $colMeta, 'total' => $total, 'pk' => $pk, 'page' => $page, 'per' => $per]);

    case 'insert':
        $v = $in['values'] ?? [];
        if (!$v) { $p->exec("INSERT INTO $T DEFAULT VALUES"); out(['ok' => true]); }
        $names = implode(',', array_map(fn($k) => q($k, $drv), array_keys($v)));
        $ph = implode(',', array_fill(0, count($v), '?'));
        $p->prepare("INSERT INTO $T ($names) VALUES ($ph)")->execute(array_values($v)); out(['ok' => true]);

    case 'update':
    case 'delete':
        $key = $in['key'] ?? [];
        if (!$key) fail('Table has no primary key; use the SQL editor');
        $w = []; $args = [];
        foreach ($key as $k => $val) { if ($val === null) $w[] = q($k, $drv) . ' IS NULL'; else { $w[] = q($k, $drv) . ' = ?'; $args[] = $val; } }
        $w = implode(' AND ', $w);
        if ($a === 'delete') { $s = $p->prepare("DELETE FROM $T WHERE $w LIMIT 1"); if ($drv === 'sqlite') $s = $p->prepare("DELETE FROM $T WHERE $w"); $s->execute($args); out(['ok' => true]); }
        $v = $in['values'] ?? [];
        $set = implode(',', array_map(fn($k) => q($k, $drv) . ' = ?', array_keys($v)));
        $s = $p->prepare("UPDATE $T SET $set WHERE $w"); $s->execute(array_merge(array_values($v), $args)); out(['ok' => true]);

    case 'query':
        $results = [];
        // naive split on ; outside quotes
        $stmts = []; $buf = ''; $qt = null; $sql = (string)$in['sql'];
        for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
            $ch = $sql[$i];
            if ($qt) { if ($ch === '\\' && $qt !== '`') { $buf .= $ch . ($sql[++$i] ?? ''); continue; } if ($ch === $qt) $qt = null; }
            elseif ($ch === "'" || $ch === '"' || $ch === '`') $qt = $ch;
            elseif ($ch === ';') { if (trim($buf) !== '') $stmts[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $stmts[] = $buf;
        foreach ($stmts as $st) {
            try { $r = run_sql($p, $st); $r['sql'] = trim($st); $results[] = $r; }
            catch (Throwable $e) { $results[] = ['sql' => trim($st), 'error' => $e->getMessage()]; break; }
        }
        out(['results' => $results]);

    case 'drop_table': $p->exec("DROP TABLE $T"); out(['ok' => true]);
    case 'truncate': $p->exec($drv === 'mysql' ? "TRUNCATE TABLE $T" : "DELETE FROM $T"); out(['ok' => true]);
    case 'rename_table':
        $p->exec($drv === 'mysql' ? "RENAME TABLE $T TO " . q((string)$db, $drv) . '.' . q($in['to'], $drv) : "ALTER TABLE $T RENAME TO " . q($in['to'], $drv)); out(['ok' => true]);

    case 'create_table':
        // columns: [{name,type,length,null,pk,ai,default,default_mode,on_update}]
        $defs = []; $pks = [];
        $exprs = ['CURRENT_TIMESTAMP', 'NOW()', 'CURDATE()', 'CURRENT_DATE', 'CURTIME()', 'CURRENT_TIME', 'UUID()'];
        foreach ($in['columns'] ?? [] as $col) {
            $type = strtoupper($col['type'] ?? 'INT');
            if (!in_array($type, COL_TYPES, true)) fail("Unsupported type $type");
            $len = trim((string)($col['length'] ?? ''));
            if ($len !== '' && !preg_match('/^\d+(,\d+)?$/', $len)) fail('Invalid length');
            $d = q($col['name'], $drv) . ' ' . $type . ($len !== '' ? "($len)" : '');
            if (empty($col['null'])) $d .= ' NOT NULL'; else $d .= ' NULL';
            if (!empty($col['ai'])) $d .= $drv === 'mysql' ? ' AUTO_INCREMENT' : '';
            $defVal = trim((string)($col['default'] ?? ''));
            $defMode = $col['default_mode'] ?? '';
            if ($defMode === 'null' || strtoupper($defVal) === 'NULL') {
                $d .= ' DEFAULT NULL';
            } elseif ($defMode === 'expr' || in_array(strtoupper($defVal), $exprs, true)) {
                $e = strtoupper($defVal ?: 'CURRENT_TIMESTAMP');
                if (!preg_match('/^[A-Za-z_]+(\([\w\s,.\']*\))?$/', $e)) fail('Invalid default expression');
                $d .= " DEFAULT $e";
            } elseif ($defVal !== '') {
                $d .= ' DEFAULT ' . $p->quote($defVal);
            }
            if ($drv === 'mysql' && !empty($col['on_update']) && in_array($type, ['DATETIME', 'TIMESTAMP'], true)) {
                $d .= ' ON UPDATE CURRENT_TIMESTAMP';
            }
            if (!empty($col['pk'])) { $pks[] = q($col['name'], $drv); }
            $defs[] = $d;
        }
        if (!$defs) fail('At least one column required');
        if ($drv === 'sqlite' && count($pks) === 1) {
            foreach ($defs as $i => $d) if (str_starts_with($d, $pks[0] . ' ')) $defs[$i] .= ' PRIMARY KEY' . (!empty($in['columns'][$i]['ai']) ? ' AUTOINCREMENT' : '');
        } elseif ($pks) $defs[] = 'PRIMARY KEY (' . implode(',', $pks) . ')';
        $tail = '';
        if ($drv === 'mysql') {
            $eng = trim((string)($in['engine'] ?? ''));
            if ($eng !== '') {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $eng)) fail('Invalid storage engine');
                $tail .= " ENGINE=$eng";
            }
            $coll = trim((string)($in['collation'] ?? ''));
            if ($coll !== '') {
                if (!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/i', $coll)) fail('Invalid collation');
                $cs = explode('_', $coll)[0];
                $tail .= " DEFAULT CHARSET=$cs COLLATE=$coll";
            }
            $cmt = trim((string)($in['comment'] ?? ''));
            if ($cmt !== '') $tail .= ' COMMENT=' . $p->quote($cmt);
        }
        $p->exec("CREATE TABLE $T (" . implode(', ', $defs) . ")$tail"); out(['ok' => true]);

    case 'alter_table_options':
        if ($drv !== 'mysql') fail('MySQL/MariaDB only');
        $parts = []; $stmts = []; $fkDrops = []; $fkAdds = [];
        $eng = trim((string)($in['engine'] ?? ''));
        if ($eng !== '') {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $eng)) fail('Invalid storage engine');
            $parts[] = "ENGINE=$eng";
        }
        $coll = trim((string)($in['collation'] ?? ''));
        $cmode = $in['conv_mode'] ?? (!empty($in['convert_columns']) ? 'convert' : 'default_only'); // convert | default_only | binary
        if ($coll !== '') {
            if (!preg_match('/^[a-z0-9]+_[a-z0-9_]+$/i', $coll)) fail('Invalid collation');
            $cs = explode('_', $coll)[0];
            if (in_array($cmode, ['convert', 'binary'], true)) {
                [$fkDrops, $fkAdds] = fk_text_conversion_guards($p, (string)$db, (string)$tb);
                array_push($stmts, ...$fkDrops);
            }
            if ($cmode === 'convert') $parts[] = "CONVERT TO CHARACTER SET $cs COLLATE $coll";
            elseif ($cmode === 'default_only') $parts[] = "DEFAULT CHARACTER SET $cs COLLATE $coll";
        }
        if (array_key_exists('comment', $in)) {
            $parts[] = 'COMMENT=' . $p->quote((string)$in['comment']);
        }
        if ($parts) $stmts[] = "ALTER TABLE $T " . implode(', ', $parts);
        if ($coll !== '' && $cmode === 'binary') {
            array_push($stmts, ...binary_relabel_table_sql($p, (string)$db, (string)$tb, $cs, $coll));
        }
        if ($fkAdds) array_push($stmts, ...$fkAdds);
        if (!$stmts) fail('No table options changed');
        if (empty($in['dry'])) {
            if ($fkDrops) $p->exec('SET FOREIGN_KEY_CHECKS=0');
            try { foreach ($stmts as $s) $p->exec($s); }
            finally { if ($fkDrops) $p->exec('SET FOREIGN_KEY_CHECKS=1'); }
        }
        out(['ok' => true, 'sql' => implode(";\n", $stmts), 'statements' => $stmts]);

    case 'drop_column': $p->exec("ALTER TABLE $T DROP COLUMN " . q($in['column'], $drv)); out(['ok' => true]);
    case 'add_column':
    case 'alter_column':
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') fail('Column name required');
        $def = col_def($p, $drv, $in);
        if ($a === 'add_column') {
            $sql = "ALTER TABLE $T ADD COLUMN " . q($name, $drv) . " $def" . col_pos($drv, $in);
        } elseif ($drv === 'mysql') {
            $sql = "ALTER TABLE $T CHANGE COLUMN " . q((string)$in['column'], $drv) . ' ' . q($name, $drv) . " $def" . col_pos($drv, $in);
        } else {
            if ($name === $in['column']) fail('SQLite can only rename columns; other changes need a table rebuild (use the SQL editor).');
            $sql = "ALTER TABLE $T RENAME COLUMN " . q((string)$in['column'], $drv) . ' TO ' . q($name, $drv);
        }
        if (empty($in['dry'])) $p->exec($sql);
        out(['ok' => true, 'sql' => $sql]);

    case 'indexes': out(['indexes' => list_indexes($p, $drv, $T, $tb)]);
    case 'foreign_keys': out(['foreign_keys' => list_fks($p, $drv, $db, $tb), 'supported' => $drv === 'mysql']);

    case 'add_index':
        $type = strtoupper((string)($in['type'] ?? 'INDEX'));
        if (!in_array($type, ['INDEX', 'UNIQUE', 'PRIMARY', 'FULLTEXT'], true)) fail('Invalid index type');
        $cols = col_list($drv, $in['columns'] ?? [], true);
        $iname = trim((string)($in['name'] ?? ''));
        if ($drv === 'mysql') {
            $sql = $type === 'PRIMARY' ? "ALTER TABLE $T ADD PRIMARY KEY $cols"
                : "ALTER TABLE $T ADD " . ($type === 'UNIQUE' ? 'UNIQUE ' : ($type === 'FULLTEXT' ? 'FULLTEXT ' : '')) . 'INDEX ' . ($iname !== '' ? q($iname, $drv) . ' ' : '') . $cols;
        } else {
            if (in_array($type, ['PRIMARY', 'FULLTEXT'], true)) fail("SQLite cannot add a $type key to an existing table");
            if ($iname === '') $iname = 'idx_' . $tb . '_' . implode('_', array_map(fn($c) => is_array($c) ? $c['name'] : $c, $in['columns']));
            $sql = 'CREATE ' . ($type === 'UNIQUE' ? 'UNIQUE ' : '') . 'INDEX ' . q($iname, $drv) . " ON " . q((string)$tb, $drv) . " $cols";
        }
        if (empty($in['dry'])) $p->exec($sql);
        out(['ok' => true, 'sql' => $sql]);

    case 'drop_index':
        $iname = (string)($in['name'] ?? '');
        $sql = $drv === 'mysql' ? ($iname === 'PRIMARY' ? "ALTER TABLE $T DROP PRIMARY KEY" : "ALTER TABLE $T DROP INDEX " . q($iname, $drv)) : 'DROP INDEX ' . q($iname, $drv);
        if (empty($in['dry'])) $p->exec($sql);
        out(['ok' => true, 'sql' => $sql]);

    case 'add_fk':
        if ($drv !== 'mysql') fail('SQLite cannot add foreign keys to an existing table (use the SQL editor to rebuild it)');
        $pairs = array_values(array_filter($in['pairs'] ?? [], fn($x) => ($x['col'] ?? '') !== '' && ($x['ref'] ?? '') !== ''));
        if (!$pairs) fail('Pick at least one column pair');
        $od = strtoupper((string)($in['on_delete'] ?? 'RESTRICT')); $ou = strtoupper((string)($in['on_update'] ?? 'RESTRICT'));
        if (!in_array($od, FK_RULES, true) || !in_array($ou, FK_RULES, true)) fail('Invalid referential action');
        $rt = (string)($in['ref_table'] ?? ''); if ($rt === '') fail('Referenced table required');
        $rdb = (string)($in['ref_db'] ?? $db);
        $fname = trim((string)($in['name'] ?? ''));
        $sql = "ALTER TABLE $T ADD " . ($fname !== '' ? 'CONSTRAINT ' . q($fname, $drv) . ' ' : '') . 'FOREIGN KEY ' . col_list($drv, array_column($pairs, 'col'))
            . ' REFERENCES ' . q($rdb, $drv) . '.' . q($rt, $drv) . ' ' . col_list($drv, array_column($pairs, 'ref')) . " ON DELETE $od ON UPDATE $ou";
        if (empty($in['dry'])) $p->exec($sql);
        out(['ok' => true, 'sql' => $sql]);

    case 'drop_fk':
        if ($drv !== 'mysql') fail('SQLite cannot drop foreign keys from an existing table');
        $sql = "ALTER TABLE $T DROP FOREIGN KEY " . q((string)($in['name'] ?? ''), $drv);
        if (empty($in['dry'])) $p->exec($sql);
        out(['ok' => true, 'sql' => $sql]);

    case 'export_csv':
        $cols = table_cols($p, $drv, $T, $tb);
        $fargs = [];
        [$where, $order] = filter_sql($drv, $cols, $in, $fargs);
        $delim = in_array($in['delimiter'] ?? ',', [',', ';', "\t", '|'], true) ? ($in['delimiter'] ?? ',') : ',';
        $nullStr = (string)($in['null_as'] ?? '');
        if ($drv === 'mysql') $p->setAttribute(defined('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') ? constant('Pdo\Mysql::ATTR_USE_BUFFERED_QUERY') : 1000, false);
        $st = $p->prepare("SELECT * FROM $T$where$order"); $st->execute($fargs);
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', (string)$tb) . '.csv"');
        $fh = fopen('php://output', 'w');
        if (!empty($in['bom'])) fwrite($fh, "\xEF\xBB\xBF");
        if (!empty($in['header'])) fputcsv($fh, $cols, $delim, '"', '');
        while ($r = $st->fetch(PDO::FETCH_NUM)) fputcsv($fh, array_map(fn($v) => $v === null ? $nullStr : $v, $r), $delim, '"', '');
        exit;

    case 'import_rows':
        $icols = $in['columns'] ?? []; $irows = $in['rows'] ?? [];
        if (!$icols || !is_array($icols)) fail('No columns mapped');
        $mode = $in['mode'] ?? 'insert';
        $verb = ['insert' => 'INSERT INTO', 'ignore' => $drv === 'mysql' ? 'INSERT IGNORE INTO' : 'INSERT OR IGNORE INTO', 'replace' => $drv === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO'][$mode] ?? fail('Invalid mode');
        $emptyNull = !empty($in['empty_null']); $n = count($icols); $done = 0;
        $names = implode(',', array_map(fn($c) => q((string)$c, $drv), $icols));
        $per = max(1, intdiv(30000, $n));
        $p->beginTransaction();
        try {
            foreach (array_chunk($irows, $per) as $chunk) {
                $args = [];
                foreach ($chunk as $i => $r) {
                    if (!is_array($r) || count($r) !== $n) throw new InvalidArgumentException('Row ' . ($i + 1) . ' has the wrong number of values');
                    foreach ($r as $v) $args[] = ($emptyNull && $v === '') ? null : $v;
                }
                $one = '(' . implode(',', array_fill(0, $n, '?')) . ')';
                $stm = $p->prepare("$verb $T ($names) VALUES " . implode(',', array_fill(0, count($chunk), $one)));
                $stm->execute($args); $done += $stm->rowCount();
            }
            $p->commit();
        } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
        out(['ok' => true, 'imported' => $done, 'received' => count($irows)]);

    case 'export':
        $tables = $in['tables'] ?? [];
        $sql = "-- Fandejo SQL Export\n-- " . date('c') . "\n\n";
        foreach ($tables as $t) {
            $TT = ($drv === 'mysql' ? q((string)$db, $drv) . '.' : '') . q($t, $drv);
            if ($drv === 'mysql') $create = rows($p, "SHOW CREATE TABLE $TT")[0]['Create Table'] ?? '';
            else $create = $p->query('SELECT sql FROM sqlite_master WHERE name=' . $p->quote($t))->fetchColumn();
            if (!empty($in['structure'])) $sql .= "DROP TABLE IF EXISTS $TT;\n$create;\n\n";
            if (!empty($in['data'])) {
                $s = $p->query("SELECT * FROM $TT");
                while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : $p->quote((string)$v), array_values($r));
                    $sql .= "INSERT INTO $TT (" . implode(',', array_map(fn($k) => q($k, $drv), array_keys($r))) . ') VALUES (' . implode(',', $vals) . ");\n";
                }
                $sql .= "\n";
            }
        }
        out(['sql' => $sql]);

    case 'users':
        if ($drv !== 'mysql') fail('User management requires MySQL/MariaDB');
        $r = rows($p, 'SELECT User user, Host host FROM mysql.user ORDER BY 1,2');
        foreach ($r as &$u) {
            try { $u['scopes'] = parse_grants($p, $u['user'], $u['host']); } catch (Throwable $e) { $u['scopes'] = []; }
        }
        out(['users' => $r, 'me' => $c['user']]);

    case 'user_grants':
        if ($drv !== 'mysql') fail('MySQL only');
        out(['scopes' => parse_grants($p, $in['user'], $in['host']), 'catalog' => ['global' => PRIV_GLOBAL, 'db' => PRIV_DB, 'table' => PRIV_TABLE]]);

    case 'set_grants':
        if ($drv !== 'mysql') fail('MySQL only');
        $sdb = (string)($in['scope_db'] ?? '*'); $stb = (string)($in['scope_table'] ?? '*');
        if ($sdb === '*') $stb = '*';
        $cat = priv_catalog($sdb, $stb);
        $want = array_values(array_unique(array_map('strtoupper', $in['privileges'] ?? [])));
        if (array_diff($want, $cat)) fail('Unknown privilege: ' . implode(', ', array_diff($want, $cat)));
        $wantOpt = !empty($in['grant_option']);
        $cur = ['privs' => [], 'option' => false];
        foreach (parse_grants($p, $in['user'], $in['host']) as $e) if ($e['db'] === $sdb && $e['table'] === $stb) $cur = $e;
        $add = array_values(array_diff($want, $cur['privs'])); $del = array_values(array_intersect($cur['privs'], array_diff($cat, $want)));
        $T2 = scope_sql($sdb, $stb); $acct = acct_sql($p, $in['user'], $in['host']); $st = [];
        if ($del) $st[] = 'REVOKE ' . implode(', ', $del) . " ON $T2 FROM $acct";
        if ($add) $st[] = 'GRANT ' . (count($add) === count($cat) ? 'ALL PRIVILEGES' : implode(', ', $add)) . " ON $T2 TO $acct";
        if ($wantOpt && !$cur['option']) $st[] = "GRANT USAGE ON $T2 TO $acct WITH GRANT OPTION";
        if (!$wantOpt && $cur['option']) $st[] = "REVOKE GRANT OPTION ON $T2 FROM $acct";
        if (empty($in['dry'])) foreach ($st as $x) $p->exec($x);
        out(['ok' => true, 'statements' => $st]);

    case 'set_password':
        if ($drv !== 'mysql') fail('MySQL only');
        if (($in['pass'] ?? '') === '') fail('Password required');
        $p->exec('ALTER USER ' . acct_sql($p, $in['user'], $in['host']) . ' IDENTIFIED BY ' . $p->quote($in['pass'])); out(['ok' => true]);

    case 'create_user':
        if ($drv !== 'mysql') fail('MySQL only');
        $p->exec('CREATE USER ' . $p->quote($in['user']) . '@' . $p->quote($in['host'] ?? '%') . ' IDENTIFIED BY ' . $p->quote($in['pass']));
        if (!empty($in['grant_db'])) $p->exec('GRANT ALL PRIVILEGES ON ' . q($in['grant_db'], 'mysql') . '.* TO ' . $p->quote($in['user']) . '@' . $p->quote($in['host'] ?? '%'));
        out(['ok' => true]);
    case 'drop_user':
        if ($drv !== 'mysql') fail('MySQL only');
        $p->exec('DROP USER ' . $p->quote($in['user']) . '@' . $p->quote($in['host'])); out(['ok' => true]);

    default: fail('Unknown action', 404);
    }
} catch (Throwable $e) {
    fail($e->getMessage(), 400);
}
