<?php
// register a student in a room. sends { classroom, uid, name, matric }
require __DIR__ . '/dbconnect.php';

$cid = classroomOf();
$b   = body();
if (!$cid || empty($b['uid']) || empty($b['name']) || empty($b['matric']))
    send(['error' => 'classroom, uid, name, matric all required'], 400);

$uid    = normalizeUid($b['uid']);
$name   = $b['name'];
$matric = $b['matric'];

// a name or matric can't belong to two different cards in the same room
if (one("SELECT 1 FROM students WHERE classroom_id = ? AND matric = ? AND uid <> ?", [$cid, $matric, $uid]))
    send(['error' => 'That matric is already used in this classroom.'], 409);
if (one("SELECT 1 FROM students WHERE classroom_id = ? AND name = ? AND uid <> ?", [$cid, $name, $uid]))
    send(['error' => 'That name is already used in this classroom.'], 409);

// same card scanning again just updates its own name / matric
q("INSERT INTO students (uid, classroom_id, name, matric) VALUES (?,?,?,?)
   ON DUPLICATE KEY UPDATE name = VALUES(name), matric = VALUES(matric)",
  [$uid, $cid, $name, $matric]);
send(['success' => true]);
