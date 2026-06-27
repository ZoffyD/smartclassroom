<?php
// 24 hour summary of the room (environment + attendance)
require __DIR__ . '/dbconnect.php';

$c = resolveClassroom();
if (!$c) send(['message' => 'No classroom selected.']);
$since = date('Y-m-d H:i:s', time() - 24 * 3600);

$records = $c['device_id']
    ? all("SELECT * FROM sensors WHERE device_id = ? AND created_at >= ? ORDER BY created_at ASC", [$c['device_id'], $since])
    : [];

$present = (int) one("SELECT COUNT(DISTINCT a.uid) AS cnt FROM attendance a
                      JOIN (SELECT uid, MAX(created_at) mx FROM attendance WHERE classroom_id = ? GROUP BY uid) last
                        ON last.uid = a.uid AND last.mx = a.created_at
                      WHERE a.classroom_id = ? AND a.type = 'IN'", [$c['id'], $c['id']])['cnt'];
$total = (int) one("SELECT COUNT(*) AS cnt FROM students WHERE classroom_id = ?", [$c['id']])['cnt'];

if (count($records) === 0) send(['message' => 'No sensor data in the last 24 hours.']);

$temps = array_values(array_filter(array_map(fn($r) => $r['temperature'], $records), fn($v) => $v !== null));
$gases = array_values(array_filter(array_map(fn($r) => $r['gas'], $records),         fn($v) => $v !== null));
$avg   = fn($a) => array_sum($a) / count($a);

$motionEvents  = count(array_filter($records, fn($r) => (int)$r['motion'] === 1));
$warningCount  = count(array_filter($records, fn($r) => $r['status'] === 'Warning'));
$criticalCount = count(array_filter($records, fn($r) => $r['status'] === 'Critical'));
$latest        = $records[count($records) - 1];
$occupied      = ($motionEvents / count($records)) > 0.2;

$classification = 'Safe';
if ($latest['status'] === 'Critical')     $classification = 'Danger';
else if ($latest['status'] === 'Warning') $classification = 'Warning';

$recommendation = 'System operating normally.';
if (!$occupied)                          $recommendation = 'Room appears unoccupied. Consider turning off fan to save energy.';
else if ($latest['temperature'] < 26)    $recommendation = 'Temperature is comfortable. Fan can be turned off.';
else if ($latest['temperature'] > 30)    $recommendation = 'High temperature detected. Fan is recommended.';

send([
    'total_readings'        => count($records),
    'avg_temperature'       => count($temps) ? number_format($avg($temps), 1) : null,
    'max_temperature'       => count($temps) ? number_format(max($temps), 1) : null,
    'min_temperature'       => count($temps) ? number_format(min($temps), 1) : null,
    'avg_gas'               => count($gases) ? round($avg($gases)) : null,
    'max_gas'               => count($gases) ? max($gases) : null,
    'motion_events'         => $motionEvents,
    'warning_count'         => $warningCount,
    'critical_count'        => $criticalCount,
    'students_present'      => $present,
    'total_students'        => $total,
    'occupancy_status'      => $occupied ? 'Occupied' : 'Unoccupied',
    'classification'        => $classification,
    'energy_recommendation' => $recommendation,
]);
