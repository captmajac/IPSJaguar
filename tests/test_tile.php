<?php

$tests['Tile: daily km come from the odometer, a gap counts on the day of the report'] = function () {
    $days = [];
    $days = JlrTile::recordDay($days, 1000.0, strtotime('2026-11-01 12:00'));
    $days = JlrTile::recordDay($days, 1010.0, strtotime('2026-11-01 18:00'));
    $days = JlrTile::recordDay($days, 1060.0, strtotime('2026-11-04 09:00'));
    $stats = JlrTile::statistics($days, strtotime('2026-11-05 12:00'));
    check(count($stats['days']) === 30 && end($stats['days'])['date'] === '2026-11-05', 'thirty days up to today');
    $km = array_column($stats['days'], 'km', 'date');
    check($km['2026-11-01'] === 0 && $km['2026-11-02'] === 0, 'first day is only the baseline');
    check($km['2026-11-04'] === 50 && $km['2026-11-05'] === 0, 'drive on the day of the report');
    check($stats['total'] === 50 && $stats['drivingDays'] === 1 && $stats['perDay'] === 50, json_encode($stats['total']));
};

$tests['Tile: the history keeps 32 days and ignores a falling odometer'] = function () {
    $days = [];
    for ($i = 0; $i < 40; $i++) {
        $days = JlrTile::recordDay($days, 100.0 + $i, strtotime('2026-10-01 12:00') + $i * 86400);
    }
    check(count($days) === 32, 'pruned to ' . count($days));
    $days = JlrTile::recordDay(['2026-11-01' => 500.0], 400.0, strtotime('2026-11-02 12:00'));
    check(JlrTile::statistics($days, strtotime('2026-11-02 13:00'))['total'] === 0, 'no negative distance');
};

$tests['Tile: payload sums up doors, windows, tyres and warnings'] = function () {
    $values = JlrStatus::values(JlrStatus::flatten(json_decode(file_get_contents(__DIR__ . '/fixtures/status_ftype.json'), true)));
    $values['DoorFrontRight'] = true;
    $values['WindowRearLeft'] = true;
    $values['OilLow'] = true;
    $p = JlrTile::payload($values, ['name' => 'F-TYPE', 'online' => true, 'lastReport' => 1000, 'position' => [50.0, 8.0]], [], 2000);
    check($p['name'] === 'F-TYPE' && $p['online'] === true && $p['lastReport'] === 1000, 'head');
    check($p['fuel'] === 50 && $p['range'] === 300 && $p['odometer'] === 50000.0 && $p['locked'] === true && $p['bootLocked'] === true, 'main');
    check($p['doors'] === ['vorne rechts'], json_encode($p['doors']));
    check($p['windows'] === ['hinten links'], json_encode($p['windows']));
    check(array_column($p['tyres'], 'label') === ['Vorne links', 'Vorne rechts', 'Hinten links', 'Hinten rechts'], 'tyre order');
    check($p['tyres'][3]['bar'] === 2.09 && $p['tyres'][3]['low'] === true && $p['tyres'][0]['low'] === false, 'tyre values');
    check($p['warnings'] === ['Motoröl niedrig'], json_encode($p['warnings']));
    check($p['position'] === ['lat' => 50.0, 'lon' => 8.0], 'position');
};

$tests['Tile: missing values stay null, an empty vehicle still renders'] = function () {
    $p = JlrTile::payload([], ['name' => '', 'online' => false, 'lastReport' => 0, 'position' => null], [], 2000);
    check($p['fuel'] === null && $p['locked'] === null && $p['doors'] === null && $p['windows'] === null, 'unknown is not closed');
    check($p['tyres'] === [] && $p['warnings'] === [] && $p['position'] === null, 'empty lists');
    check(count($p['stats']['days']) === 30, 'empty chart');
};

$tests['Vehicle: the tile gets the status, name, position and today in the history'] = function () {
    TestRegistry::$http = fakeHttp(['/vehicles/' . VIN => [200, json_encode(['Vehicle' => ['VehicleIdentification' => ['Brand' => 'Jaguar', 'Model' => 'F-Type']]])]]);
    $m = makeJaguar();
    $p = json_decode(end($m->visu), true);
    check($p['name'] === 'F-TYPE' && $p['online'] === true && $p['fuel'] === 63, json_encode([$p['name'], $p['fuel']]));
    check($p['position'] === ['lat' => 50.1, 'lon' => 8.6], 'position');
    check(json_decode($m->attributes['Days'], true) === [date('Y-m-d', strtotime('2026-11-04T16:30:00Z')) => 12345.678], $m->attributes['Days']);
    $tile = $m->GetVisualizationTile();
    check(str_contains($tile, 'function handleMessage') && str_contains($tile, 'handleMessage('), 'tile with initial data');
    check($m->visualizationType === 1, 'tile visualization');
    $m->RequestAction('Update', '');
    check(count(calls('/vehicles/' . VIN)) === 1, 'name read only once');
};

$tests['Vehicle: a failed update still tells the tile'] = function () {
    $m = makeJaguar();
    TestRegistry::$http = fakeHttp(['/status' => [500, 'oops']]);
    $m->RequestAction('Update', '');
    $p = json_decode(end($m->visu), true);
    check($p['online'] === false && $p['fuel'] === 63, 'offline with the last values');
};

$tests['Vehicle: the refresh button of the tile reads at most once a minute'] = function () {
    $m = makeJaguar();
    $before = count(calls('/status'));
    $m->RequestAction('Refresh', '');
    check(count(calls('/status')) === $before, 'throttled');
    TestRegistry::$now += 61;
    $m->RequestAction('Refresh', '');
    check(count(calls('/status')) === $before + 1, 'read again');
};

$tests['Account: passes the vehicle details on'] = function () {
    TestRegistry::$http = fakeHttp(['/vehicles/' . VIN => [200, '{"Vehicle":{"VehicleIdentification":{"Model":"F-Type"}}}']]);
    $a = makeAccount();
    check(ask($a, 'vehicle', VIN)['data']['Vehicle']['VehicleIdentification']['Model'] === 'F-Type', 'details');
};

$tests['Tile: knows when the last report came with the ignition on'] = function () {
    $info = ['name' => '', 'online' => true, 'lastReport' => 1000, 'position' => null];
    check(JlrTile::payload(['VehicleState' => 'KEY_ON_ENGINE_OFF'], $info, [], 2000)['ignitionOn'] === true, 'key on');
    check(JlrTile::payload(['VehicleState' => 'KEY_OFF'], $info, [], 2000)['ignitionOn'] === false, 'key off');
    check(JlrTile::payload([], $info, [], 2000)['ignitionOn'] === false, 'unknown');
};
