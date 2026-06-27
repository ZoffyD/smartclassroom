<?php
// recent check-in / check-out log for a room
require __DIR__ . '/dbconnect.php';

$cid = classroomOf();
if (!$cid) send([]);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$date  = $_GET['date'] ?? '';

// optional ?date=YYYY-MM-DD to show just one day, otherwise the latest taps
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $rows = all("SELECT * FROM attendance WHERE classroom_id = ? AND DATE(created_at) = ?
                 ORDER BY created_at DESC LIMIT $limit", [$cid, $date]);
} else {
    $rows = all("SELECT * FROM attendance WHERE classroom_id = ?
                 ORDER BY created_at DESC LIMIT $limit", [$cid]);
}
foreach ($rows as &$r) $r['created_at'] = iso($r['created_at']);
send($rows);
