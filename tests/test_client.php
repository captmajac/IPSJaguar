<?php

function testClient(?callable $http = null): DataHubClient
{
    return new DataHubClient('dsas-test', testCredentials()['private_key'], $http ?? fakeHttp(), fn () => 1000);
}

$tests['Client: certificate is self-signed for a year and fits the key'] = function () {
    $c = testCredentials();
    check(str_starts_with($c['certificate'], '-----BEGIN CERTIFICATE-----'), 'pem');
    $days = (DataHubClient::certificateExpiry($c['certificate']) - time()) / 86400;
    check($days > 364 && $days <= 365, "days $days");
    check(openssl_x509_check_private_key($c['certificate'], $c['private_key']), 'key fits');
};

$tests['Client: device login sends a signed client assertion'] = function () {
    $login = testClient()->startDeviceLogin();
    check($login['user_code'] === 'ABCD-EFGH' && $login['verification_uri'] === 'https://auth.example/device?user_code=ABCD-EFGH', 'login');
    check($login['expires'] === 1600 && $login['interval'] === 5, 'timing');
    $call = TestRegistry::$calls[0];
    check($call['url'] === 'https://auth.jlr-vcdp.com/realms/public/protocol/openid-connect/auth/device', $call['url']);
    $form = $call['form'];
    check($form['client_id'] === 'dsas-test' && $form['code_challenge_method'] === 'S256', 'form');
    check($form['code_challenge'] === rtrim(strtr(base64_encode(hash('sha256', $login['verifier'], true)), '+/', '-_'), '='), 'challenge');
    [$header, $claims] = jwtParts($form['client_assertion']);
    check($header === ['alg' => 'PS256', 'typ' => 'JWT'], 'header');
    check($claims['iss'] === 'dsas-test' && $claims['sub'] === 'dsas-test' && $claims['exp'] === 1300, 'claims');
    check($claims['aud'] === 'https://auth.jlr-vcdp.com/realms/public/protocol/openid-connect/token', 'aud');
    check(jwtVerified($form['client_assertion']), 'PS256 signature verified by openssl');
};

$tests['Client: poll is null while pending, tokens with VINs after approval'] = function () {
    $login = ['device_code' => 'dev', 'verifier' => 'ver'];
    check(testClient(fakeHttp(['/token' => [400, '{"error":"authorization_pending"}']]))->pollDeviceLogin($login) === null, 'pending');
    $tokens = testClient()->pollDeviceLogin($login);
    check($tokens['refresh_token'] === 'ref1' && $tokens['expires'] === 1300 && $tokens['vehicles'] === [VIN], json_encode($tokens));
    $call = end(TestRegistry::$calls);
    check($call['form']['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code' && $call['form']['code_verifier'] === 'ver', 'grant');
    check(jwtParts($call['headers']['DPoP'])[0]['typ'] === 'dpop+jwt', 'dpop on token request');
    try {
        testClient(fakeHttp(['/token' => [400, '{"error":"expired_token"}']]))->pollDeviceLogin($login);
        check(false, 'no exception');
    } catch (DataHubAuthException $e) {
    }
};

$tests['Client: refresh keeps the old refresh token if none comes, dead link is an auth exception'] = function () {
    $http = fakeHttp(['/token' => [200, json_encode(['access_token' => accessToken(), 'expires_in' => 300])]]);
    check(testClient($http)->refresh('old')['refresh_token'] === 'old', 'kept');
    try {
        testClient(fakeHttp(['/token' => [400, '{"error":"invalid_grant"}']]))->refresh('old');
        check(false, 'no exception');
    } catch (DataHubAuthException $e) {
    }
};

$tests['Client: status call carries the token and a DPoP proof bound to it'] = function () {
    $snapshot = testClient()->status(VIN, 'tok');
    check($snapshot['Vehicle']['TraveledDistance'] === 12345678, 'body');
    $call = TestRegistry::$calls[0];
    check($call['url'] === 'https://data.jlr-vcdp.com/v1/vehicles/' . VIN . '/status', $call['url']);
    check($call['headers']['Authorization'] === 'DPoP tok', 'authorization');
    [$header, $claims] = jwtParts($call['headers']['DPoP']);
    check($header['alg'] === 'PS256' && $header['jwk']['kty'] === 'RSA' && $header['jwk']['e'] === 'AQAB', 'jwk');
    check($claims['htm'] === 'GET' && $claims['htu'] === $call['url'] && $claims['iat'] === 1000, 'claims');
    check($claims['ath'] === rtrim(strtr(base64_encode(hash('sha256', 'tok', true)), '+/', '-_'), '='), 'ath');
    check(jwtVerified($call['headers']['DPoP']), 'signature');
};

$tests['Client: a DPoP nonce challenge is answered with one retry'] = function () {
    $http = fakeHttp(['/status' => function (array $call) {
        $nonce = jwtParts($call['headers']['DPoP'])[1]['nonce'] ?? '';
        return $nonce === 'n1' ? [200, statusJson()] : [401, '', ['dpop-nonce' => 'n1']];
    }]);
    testClient($http)->status(VIN, 'tok');
    check(count(TestRegistry::$calls) === 2, 'two calls');
};

$tests['Client: 403 is a forbidden exception with the reason'] = function () {
    try {
        testClient(fakeHttp(['/location' => [403, '{"detail":"Access to category is not permitted for this vehicle"}']]))->location(VIN, 'tok');
        check(false, 'no exception');
    } catch (DataHubForbiddenException $e) {
        check(str_contains($e->getMessage(), 'not permitted'), $e->getMessage());
    }
};

$tests['Status: nested signals are flattened and converted'] = function () {
    $flat = JlrStatus::flatten(json_decode(statusJson(), true));
    check($flat['Body.Door.Lock.Status'] === 'TRUE' && $flat['Body.Hood.IsOpen'] === 'FALSE' && !isset($flat['VehicleIdentification.VIN']), 'flat');
    $values = JlrStatus::values($flat);
    check($values['Odometer'] === 12345.678 && $values['TyreFrontLeft'] === 2.45 && $values['Hood'] === false, json_encode($values));
    check(!isset($values['DoorRearLeft']), 'UNKNOWN is no value');
};

// JLR answer every request without a User-Agent with 403 Forbidden (measured 2026-11-13)
$tests['Client: every request names a User-Agent'] = function () {
    $c = testClient();
    $c->startDeviceLogin();
    $c->status(VIN, 'tok');
    foreach (TestRegistry::$calls as $call) {
        check(($call['headers']['User-Agent'] ?? '') !== '', $call['url']);
    }
};

// real snapshot of a Jaguar F-Type, anonymized: no VIN, made-up time, mileage, fuel and service distance
$tests['Status: real F-Type snapshot'] = function () {
    $snapshot = json_decode(file_get_contents(__DIR__ . '/fixtures/status_ftype.json'), true);
    $values = JlrStatus::values(JlrStatus::flatten($snapshot));
    check($values['Odometer'] === 50000.0 && $values['FuelLevel'] === 50 && $values['FuelRange'] === 300, 'fuel');
    // tyre pressure comes in kPa: 215 is 2.15 bar
    check($values['TyreFrontLeft'] === 2.15 && $values['TyreRearRight'] === 2.09, 'tyres ' . $values['TyreFrontLeft']);
    check($values['TyreLowRearRight'] === true && $values['TyreLowFrontLeft'] === false, 'tyre warning');
    check($values['Locked'] === true && $values['DoorFrontLeft'] === false && $values['VehicleState'] === 'KEY_ON_ENGINE_OFF', 'state');
    check(JlrStatus::lastUpdated($snapshot) === strtotime('2026-01-01T12:00:00Z'), 'last report');
};

$tests['Status: boot lock as its own value, TRUE or LOCKED'] = function () {
    $values = JlrStatus::values(JlrStatus::flatten(json_decode(file_get_contents(__DIR__ . '/fixtures/status_ftype.json'), true)));
    check(($values['BootLocked'] ?? null) === true, 'F-Type delivers TRUE');
    check(JlrStatus::values(['Body.Trunk.Rear.IsLocked' => 'UNLOCKED'])['BootLocked'] === false, 'UNLOCKED');
    check(JlrStatus::values(['Body.Trunk.Rear.IsLocked' => 'LOCKED'])['BootLocked'] === true, 'LOCKED');
};
