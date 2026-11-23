<?php

$tests['Vehicle: without an account nothing is read, the timer keeps asking'] = function () {
    $m = makeJaguar([], null);
    check($m->currentStatus() === 201 && TestRegistry::$calls === [] && TestRegistry::$log === [], 'waits quietly');
    check($m->timers['Update']['interval'] === 900000, 'timer runs');
};

$tests['Vehicle: update fills the variables of the delivered values'] = function () {
    $m = makeJaguar();
    check($m->currentStatus() === 102, 'status ' . $m->currentStatus());
    check($m->value('Odometer') === 12345.678 && $m->value('FuelLevel') === 63 && $m->value('FuelRange') === 412, 'fuel');
    check($m->value('Locked') === true && $m->value('DoorFrontLeft') === false && $m->value('WindowFrontLeft') === true && $m->value('Hood') === false, 'doors');
    check($m->value('TyreFrontLeft') === 2.45 && $m->value('BatteryVoltage') === 12.4 && $m->value('WasherFluidLow') === true, 'numbers');
    check($m->value('VehicleState') === 'KEY_REMOVED', 'state');
    check($m->value('LastUpdate') === strtotime('2026-11-04T16:30:00Z'), 'last update');
    check($m->value('Latitude') === 50.1 && $m->value('Longitude') === 8.6, 'position');
    check(!isset($m->variables['DoorRearLeft']) && !isset($m->variables['Sunroof']), 'only delivered values');
    check($m->presentations['Odometer']['SUFFIX'] === ' km' && $m->presentations['Locked'] === '~Lock', 'presentation');
};

$tests['Vehicle: two vehicles share one account and one token'] = function () {
    $a = makeAccount();
    makeJaguar(['Vin' => VIN], $a, 2);
    makeJaguar(['Vin' => 'SAJOTHER000000002'], $a, 3);
    check(count(calls('/token')) === 1, 'one renewal');
    check(str_contains(calls('/status')[0]['url'], VIN) && str_contains(calls('/status')[1]['url'], 'SAJOTHER000000002'), 'each its own vin');
};

$tests['Vehicle: API error keeps the values and the timer, then recovers'] = function () {
    $m = makeJaguar();
    TestRegistry::$http = fakeHttp(['/status' => [500, 'oops']]);
    $m->RequestAction('Update', '');
    check($m->currentStatus() === 203 && $m->timers['Update']['interval'] === 900000 && $m->value('FuelLevel') === 63, 'kept');
    TestRegistry::$http = fakeHttp();
    $m->RequestAction('Update', '');
    check($m->currentStatus() === 102, 'recovers');
};

$tests['Vehicle: a dead link shows as missing account'] = function () {
    $m = makeJaguar();
    TestRegistry::$now += 400;
    TestRegistry::$http = fakeHttp(['/token' => [400, '{"error":"invalid_grant"}']]);
    $m->RequestAction('Update', '');
    check($m->currentStatus() === 201 && $m->value('FuelLevel') === 63, 'status ' . $m->currentStatus());
};

$tests['Vehicle: refused position does not fail the status, position can be switched off'] = function () {
    TestRegistry::$http = fakeHttp(['/location' => [403, '{"detail":"Access to category is not permitted for this vehicle"}']]);
    $m = makeJaguar();
    check($m->currentStatus() === 102 && $m->value('FuelLevel') === 63, 'status ok');
    TestRegistry::reset();
    $m = makeJaguar(['Position' => false]);
    check(!isset($m->variables['Latitude']) && calls('/location') === [], 'off');
};

// before the vehicle has reported to the Data Hub the snapshot is empty and stamped with the current time
$tests['Vehicle: an empty snapshot sets nothing'] = function () {
    TestRegistry::$http = fakeHttp(['/status' => [200, '{"Vehicle":{},"timestamp":"2026-11-13T18:58:27.676705291Z"}'], '/location' => [200, '{"Vehicle":{},"timestamp":"2026-11-13T18:58:27Z"}']]);
    $m = makeJaguar();
    check($m->currentStatus() === 102 && $m->value('LastUpdate') === 0 && !isset($m->variables['FuelLevel']), 'nothing set');
};

$tests['Vehicle: form lists the vehicles of the account, show values lists all signals'] = function () {
    $m = makeJaguar();
    check(array_column(json_decode($m->GetConfigurationForm(), true)['elements'][1]['options'], 'value') === ['', VIN], 'vin options');
    check(str_contains(echoed(fn () => $m->RequestAction('ShowValues', '')), 'Body.Mirrors.Folded: YES'), 'all values');
    check(echoed(fn () => $m->RequestAction('UpdateNow', '')) === 'Status updated', 'update now');
};

const MODULES = ['JaguarAccount' => 'JAGIO', 'Jaguar' => 'JAG'];

$tests['Language: all texts are translated, none unused'] = function () {
    foreach (array_keys(MODULES) as $module) {
        $de = json_decode(file_get_contents(__DIR__ . "/../$module/locale.json"), true)['translations']['de'];
        $strings = $module === 'Jaguar' ? array_column(JlrStatus::variables(), 'name') : [];
        preg_match_all('/->Translate\("((?:[^"\\\\]|\\\\.)*)"\)/', file_get_contents(__DIR__ . "/../$module/module.php"), $code);
        $form = json_decode(file_get_contents(__DIR__ . "/../$module/form.json"), true);
        array_walk_recursive($form, function ($v, $k) use (&$strings) {
            if (in_array($k, ['caption', 'confirm'], true) && $v !== '') {
                $strings[] = $v;
            }
        });
        $strings = array_merge($strings, $code[1]);
        check(array_diff($strings, array_keys($de)) === [], "$module missing: " . implode(' | ', array_diff($strings, array_keys($de))));
        check(array_diff(array_keys($de), $strings) === [], "$module unused: " . implode(' | ', array_diff(array_keys($de), $strings)));
    }
};

$tests['Convention: module files, public functions, data flow, libs without Symcon'] = function () {
    $allowed = ['Create', 'ApplyChanges', 'MessageSink', 'RequestAction', 'GetConfigurationForm', 'ForwardData', 'ReceiveData'];
    $json = [];
    foreach (MODULES as $module => $prefix) {
        $json[$module] = json_decode(file_get_contents(__DIR__ . "/../$module/module.json"), true);
        check($json[$module]['name'] === $module && $json[$module]['prefix'] === $prefix, "$module: module.json");
        $public = [];
        foreach ((new ReflectionClass($module))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class === $module) {
                $public[] = $method->name;
            }
        }
        check(array_diff($public, $allowed) === [], "$module: " . implode(', ', $public));
        $src = file_get_contents(__DIR__ . "/../$module/module.php");
        foreach (['IPS_SetProperty', 'IPS_ApplyChanges', 'IPS_LogMessage'] as $fn) {
            check(!str_contains($src, $fn), "$module uses $fn");
        }
    }
    [$account, $vehicle] = [$json['JaguarAccount'], $json['Jaguar']];
    check($account['type'] === 1 && $vehicle['type'] === 3 && $account['id'] !== $vehicle['id'], 'types');
    check($vehicle['parentRequirements'] === $account['implemented'] && $account['implemented'] === [Jaguar::TO_PARENT], 'vehicle -> account');
    check($account['childRequirements'] === $vehicle['implemented'] && $vehicle['implemented'] === [JaguarAccount::TO_CHILDREN], 'account -> vehicle');
    check('{' . trim($account['id'], '{}') . '}' === Jaguar::ACCOUNT_MODULE, 'ConnectParent');
    foreach (glob(__DIR__ . '/../libs/*.php') as $file) {
        check(preg_match('/\\bIPS_|\\$this->(Read|Write|Register|Set)/', file_get_contents($file)) === 0, basename($file));
    }
};

$tests['Vehicle: an existing variable is left to the user'] = function () {
    $m = makeJaguar();
    $m->presentations['FuelLevel'] = 'user choice';
    $m->RequestAction('Update', '');
    check($m->presentations['FuelLevel'] === 'user choice', 'presentation overwritten');
    check($m->value('FuelLevel') === 63, 'value still set');
};
