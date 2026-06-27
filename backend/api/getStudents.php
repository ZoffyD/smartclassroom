<?php
// students in a room, each with their latest in/out status
require __DIR__ . '/dbconnect.php';

$cid = classroomOf();
if (!$cid) send([]);
$rows = all("SELECT s.uid, s.name, s.matric,
               (SELECT a.type FROM attendance a
                WHERE a.uid = s.uid AND a.classroom_id = s.classroom_id
                ORDER BY a.created_at DESC LIMIT 1) AS last_type
             FROM students s WHERE s.classroom_id = ? ORDER BY s.name", [$cid]);
send($rows);
