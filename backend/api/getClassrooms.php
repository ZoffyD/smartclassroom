<?php
// list every room for the dropdown
require __DIR__ . '/dbconnect.php';

$rows = all("SELECT * FROM classrooms ORDER BY id");
foreach ($rows as &$r) $r['last_seen'] = iso($r['last_seen']);
send($rows);
