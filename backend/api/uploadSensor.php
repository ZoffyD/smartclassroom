<?php
// esp32 posts one sensor reading here
require __DIR__ . '/dbconnect.php';

const SENSOR_KEEP = 1000;   // keep only the latest 1000 readings per device

$device = deviceOf();
if (!$device) send(['error' => 'device required'], 400);
$b = body();
$c = classroomForDevice($device);
$s = classroomSettings($c['id']);

// decide Normal / Warning / Critical from this room's thresholds
$temp = $b['temperature'] ?? null;
$gas  = $b['gas'] ?? null;
$status = 'Normal';
if (($gas !== null && $gas > $s['gas_danger']) || ($temp !== null && $temp > $s['temp_danger']))      $status = 'Critical';
else if (($gas !== null && $gas > $s['gas_warning']) || ($temp !== null && $temp > $s['temp_warning'])) $status = 'Warning';

q("INSERT INTO sensors (device_id, temperature, humidity, gas, light, sound, motion, status, created_at)
   VALUES (?,?,?,?,?,?,?,?,?)",
   [$device, $temp, $b['humidity'] ?? null, $gas, $b['light'] ?? null,
    $b['sound'] ?? null, !empty($b['motion']) ? 1 : 0, $status, nowTime()]);

// every so often, trim this device's oldest rows so the table stays small
if (rand(1, 50) === 1) {
    q("DELETE FROM sensors WHERE device_id = ? AND id NOT IN (
         SELECT id FROM (
           SELECT id FROM sensors WHERE device_id = ? ORDER BY created_at DESC LIMIT " . SENSOR_KEEP . "
         ) keep)", [$device, $device]);
}
send(['success' => true, 'status' => $status]);
