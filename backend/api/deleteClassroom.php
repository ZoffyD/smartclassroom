<?php
// delete a room and everything in it. sends { id }
require __DIR__ . '/dbconnect.php';

$id = (int)(body()['id'] ?? 0);
if (!$id) send(['error' => 'id required'], 400);

q("DELETE FROM classrooms WHERE id = ?", [$id]);
send(['success' => true]);
