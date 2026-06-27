<?php
// newest reading for the chosen room
require __DIR__ . '/dbconnect.php';

$c = resolveClassroom();
if (!$c || !$c['device_id']) send(null);
$row = one("SELECT * FROM sensors WHERE device_id = ? ORDER BY created_at DESC LIMIT 1", [$c['device_id']]);
send(castSensor($row));
