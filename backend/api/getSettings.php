<?php
// alert thresholds for a room. works for the dashboard (room id)
// and the esp32 (device id)
require __DIR__ . '/dbconnect.php';

$c = resolveClassroom();
if (!$c) send(['temp_warning'=>30,'temp_danger'=>35,'gas_warning'=>800,'gas_danger'=>1500,'upload_interval'=>5]);

$s = classroomSettings($c['id']);
send([
    'temp_warning'    => 0 + $s['temp_warning'],
    'temp_danger'     => 0 + $s['temp_danger'],
    'gas_warning'     => (int)$s['gas_warning'],
    'gas_danger'      => (int)$s['gas_danger'],
    'upload_interval' => (int)$s['upload_interval'],
]);
