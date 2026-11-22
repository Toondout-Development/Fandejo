<?php
// Unit test for the SHOW GRANTS parser. Run: php tests/grants_parse.php
$src = file_get_contents(__DIR__ . '/../public/api.php');
preg_match('/(const PRIV_GLOBAL.*?)\n\$rawBody = /s', $src, $m);
eval($m[1]);
$r = parse_grant_lines([
  "GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` IDENTIFIED VIA mysql_native_password USING 'x' WITH GRANT OPTION",
  "GRANT USAGE ON *.* TO `app`@`%` IDENTIFIED BY PASSWORD '*ABC'",
  "GRANT SELECT, INSERT, UPDATE ON `shop`.* TO `app`@`%`",
  "GRANT ALL PRIVILEGES ON `my\\_db`.* TO `app`@`%`",
  "GRANT SELECT, INSERT ON `shop`.`or``ders` TO `app`@`%` WITH GRANT OPTION",
  "GRANT SELECT (id, name), UPDATE (name) ON `shop`.`users` TO `app`@`%`",
  "GRANT `reader` TO `app`@`%`",
]);
foreach ($r as $e) echo json_encode($e), "\n";
assert($r[0]['db'] === '*' && $r[0]['all'] && $r[0]['option']);
assert(count($r) === 5 || count($r) === 6);
echo "OK\n";
