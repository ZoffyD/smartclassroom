<?php
// recent readings for the chosen room (for the charts)
require __DIR__ . '/dbconnect.php';

$c = resolveClassroom();
if (!$c || !$c['device_id']) send([]);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$rows  = all("SELECT * FROM sensors WHERE device_id = ? ORDER BY created_at DESC LIMIT $limit", [$c['device_id']]);
send(array_map('castSensor', $rows));
