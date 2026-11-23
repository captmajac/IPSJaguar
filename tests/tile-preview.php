<?php

// Local preview of Jaguar/module.html with made-up data.
// Serve the repo root: ../tools/php/php -S 127.0.0.1:8766 and open /tests/tile-preview.php
// Parameters: ?sample=0..6 &theme=light &w=380 &h=720

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../libs/JlrTile.php';

$now = time();
$days = [];
$km = 12000.0;
foreach ([28 => 0, 25 => 42, 21 => 118, 18 => 12, 14 => 260, 11 => 35, 7 => 76, 4 => 18, 2 => 141, 0 => 23] as $ago => $driven) {
    $km += $driven;
    $days[date('Y-m-d', $now - $ago * 86400)] = $km;
}
$values = [
    'FuelLevel' => 64, 'FuelRange' => 412, 'Odometer' => $km, 'BatteryVoltage' => 12.4, 'ServiceDistance' => 8300,
    'Locked' => true, 'BootLocked' => true, 'Boot' => false, 'Hood' => false, 'Convertible' => false,
    'DoorFrontLeft' => false, 'DoorFrontRight' => false, 'WindowFrontLeft' => false, 'WindowFrontRight' => false,
    'TyreFrontLeft' => 2.3, 'TyreFrontRight' => 2.3, 'TyreRearLeft' => 2.5, 'TyreRearRight' => 2.45,
];
$journeys = [];
foreach ([[3, 42.5, 38, 67], [8, 12.3, 19, 39], [29, 141.0, 95, 89], [31, 7.8, 14, 33]] as [$hours, $km, $minutes, $speed]) {
    $start = $now - $hours * 3600;
    $journeys[] = ['start' => $start, 'end' => $start + $minutes * 60, 'km' => $km, 'minutes' => $minutes, 'speed' => (float) $speed];
}
$info = ['name' => 'F-TYPE', 'online' => true, 'lastReport' => $now - 3600, 'position' => [50.94130, 6.95830], 'journeys' => $journeys, 'journeyState' => 'ok'];
$samples = [
    JlrTile::payload($values, $info, $days, $now),
    JlrTile::payload(['FuelLevel' => 8, 'DoorFrontRight' => true, 'WindowFrontLeft' => true, 'TyreLowRearLeft' => true, 'OilLow' => true, 'Locked' => false, 'BootLocked' => false] + $values, $info, $days, $now),
    JlrTile::payload($values, ['online' => false, 'journeys' => [], 'journeyState' => 'refused'] + $info, $days, $now),
    JlrTile::payload([], ['name' => '', 'online' => true, 'lastReport' => 0, 'position' => null], [], $now),
    JlrTile::payload(['Locked' => false, 'BootLocked' => false, 'VehicleState' => 'KEY_ON_ENGINE_OFF'] + $values, $info, $days, $now),
    JlrTile::payload(['FuelLevel' => 20, 'FuelRange' => 95] + $values, $info, $days, $now),
    JlrTile::payload($values, ['journeys' => [], 'journeyError' => 'Abfrage fehlgeschlagen (HTTP 404)'] + $info, $days, $now),
];
$sample = $samples[(int) ($_GET['sample'] ?? 0)] ?? $samples[0];
$light = ($_GET['theme'] ?? '') === 'light';
$w = (int) ($_GET['w'] ?? 380);
$h = (int) ($_GET['h'] ?? 720);
$html = file_get_contents(__DIR__ . '/../Jaguar/module.html');
$doc = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:-apple-system,sans-serif;font-size:15px;--accent-color:#00cdab;--on-accent-color:#fff;'
    . ($light ? '--content-color:#10161e;background:#fff;' : '--content-color:#e8eef5;background:#1b2531;')
    . '}</style><script>function requestAction(){setTimeout(()=>handleMessage(' . json_encode(json_encode($sample), JSON_HEX_TAG | JSON_HEX_AMP) . '),600)}</script></head><body>'
    . '<div style="position:fixed;left:20px;top:12px;font-size:19px;color:#fff">Modulname</div><div style="position:fixed;right:20px;top:14px;width:18px;height:18px;border:1px solid #fff"></div>'
    . $html . '<script>handleMessage(' . json_encode(json_encode($sample), JSON_HEX_TAG | JSON_HEX_AMP) . ');</script></body></html>';
?>
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>Jaguar tile preview</title>
<style>body{margin:0;background:#10161e}#tile{display:block;width:<?= $w ?>px;height:<?= $h ?>px;margin:8px;border:1px solid #556;border-radius:10px}</style>
</head>
<body><iframe id="tile" srcdoc="<?= htmlspecialchars($doc) ?>"></iframe></body>
</html>
