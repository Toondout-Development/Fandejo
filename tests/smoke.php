<?php
// In-process smoke test (no web server needed): php tests/smoke.php
if (($argv[1] ?? '') === '--call') {           // child: one API call
    [, , $action, $json] = $argv;
    $_GET['a'] = $action;
    $_SERVER['HTTP_X_CSRF'] = getenv('CSRF') ?: '';
    $_SERVER['HTTP_USER_AGENT'] = 'FandejoSmokeTest/1.0';
    $_SERVER['REMOTE_ADDR'] = getenv('TEST_IP') ?: '127.0.0.1';
    if (getenv('TKEY')) $_COOKIE['fandejo_key'] = getenv('TKEY');
    session_save_path(sys_get_temp_dir());
    if (getenv('SID')) session_id(getenv('SID'));
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', get_class(new class { public $c; private $p = 0; public $context;
        function stream_open($path) { $this->c = $GLOBALS['json']; return true; }
        function stream_read($n) { $r = substr($this->c, $this->p, $n); $this->p += strlen($r); return $r; }
        function stream_write($d) { echo $d; return strlen($d); }
        function stream_eof() { return $this->p >= strlen($this->c); }
        function stream_stat() { return []; } }));
    $GLOBALS['json'] = $json;
    register_shutdown_function(fn() => print("\nSID=" . session_id() . "\nTKEY=" . ($_COOKIE['fandejo_key'] ?? '')));
    require __DIR__ . '/../public/api.php';
    exit;
}

$tmpDir = sys_get_temp_dir() . '/fandejo_smoke_' . bin2hex(random_bytes(4));
@mkdir($tmpDir . '/data', 0750, true);
$cfgPath = $tmpDir . '/config.php';
$locksPath = $tmpDir . '/locks.json';
$dbName = 'fandejo_smoke.sqlite';

$sid = bin2hex(random_bytes(8)); $csrf = ''; $tkey = ''; $testIp = '127.0.0.1';
$failures = 0;

function call(string $a, array $b = [], bool $expectErr = false) {
    global $sid, $csrf, $tkey, $cfgPath, $locksPath, $testIp, $failures;
    $cmd = 'FANDEJO_CONFIG=' . escapeshellarg($cfgPath)
         . ' FANDEJO_LOCKS_FILE=' . escapeshellarg($locksPath)
         . ' TEST_IP=' . escapeshellarg($testIp)
         . ' SID=' . $sid . ' CSRF=' . $csrf . ' TKEY=' . $tkey
         . ' php ' . escapeshellarg(__FILE__) . ' --call ' . $a . ' ' . escapeshellarg(json_encode($b)) . ' 2>&1';
    $o = shell_exec($cmd);
    if (preg_match('/\nSID=(\w+)\nTKEY=(\w*)$/', $o, $m)) { $sid = $m[1]; if ($m[2]) $tkey = $m[2]; }
    $o = preg_replace('/\nSID=.*$/s', '', $o);
    echo str_pad($a, 16), substr(str_replace("\n", ' ', $o), 0, 220), "\n";
    $j = json_decode($o, true);
    $isErr = is_array($j) && isset($j['error']);
    if ($expectErr && !$isErr) {
        echo "  [FAIL] Expected error for $a, got success\n";
        $failures++;
    } elseif (!$expectErr && $isErr) {
        echo "  [FAIL] Unexpected error for $a: {$j['error']}\n";
        $failures++;
    }
    return $j ?? $o;
}

// 1. First-time SQLite setup login + database creation
$r = call('login', ['driver' => 'sqlite', 'user' => 'admin', 'pass' => 'smoke_secret_123', 'setup_sqlite' => true]);
$csrf = $r['csrf'] ?? '';
call('create_db', ['name' => $dbName]);

// 2. Test brute-force per-IP lockout from an attacker IP & admin unlock
$savedSid = $sid; $savedKey = $tkey; $savedCsrf = $csrf;
$sid = bin2hex(random_bytes(8)); $tkey = ''; $csrf = ''; $testIp = '203.0.113.99';
for ($i = 1; $i <= 5; $i++) {
    call('login', ['driver' => 'sqlite', 'user' => 'admin', 'pass' => 'wrong_pass'], true);
}
// Attacker IP is now hard-locked even with the right password
$lockedRes = call('login', ['driver' => 'sqlite', 'user' => 'admin', 'pass' => 'smoke_secret_123'], true);
if (!str_contains($lockedRes['error'] ?? '', 'locked')) { echo "  [FAIL] Expected hard lockout on attacker IP\n"; $failures++; }

// Admin on 127.0.0.1 is NOT locked out and can unlock the attacker IP
$sid = $savedSid; $tkey = $savedKey; $csrf = $savedCsrf; $testIp = '127.0.0.1';
call('unlock_account', ['user' => 'admin', 'driver' => 'sqlite', 'ip' => '203.0.113.99']);

// 3. Test directory sandbox boundary enforcement
call('set_sqlite_dirs', ['dirs' => ['/etc']], true);
call('set_sqlite_dirs', ['dirs' => [$tmpDir . '/data', $tmpDir . '/extra_data'], 'create_missing' => true]);

// 4. Table creation, CRUD, keyless table update/delete, structure, export/import
call('create_table', ['db' => $dbName, 'table' => 'u', 'columns' => [['name' => 'id', 'type' => 'INTEGER', 'pk' => true, 'ai' => true], ['name' => 'name', 'type' => 'VARCHAR', 'length' => '50']]]);
call('insert', ['db' => $dbName, 'table' => 'u', 'values' => ['name' => 'bob']]);
call('query', ['db' => $dbName, 'sql' => "INSERT INTO u(name) VALUES ('al;ice'); SELECT * FROM u;"]);
call('browse', ['db' => $dbName, 'table' => 'u', 'search' => 'bo', 'sort' => 'name']);
call('update', ['db' => $dbName, 'table' => 'u', 'key' => ['id' => 1], 'values' => ['name' => 'rob']]);
call('structure', ['db' => $dbName, 'table' => 'u']);
$exp = call('export', ['db' => $dbName, 'tables' => ['u'], 'structure' => true, 'data' => true]);
if (!str_contains($exp['sql'] ?? '', 'INSERT INTO "u"')) { echo "  [FAIL] Export SQL missing INSERT statement\n"; $failures++; }
call('tables', ['db' => $dbName]);
call('delete', ['db' => $dbName, 'table' => 'u', 'key' => ['id' => 1]]);
call('users');
call('add_column', ['db' => $dbName, 'table' => 'u', 'name' => 'age', 'type' => 'INT', 'null' => true, 'default_mode' => 'value', 'default' => '7']);
call('alter_column', ['db' => $dbName, 'table' => 'u', 'column' => 'age', 'name' => 'years', 'type' => 'INT']);
call('alter_column', ['db' => $dbName, 'table' => 'u', 'column' => 'years', 'name' => 'years', 'type' => 'BIGINT'], true);
call('alter_column', ['db' => $dbName, 'table' => 'u', 'column' => 'years', 'name' => 'years', 'type' => 'NOPE'], true);
call('structure', ['db' => $dbName, 'table' => 'u']);
call('add_index', ['db' => $dbName, 'table' => 'u', 'name' => 'idx_name', 'type' => 'UNIQUE', 'columns' => [['name' => 'name']]]);
call('add_index', ['db' => $dbName, 'table' => 'u', 'type' => 'PRIMARY', 'columns' => [['name' => 'name']]], true);
call('indexes', ['db' => $dbName, 'table' => 'u']);
call('foreign_keys', ['db' => $dbName, 'table' => 'u']);
call('add_fk', ['db' => $dbName, 'table' => 'u', 'ref_table' => 'x', 'pairs' => [['col' => 'a', 'ref' => 'b']]], true);
call('drop_index', ['db' => $dbName, 'table' => 'u', 'name' => 'idx_name']);
call('indexes', ['db' => $dbName, 'table' => 'u']);
call('import_rows', ['db' => $dbName, 'table' => 'u', 'columns' => ['name', 'years'], 'rows' => [['charlie', '30'], ['dave', ''], ['bob', '25']], 'mode' => 'insert', 'empty_null' => true]);
$bf = call('browse', ['db' => $dbName, 'table' => 'u', 'filters' => [['col' => 'years', 'op' => '>=', 'val' => '25'], ['col' => 'name', 'op' => 'IN', 'val' => 'charlie, bob']]]);
if (($bf['total'] ?? 0) !== 2) { echo "  [FAIL] Column filter expected 2 rows, got " . ($bf['total'] ?? 'null') . "\n"; $failures++; }
$bfNull = call('browse', ['db' => $dbName, 'table' => 'u', 'filters' => [['col' => 'years', 'op' => 'IS NULL']]]);
if (($bfNull['total'] ?? 0) !== 1) { echo "  [FAIL] IS NULL filter expected 1 row, got " . ($bfNull['total'] ?? 'null') . "\n"; $failures++; }
// Test bulk_update in both batch mode (keys + values) and individual mode (updates)
$bupd1 = call('bulk_update', ['db' => $dbName, 'table' => 'u', 'keys' => [['id' => 3], ['id' => 4]], 'values' => ['years' => 40]]);
if (($bupd1['affected'] ?? 0) !== 2) { echo "  [FAIL] bulk_update (batch) expected 2 rows updated, got " . ($bupd1['affected'] ?? 'null') . "\n"; $failures++; }
$bupd2 = call('bulk_update', ['db' => $dbName, 'table' => 'u', 'updates' => [['key' => ['id' => 3], 'values' => ['name' => 'chuck']], ['key' => ['id' => 4], 'values' => ['name' => 'david']]]]);
if (($bupd2['affected'] ?? 0) !== 2) { echo "  [FAIL] bulk_update (individual) expected 2 rows updated, got " . ($bupd2['affected'] ?? 'null') . "\n"; $failures++; }
$bdel = call('bulk_delete', ['db' => $dbName, 'table' => 'u', 'keys' => [['id' => 3], ['id' => 4]]]);
if (($bdel['affected'] ?? 0) !== 2) { echo "  [FAIL] bulk_delete expected 2 rows deleted, got " . ($bdel['affected'] ?? 'null') . "\n"; $failures++; }
$fkl = call('fk_lookup', ['db' => $dbName, 'ref_table' => 'u', 'ref_col' => 'id', 'q' => 'bob']);
if (count($fkl['items'] ?? []) !== 1) { echo "  [FAIL] fk_lookup expected 1 matching item for 'bob'\n"; $failures++; }
// Test Views & Triggers
call('save_view', ['db' => $dbName, 'name' => 'v_users', 'select' => 'SELECT id, name FROM u']);
$vst = call('structure', ['db' => $dbName, 'table' => 'v_users']);
if (empty($vst['is_view'])) { echo "  [FAIL] Expected v_users structure to report is_view=true\n"; $failures++; }
call('create_trigger', ['db' => $dbName, 'name' => 'trg_u_ins', 'table' => 'u', 'timing' => 'AFTER', 'event' => 'INSERT', 'body' => 'UPDATE u SET years = 10 WHERE id = NEW.id AND years IS NULL;']);
$trList = call('triggers', ['db' => $dbName, 'table' => 'u']);
if (count($trList['triggers'] ?? []) !== 1) { echo "  [FAIL] Expected 1 trigger on table u\n"; $failures++; }
call('drop_trigger', ['db' => $dbName, 'name' => 'trg_u_ins']);
call('export_csv', ['db' => $dbName, 'table' => 'u', 'delimiter' => ',', 'header' => true, 'null_as' => 'NULL']);
call('server_info');

// Clean up temporary directory
foreach ([$tmpDir . '/data', $tmpDir . '/extra_data', $tmpDir] as $d) {
    if (is_dir($d)) {
        foreach (glob("$d/*") ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
}

if ($failures > 0) {
    echo "\nFAILED ($failures error(s))\n";
    exit(1);
}
echo "\nALL SMOKE TESTS PASSED\n";
