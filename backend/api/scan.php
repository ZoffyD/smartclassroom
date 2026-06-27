<?php
// esp32 sends a tapped card uid, we log a check-in or check-out
require __DIR__ . '/dbconnect.php';

$b = body();
if (empty($b['uid'])) send(['error' => 'uid required'], 400);
$device = deviceOf();
if (!$device) send(['error' => 'device required'], 400);

$uid = normalizeUid($b['uid']);
$c   = classroomForDevice($device);

$student = one("SELECT * FROM students WHERE uid = ? AND classroom_id = ?", [$uid, $c['id']]);
if (!$student) {
    // remember this unknown card so the dashboard can auto-fill it into the
    // register form (see getLastScan.php)
    q("UPDATE classrooms SET last_scan_uid = ?, last_scan_at = ? WHERE id = ?", [$uid, nowTime(), $c['id']]);
    send(['registered' => false, 'uid' => $uid]);
}

// ignore the same card tapped again within 3 seconds (debounce)
$recent = one("SELECT created_at FROM attendance WHERE uid = ? AND classroom_id = ?
               ORDER BY created_at DESC LIMIT 1", [$uid, $c['id']]);
if ($recent && (strtotime($recent['created_at']) > time() - 3)) {
    send(['registered' => true, 'ignored' => true, 'name' => $student['name'], 'matric' => $student['matric']]);
}

// alternate IN / OUT based on the last record for this card
$last = one("SELECT type FROM attendance WHERE uid = ? AND classroom_id = ?
             ORDER BY created_at DESC LIMIT 1", [$uid, $c['id']]);
$type = (($last['type'] ?? '') === 'IN') ? 'OUT' : 'IN';

q("INSERT INTO attendance (classroom_id, uid, name, matric, type, created_at) VALUES (?,?,?,?,?,?)",
  [$c['id'], $uid, $student['name'], $student['matric'], $type, nowTime()]);

send(['registered' => true, 'name' => $student['name'], 'matric' => $student['matric'], 'type' => $type]);
