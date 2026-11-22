<?php
// Unit test for MySQL column-definition builder. Run: php tests/coldef.php
$src = file_get_contents(__DIR__ . '/../public/api.php');
function q(string $id, string $drv): string { return '`' . str_replace('`', '``', $id) . '`'; }
preg_match('/(const COL_TYPES.*?)\n\$rawBody = /s', $src, $m); eval($m[1]);
$p = new PDO('sqlite::memory:');
$t = [
  [['type' => 'varchar', 'length' => '255', 'collation' => 'utf8mb4_unicode_ci', 'default_mode' => 'value', 'default' => "it's", 'comment' => 'x', 'after' => 'id'], "VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'it''s' COMMENT 'x'"],
  [['type' => 'INT', 'unsigned' => true, 'ai' => true], 'INT UNSIGNED NOT NULL AUTO_INCREMENT'],
  [['type' => 'ENUM', 'values' => ['a', 'b'], 'null' => true, 'default_mode' => 'null'], "ENUM('a','b') NULL DEFAULT NULL"],
  [['type' => 'TIMESTAMP', 'default_mode' => 'expr', 'default' => 'CURRENT_TIMESTAMP'], 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'],
];
foreach ($t as [$in, $exp]) { $got = col_def($p, 'mysql', $in); echo $got === $exp ? "ok   " : "FAIL ", $got, "\n"; }
foreach ([['type' => 'INT; DROP'], ['type' => 'INT', 'length' => '1);--'], ['type' => 'INT', 'default_mode' => 'expr', 'default' => '1; DROP TABLE x']] as $bad) {
  try { col_def($p, 'mysql', $bad); echo "FAIL accepted\n"; } catch (InvalidArgumentException $e) { echo "ok   rejected: ", $e->getMessage(), "\n"; } }
echo col_pos('mysql', ['after' => '__FIRST__']), '|', col_pos('mysql', ['after' => 'id']), "\n";
print_r(parse_coltype("int(10) unsigned")); print_r(parse_coltype("enum('a','it''s')"));
