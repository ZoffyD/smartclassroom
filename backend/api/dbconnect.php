<?php

// store and show all times in Malaysia time (UTC+8, no daylight saving)
date_default_timezone_set('Asia/Kuala_Lumpur');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// access control: the dashboard pages need a login; the ESP32 endpoints stay
// public so the device keeps working without any change (and never starts a
// session, so its frequent uploads don't pile up session files).
$PUBLIC_ENDPOINTS = ['uploadSensor.php', 'scan.php', 'getSettings.php'];
if (!in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), $PUBLIC_ENDPOINTS, true)) {
    require __DIR__ . '/auth.php';
    requireLogin();
}

// if anything throws, still hand back json instead of a blank 500 page
set_exception_handler(function ($e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
});

// connect once and reuse it for the rest of the request
function db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}";
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        send(['error' => 'Database connection failed. Check api/config.php.'], 500);
    }
    return $pdo;
}

// run a query with bound values
function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function one($sql, $params = []) { $row = q($sql, $params)->fetch(); return $row === false ? null : $row; }
function all($sql, $params = []) { return q($sql, $params)->fetchAll(); }

// read the json body the dashboard / esp32 sends
function body() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw   = file_get_contents('php://input');
    $cache = $raw ? (json_decode($raw, true) ?: []) : [];
    return $cache;
}

// send back json and stop
function send($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// store times in Malaysia local time (set above). no "Z" so the browser shows
// the string as-is instead of shifting it again.
function nowTime() { return date('Y-m-d H:i:s'); }
function iso($dt)  { return $dt ? str_replace(' ', 'T', $dt) : null; }

// tidy a card uid into one uppercase form, eg "aa:bb-cc" -> "AABBCC"
function normalizeUid($uid) { return strtoupper(preg_replace('/[\s:\-]/', '', trim((string)$uid))); }

// esp32 sends a device id, the dashboard sends a classroom id
function deviceOf()    { $b = body(); return $b['device'] ?? $_GET['device'] ?? null; }
function classroomOf() { $b = body(); $v = $b['classroom'] ?? $_GET['classroom'] ?? null; return $v !== null && $v !== '' ? (int)$v : null; }

// find the room linked to a board, if the board is new make one for it
function classroomForDevice($device) {
    $row = one("SELECT * FROM classrooms WHERE device_id = ?", [$device]);
    if ($row) {
        q("UPDATE classrooms SET last_seen = ? WHERE id = ?", [nowTime(), $row['id']]);
        return $row;
    }
    $n    = (int) one("SELECT COUNT(*) AS n FROM classrooms")['n'];
    $name = "Classroom " . ($n + 1);
    q("INSERT INTO classrooms (name, device_id, last_seen) VALUES (?,?,?)", [$name, $device, nowTime()]);
    $id = db()->lastInsertId();
    q("INSERT IGNORE INTO settings (classroom_id) VALUES (?)", [$id]);
    return one("SELECT * FROM classrooms WHERE id = ?", [$id]);
}

// which room is this request about? dashboard sends a room id, esp32 sends its device id
function resolveClassroom() {
    $cid = classroomOf();
    if ($cid) return one("SELECT * FROM classrooms WHERE id = ?", [$cid]);
    $dev = deviceOf();
    if ($dev) return classroomForDevice($dev);
    return null;
}

// get a room's thresholds, make a default row if it has none yet
function classroomSettings($cid) {
    q("INSERT IGNORE INTO settings (classroom_id) VALUES (?)", [$cid]);
    return one("SELECT * FROM settings WHERE classroom_id = ?", [$cid]);
}

// pdo gives everything back as strings, fix the number/bool types on a sensor row
function castSensor($r) {
    if (!$r) return $r;
    foreach (['temperature','humidity','gas','light','sound'] as $k)
        if (isset($r[$k]) && $r[$k] !== null) $r[$k] = 0 + $r[$k];
    if (isset($r['motion'])) $r['motion'] = (bool)(int)$r['motion'];
    if (isset($r['created_at'])) $r['created_at'] = iso($r['created_at']);
    return $r;
}
