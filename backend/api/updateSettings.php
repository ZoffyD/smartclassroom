<?php
// save a room's alert thresholds. sends { classroom, temp_warning, ... }
require __DIR__ . '/dbconnect.php';

$cid = classroomOf();
if (!$cid) send(['error' => 'classroom required'], 400);
$b = body();
classroomSettings($cid);
q("UPDATE settings SET temp_warning=?, temp_danger=?, gas_warning=?, gas_danger=?,
     upload_interval=?, updated_at=? WHERE classroom_id=?",
   [$b['temp_warning'], $b['temp_danger'], $b['gas_warning'], $b['gas_danger'],
    $b['upload_interval'], nowTime(), $cid]);
send(['success' => true]);
