<?php

// Shared test helpers

const VIN = 'SAJTESTVIN0000001';

// one key pair for all tests (creating one takes a moment)
function testCredentials(): array
{
    static $credentials = null;
    return $credentials ??= DataHubClient::generateCredentials();
}

// access token as JLR issue it: the VINs are a claim
function accessToken(string $name = 'acc', array $vins = [VIN]): string
{
    $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    return $b64('{"alg":"none"}') . '.' . $b64(json_encode(['vehicles' => $vins, 'name' => $name])) . '.sig';
}

function tokenJson(string $name = 'acc', string $refresh = 'ref1'): string
{
    return json_encode(['access_token' => accessToken($name), 'refresh_token' => $refresh, 'expires_in' => 300]);
}

function statusJson(array $vehicle = []): string
{
    return json_encode([
        'timestamp' => '2026-11-04T16:30:00Z',
        'Vehicle'   => array_replace_recursive([
            'VehicleIdentification' => ['VIN' => VIN],
            'TraveledDistance'      => 12345678,
            'Powertrain'            => ['FuelSystem' => ['RelativeLevel' => 63, 'Range' => 412]],
            'Body'                  => [
                'Door'       => ['Lock' => ['Status' => 'TRUE']],
                'Hood'       => ['IsOpen' => false],
                'Windshield' => ['Front' => ['WasherFluid' => ['IsLevelLow' => true]]],
                'Mirrors'    => ['Folded' => 'YES'],
            ],
            'Cabin'                 => ['Door' => ['Row1' => ['DriverSide' => ['IsOpen' => 'CLOSED', 'Window' => ['Position' => 'OPEN']]], 'Row2' => ['DriverSide' => ['IsOpen' => 'UNKNOWN']]]],
            'Chassis'               => ['Axle' => ['Row1' => ['Wheel' => ['Left' => ['Tire' => ['Pressure' => 245]]]]]],
            'LowVoltageBattery'     => ['CurrentVoltage' => 12.4],
            'System'                => ['DriveCycle' => ['Status' => 'KEY_REMOVED']],
        ], $vehicle),
    ]);
}

// fake JLR servers: URL part => [status, body, headers?] or fn (array $call): array; records the calls
function fakeHttp(array $overrides = []): callable
{
    $responses = $overrides + [
        '/auth/device' => [200, json_encode(['device_code' => 'dev', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://auth.example/device', 'verification_uri_complete' => 'https://auth.example/device?user_code=ABCD-EFGH', 'interval' => 5, 'expires_in' => 600])],
        '/token'       => [200, tokenJson()],
        '/status'      => [200, statusJson()],
        '/location'    => [200, json_encode(['timestamp' => '2026-11-04T16:00:00Z', 'Vehicle' => ['CurrentLocation' => ['Latitude' => 50.1, 'Longitude' => 8.6]]])],
    ];
    return function (string $method, string $url, array $headers, ?string $body) use ($responses): array {
        parse_str((string) $body, $form);
        $call = ['method' => $method, 'url' => $url, 'headers' => $headers, 'form' => $form];
        TestRegistry::$calls[] = $call;
        foreach ($responses as $part => $response) {
            if (str_ends_with(explode('?', $url)[0], $part)) {
                $response = is_callable($response) ? $response($call) : $response;
                return ['status' => $response[0], 'body' => $response[1], 'headers' => $response[2] ?? []];
            }
        }
        return ['status' => 404, 'body' => ''];
    };
}

// recorded calls whose URL path ends with $part
function calls(string $part): array
{
    return array_values(array_filter(TestRegistry::$calls, fn ($c) => str_ends_with(explode('?', $c['url'])[0], $part)));
}

// header and claims of a JWT
function jwtParts(string $jwt): array
{
    [$h, $c] = explode('.', $jwt);
    $decode = fn (string $s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
    return [$decode($h), $decode($c)];
}

// true if the PS256 signature of the JWT fits the test key (checked by the openssl command line)
function jwtVerified(string $jwt): bool
{
    [$h, $c, $s] = explode('.', $jwt);
    $dir = sys_get_temp_dir() . '/jagtest' . getmypid();
    @mkdir($dir);
    openssl_x509_export(openssl_x509_read(testCredentials()['certificate']), $pem);
    file_put_contents("$dir/pub.pem", openssl_pkey_get_details(openssl_pkey_get_public($pem))['key']);
    file_put_contents("$dir/msg", "$h.$c");
    file_put_contents("$dir/sig", base64_decode(strtr($s, '-_', '+/')));
    exec("openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -verify $dir/pub.pem -signature $dir/sig $dir/msg 2>&1", $out, $code);
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
    return $code === 0;
}

class TestAccount extends JaguarAccount
{
    protected function now(): int { return TestRegistry::$now; }

    protected function client(): DataHubClient
    {
        return new DataHubClient($this->ReadPropertyString('ClientId'), $this->ReadAttributeString('PrivateKey'), TestRegistry::$http, fn () => TestRegistry::$now);
    }
}

// account instance 1 with a client ID; linked (tokens stored, access token expired) unless $linked is false
function makeAccount(bool $linked = true, array $props = []): TestAccount
{
    TestRegistry::$http ??= fakeHttp();
    $a = new TestAccount(1);
    $a->Create();
    $a->attributes['PrivateKey'] = testCredentials()['private_key'];
    $a->attributes['Certificate'] = testCredentials()['certificate'];
    $a->properties = array_merge($a->properties, ['ClientId' => 'dsas-test'], $props);
    if ($linked) {
        $a->attributes['Tokens'] = json_encode(['access_token' => accessToken('old'), 'refresh_token' => 'ref0', 'expires' => 0, 'vehicles' => [VIN], 'client_id' => $a->properties['ClientId']]);
    }
    $a->ApplyChanges();
    return $a;
}

// vehicle instance on an account (a linked one unless given; null = no account)
class TestJaguar extends Jaguar
{
    protected function now(): int { return TestRegistry::$now; }
}

function makeJaguar(array $props = [], $account = false, int $id = 2): TestJaguar
{
    $m = new TestJaguar($id);
    $m->Create();
    $m->testParent = $account === false ? makeAccount() : $account;
    if ($m->testParent !== null) {
        $m->testParent->testChildren[] = $m;
    }
    $m->properties = array_merge($m->properties, $props);
    $m->ApplyChanges();
    return $m;
}


// text of the popup an action opened; an echo would show up in Symcon as a PHP warning with file and line
function popup(IPSModule $m, callable $action): string
{
    $m->formUpdates = [];
    ob_start();
    $action();
    $echo = (string) ob_get_clean();
    check($echo === '', 'echoed: ' . $echo);
    $caption = '';
    $visible = false;
    foreach ($m->formUpdates as [$field, $parameter, $value]) {
        if ($field === 'MessageText' && $parameter === 'caption') {
            $caption = $value;
        }
        if ($field === 'Message' && $parameter === 'visible') {
            $visible = $value;
        }
        if ($field === 'MessageLink' && $parameter === 'value') {
            $caption .= "\n" . $value;
        }
    }
    check($visible === true, 'no popup');
    return $caption;
}
