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

$tests['Tile: rates the fuel by level and range'] = function () {
    $info = ['name' => '', 'online' => true, 'lastReport' => 1000, 'position' => null];
    $state = fn(array $v) => JlrTile::payload($v, $info, [], 2000)['fuelState'];
    check($state(['FuelLevel' => 60, 'FuelRange' => 210]) === 'ok', 'ok');
    check($state(['FuelLevel' => 20, 'FuelRange' => 210]) === 'low', 'low level');
    check($state(['FuelLevel' => 40, 'FuelRange' => 100]) === 'low', 'low range');
    check($state(['FuelLevel' => 10, 'FuelRange' => 210]) === 'critical', 'critical level');
    check($state(['FuelLevel' => 40, 'FuelRange' => 50]) === 'critical', 'critical range');
    check($state([]) === 'ok', 'unknown is no warning');
};

function journeyItem(string $start, string $end, $km = 42.5, array $extra = []): array
{
    return $extra + [
        'journeyId'     => 'j' . $start,
        'startTime'     => $start,
        'endTime'       => $end,
        'duration'      => 2700,
        'distance'      => ['value' => $km, 'unit' => 'km'],
        'averageSpeed'  => ['value' => 56.7, 'unit' => 'km/h'],
        'startLocation' => ['latitude' => 50.111, 'longitude' => 8.611],
        'endLocation'   => ['latitude' => 50.222, 'longitude' => 8.622],
    ];
}

$tests['Journeys: newest first, in km and minutes, never with coordinates'] = function () {
    $list = JlrTile::journeys(['items' => [
        journeyItem('2026-11-01T08:00:00Z', '2026-11-01T08:45:00Z'),
        journeyItem('2026-11-03T08:00:00Z', '2026-11-03T08:45:00Z', 1500, ['distance' => ['value' => 1500, 'unit' => 'm']]),
        ['startTime' => 'broken'],
    ]]);
    check(count($list) === 2 && $list[0]['start'] === strtotime('2026-11-03T08:00:00Z'), 'order');
    check($list[0]['km'] === 1.5 && $list[1]['km'] === 42.5, 'distance');
    check($list[1]['minutes'] === 45 && $list[1]['speed'] === 56.7, 'duration and speed');
    check(!str_contains(json_encode($list), '50.111') && !str_contains(json_encode($list), 'atitude'), 'no position');
};

$tests['Journeys: only the newest 30 are kept'] = function () {
    $items = [];
    for ($i = 1; $i <= 35; $i++) {
        $items[] = journeyItem(gmdate('c', 1000000 + $i * 3600), gmdate('c', 1000000 + $i * 3600 + 60));
    }
    $list = JlrTile::journeys(['items' => $items]);
    check(count($list) === 30 && $list[0]['start'] === 1000000 + 35 * 3600, 'newest 30');
};

$tests['Account: reads journeys with a clean proof URL, also from the last page'] = function () {
    $pages = function (array $call) {
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $q);
        $start = ($q['page'] ?? '0') === '0' ? '2026-11-01T08:00:00Z' : '2026-11-05T08:00:00Z';
        return [200, json_encode(['items' => [journeyItem($start, $start)], 'page' => ['totalPages' => 3]])];
    };
    TestRegistry::$http = fakeHttp(['/journeys' => $pages]);
    $a = makeAccount();
    $items = ask($a, 'journeys', VIN)['data']['items'];
    check(array_column($items, 'startTime') === ['2026-11-01T08:00:00Z', '2026-11-05T08:00:00Z'], json_encode(array_column($items, 'startTime')));
    $call = calls('/journeys')[1];
    check(str_contains($call['url'], 'page=2') && str_contains($call['url'], 'size=100'), $call['url']);
    check(!str_contains(jwtParts($call['headers']['DPoP'])[1]['htu'], '?'), 'htu without query');
};

$tests['Vehicle: journeys reach the tile without coordinates and are not read on every update'] = function () {
    TestRegistry::$http = fakeHttp(['/journeys' => [200, json_encode(['items' => [journeyItem('2026-11-04T15:00:00Z', '2026-11-04T15:45:00Z')]])]]);
    $m = makeJaguar();
    $p = json_decode(end($m->visu), true);
    check($p['journeyState'] === 'ok' && count($p['journeys']) === 1 && $p['journeys'][0]['km'] === 42.5, 'in the tile');
    check(!str_contains($m->attributes['Journeys'], '50.111'), 'nothing stored about the position');
    $reads = count(calls('/journeys'));
    TestRegistry::$now += 900;
    $m->RequestAction('Update', '');
    check(count(calls('/journeys')) === $reads, 'same report, read within the hour');
    TestRegistry::$now += 3600;
    $m->RequestAction('Update', '');
    check(count(calls('/journeys')) === $reads + 1, 'read again after an hour');
};

$tests['Vehicle: refused journeys leave the status alone and are asked again daily'] = function () {
    TestRegistry::$http = fakeHttp(['/journeys' => [403, '{"detail":"Access to category is not permitted for this vehicle"}']]);
    $m = makeJaguar();
    $p = json_decode(end($m->visu), true);
    check($p['online'] === true && $p['journeyState'] === 'refused' && $p['journeys'] === [], 'still online');
    $reads = count(calls('/journeys'));
    TestRegistry::$now += 7200;
    $m->RequestAction('Update', '');
    check(count(calls('/journeys')) === $reads, 'not again within a day');
    TestRegistry::$now += 86400;
    $m->RequestAction('Update', '');
    check(count(calls('/journeys')) === $reads + 1, 'again after a day');
};

$tests['Vehicle: a failed journey read keeps the last journeys'] = function () {
    TestRegistry::$http = fakeHttp(['/journeys' => [200, json_encode(['items' => [journeyItem('2026-11-04T15:00:00Z', '2026-11-04T15:45:00Z')]])]]);
    $m = makeJaguar();
    TestRegistry::$http = fakeHttp(['/journeys' => [500, 'oops']]);
    TestRegistry::$now += 3700;
    $m->RequestAction('Update', '');
    $p = json_decode(end($m->visu), true);
    check($p['online'] === true && count($p['journeys']) === 1 && $p['journeyState'] === 'ok', 'kept');
};

$tests['Vehicle: the tile names a failed journey read and clears it after a good one'] = function () {
    TestRegistry::$http = fakeHttp(['/journeys' => [404, '']]);
    $m = makeJaguar();
    $p = json_decode(end($m->visu), true);
    check($p['online'] === true && $p['journeyError'] === 'Abfrage fehlgeschlagen (HTTP 404)', json_encode($p['journeyError']));
    TestRegistry::$http = fakeHttp(['/journeys' => [200, '{"items":[]}']]);
    TestRegistry::$now += 3700;
    $m->RequestAction('Update', '');
    $p = json_decode(end($m->visu), true);
    check($p['journeyError'] === '' && $p['journeys'] === [] && $p['journeyState'] === 'ok', 'an empty list is no error');
};

$tests['Vehicle: refused journeys are no read error'] = function () {
    TestRegistry::$http = fakeHttp(['/journeys' => [403, '{"detail":"not permitted"}']]);
    $m = makeJaguar();
    check(json_decode(end($m->visu), true)['journeyError'] === '', 'refused has its own hint');
};

$tests['Tile: counts as resting from four days without a report'] = function () {
    $now = 1000000;
    $days = fn(int $report) => JlrTile::payload([], ['name' => '', 'online' => true, 'lastReport' => $report, 'position' => null], [], $now)['restingDays'];
    check($days($now - 4 * 86400 + 1) === null, 'just under four days');
    check($days($now - 4 * 86400) === 4, 'four days');
    check($days($now - 6 * 86400 - 5) === 6, 'six days');
    check($days(0) === null, 'never reported is not resting');
};
