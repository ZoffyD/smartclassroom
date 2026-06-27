<?php
// the newest unregistered card tapped in this room, so the dashboard can drop
// it straight into the register form. only returns a card tapped in the last
// 15 seconds that still isn't registered, otherwise null.
require __DIR__ . '/dbconnect.php';

$c = resolveClassroom();
if (!$c || empty($c['last_scan_uid']) || empty($c['last_scan_at'])) send(['uid' => null]);

$fresh   = strtotime($c['last_scan_at']) > time() - 15;
$already = one("SELECT 1 FROM students WHERE uid = ? AND classroom_id = ?", [$c['last_scan_uid'], $c['id']]);

send(['uid' => ($fresh && !$already) ? $c['last_scan_uid'] : null]);
