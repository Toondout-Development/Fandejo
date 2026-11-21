<?php
// In-process smoke test (no web server needed): php tests/smoke.php
if (($argv[1] ?? '') === '--call') {           // child: one API call
    [, , $action, $json] = $argv;
    $_GET['a'] = $action;
    $_SERVER['HTTP_X_CSRF'] = getenv('CSRF') ?: '';
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
$db = sys_get_temp_dir() . '/fandejo_smoke.db'; @unlink($db);
$sid = bin2hex(random_bytes(8)); $csrf = ''; $tkey = '';
function call(string $a, array $b = []) {
    global $sid, $csrf, $tkey;
    $cmd = 'SID=' . $sid . ' CSRF=' . $csrf . ' TKEY=' . $tkey . ' php ' . escapeshellarg(__FILE__) . ' --call ' . $a . ' ' . escapeshellarg(json_encode($b)) . ' 2>&1';
    $o = shell_exec($cmd);
    if (preg_match('/\nSID=(\w+)\nTKEY=(\w*)$/', $o, $m)) { $sid = $m[1]; if ($m[2]) $tkey = $m[2]; }
    $o = preg_replace('/\nSID=.*$/s', '', $o);
    echo str_pad($a, 14), substr($o, 0, 260), "\n";
    return json_decode($o, true);
}
$r = call('login', ['driver' => 'sqlite', 'path' => $db]); $csrf = $r['csrf'] ?? '';
call('create_table', ['table' => 'u', 'columns' => [['name' => 'id', 'type' => 'INTEGER', 'pk' => true, 'ai' => true], ['name' => 'name', 'type' => 'VARCHAR', 'length' => '50']]]);
call('insert', ['table' => 'u', 'values' => ['name' => 'bob']]);
call('query', ['sql' => "INSERT INTO u(name) VALUES ('al;ice'); SELECT * FROM u;"]);
call('browse', ['table' => 'u', 'search' => 'bo', 'sort' => 'name']);
call('update', ['table' => 'u', 'key' => ['id' => 1], 'values' => ['name' => 'rob']]);
call('structure', ['table' => 'u']);
call('export', ['tables' => ['u'], 'structure' => true, 'data' => true]);
call('tables');
call('delete', ['table' => 'u', 'key' => ['id' => 1]]);
call('users');
call('add_column', ['table' => 'u', 'name' => 'age', 'type' => 'INT', 'null' => true, 'default_mode' => 'value', 'default' => '7']);
call('alter_column', ['table' => 'u', 'column' => 'age', 'name' => 'years', 'type' => 'INT']);
call('alter_column', ['table' => 'u', 'column' => 'years', 'name' => 'years', 'type' => 'BIGINT']);
call('alter_column', ['table' => 'u', 'column' => 'years', 'name' => 'years', 'type' => 'NOPE']);
call('structure', ['table' => 'u']);
call('add_index', ['table' => 'u', 'name' => 'idx_name', 'type' => 'UNIQUE', 'columns' => [['name' => 'name']]]);
call('add_index', ['table' => 'u', 'type' => 'PRIMARY', 'columns' => [['name' => 'name']]]);
call('indexes', ['table' => 'u']);
call('foreign_keys', ['table' => 'u']);
call('add_fk', ['table' => 'u', 'ref_table' => 'x', 'pairs' => [['col' => 'a', 'ref' => 'b']]]);
call('drop_index', ['table' => 'u', 'name' => 'idx_name']);
call('indexes', ['table' => 'u']);
call('import_rows', ['table' => 'u', 'columns' => ['name', 'years'], 'rows' => [['charlie', '30'], ['dave', ''], ['bob', '25']], 'mode' => 'insert', 'empty_null' => true]);
call('browse', ['table' => 'u']);
call('export_csv', ['table' => 'u', 'delimiter' => ',', 'header' => true, 'null_as' => 'NULL']);
call('server_info');
