<?php
// rename a room. sends { id, name }
require __DIR__ . '/dbconnect.php';

$b    = body();
$id   = (int)($b['id'] ?? 0);
$name = trim($b['name'] ?? '');
if (!$id || $name === '') send(['error' => 'id and name required'], 400);

q("UPDATE classrooms SET name = ? WHERE id = ?", [$name, $id]);
send(['success' => true]);
