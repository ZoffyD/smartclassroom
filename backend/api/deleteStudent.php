<?php
// remove a student from a room. sends { uid } plus ?classroom= in the url
require __DIR__ . '/dbconnect.php';

$cid = classroomOf();
$uid = body()['uid'] ?? ($_GET['uid'] ?? '');
if (!$cid || $uid === '') send(['error' => 'classroom and uid required'], 400);

q("DELETE FROM students WHERE uid = ? AND classroom_id = ?", [$uid, $cid]);
send(['success' => true]);
