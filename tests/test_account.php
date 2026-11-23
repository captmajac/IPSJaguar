<?php

// request of a vehicle instance, decoded answer
function ask(JaguarAccount $a, string $method, string $vin = ''): array
{
    return json_decode($a->ForwardData(json_encode(['DataID' => '{60CFED1E-F398-4D76-B7D3-F82F4A49F10C}', 'Buffer' => json_encode(['method' => $method, 'vin' => $vin])])), true);
}

$tests['Account: creates its own key and certificate'] = function () {
    $a = new TestAccount(3);
    $a->Create();
    $a->ApplyChanges();
    check(str_contains($a->attributes['Certificate'], 'BEGIN CERTIFICATE') && $a->attributes['PrivateKey'] !== '', 'credentials');
    check($a->currentStatus() === 201 && TestRegistry::$calls === [], 'inactive without client id');
    $form = json_decode($a->GetConfigurationForm(), true);
    check($form['actions'][0]['value'] === $a->attributes['Certificate'], 'certificate in the form');
    check(str_contains($form['actions'][1]['caption'], date('Y', time() + 364 * 86400)), $form['actions'][1]['caption']);
};

$tests['Account: with a client ID but no link it is not active and answers with an auth error'] = function () {
    $a = makeAccount(false);
    check($a->currentStatus() === 202, 'not linked');
    check(ask($a, 'status')['kind'] === 'auth' && TestRegistry::$calls === [], 'no request');
};

$tests['Account: linking shows link and code, polls and tells the vehicles after approval'] = function () {
    $approved = false;
    TestRegistry::$http = fakeHttp(['/token' => function () use (&$approved) {
        return $approved ? [200, tokenJson()] : [400, '{"error":"authorization_pending"}'];
    }]);
    $a = makeAccount(false);
    $m = makeJaguar([], $a);
    check($m->currentStatus() === 201 && TestRegistry::$calls === [], 'vehicle waits for the account');
    $out = popup($a, fn () => $a->RequestAction('Link', ''));
    check(str_contains($out, 'https://auth.example/device?user_code=ABCD-EFGH') && str_contains($out, 'ABCD-EFGH'), $out);
    check($a->currentStatus() === 204 && $a->timers['LinkPoll']['interval'] === 5000, 'waiting');
    $a->RequestAction('LinkPoll', '');
    check($a->currentStatus() === 204, 'still waiting');
    $approved = true;
    $a->RequestAction('LinkPoll', '');
    check($a->currentStatus() === 102 && $a->timers['LinkPoll']['interval'] === 0, 'active');
    check(json_decode($a->attributes['Tokens'], true)['refresh_token'] === 'ref1', 'tokens stored');
    check($m->currentStatus() === 102 && $m->value('FuelLevel') === 63, 'vehicle read its first status');
    check(str_contains(json_decode($a->GetConfigurationForm(), true)['actions'][1]['caption'], VIN), 'linked vehicles shown');
};

$tests['Account: an expired code ends the linking'] = function () {
    TestRegistry::$http = fakeHttp(['/token' => [400, '{"error":"authorization_pending"}']]);
    $a = makeAccount(false);
    popup($a, fn () => $a->RequestAction('Link', ''));
    TestRegistry::$now += 601;
    $a->RequestAction('LinkPoll', '');
    check($a->currentStatus() === 202 && $a->timers['LinkPoll']['interval'] === 0, 'ended');
};

$tests['Account: a refused client says so'] = function () {
    TestRegistry::$http = fakeHttp(['/auth/device' => [401, '{"error":"invalid_client","error_description":"Invalid client credentials"}']]);
    $a = makeAccount(false);
    check(str_contains(popup($a, fn () => $a->RequestAction('Link', '')), 'Invalid client credentials'), 'message');
    check($a->currentStatus() === 202, 'status');
};

$tests['Account: renews the token, stores the rotated one and reuses a valid one'] = function () {
    $a = makeAccount();
    check(ask($a, 'status')['data']['Vehicle']['TraveledDistance'] === 12345678, 'data');
    check(calls('/token')[0]['form']['refresh_token'] === 'ref0', 'renewed with the stored token');
    check(json_decode($a->attributes['Tokens'], true)['refresh_token'] === 'ref1', 'rotated token stored');
    check(calls('/status')[0]['headers']['Authorization'] === 'DPoP ' . accessToken(), 'new access token used');
    check(str_contains(calls('/status')[0]['url'], '/vehicles/' . VIN . '/status'), 'first vehicle of the client');
    ask($a, 'location');
    check(count(calls('/token')) === 1, 'valid token reused');
    TestRegistry::$now += 280;
    ask($a, 'status', 'SAJOTHER000000002');
    check(count(calls('/token')) === 2 && calls('/token')[1]['form']['refresh_token'] === 'ref1', 'renewed shortly before expiry');
    check(str_contains(calls('/status')[1]['url'], '/vehicles/SAJOTHER000000002/status'), 'given vin');
    check(ask($a, 'vehicles')['data'] === [VIN], 'vehicles');
};

$tests['Account: a dead link drops the tokens and asks to link again'] = function () {
    TestRegistry::$http = fakeHttp(['/token' => [400, '{"error":"invalid_grant"}']]);
    $a = makeAccount();
    check(ask($a, 'status')['kind'] === 'auth', 'auth error');
    check($a->currentStatus() === 202 && $a->attributes['Tokens'] === '{}' && count(TestRegistry::$log) === 1, 'not linked, logged');
};

$tests['Account: API and permission errors are passed on, the link stays'] = function () {
    TestRegistry::$http = fakeHttp(['/status' => [500, 'oops'], '/location' => [403, '{"detail":"Access to category is not permitted for this vehicle"}']]);
    $a = makeAccount();
    check(ask($a, 'status')['kind'] === 'api' && ask($a, 'location')['kind'] === 'forbidden', 'kinds');
    check($a->currentStatus() === 102 && json_decode($a->attributes['Tokens'], true)['refresh_token'] === 'ref1', 'still linked');
};

$tests['Account: new certificate ends the link'] = function () {
    $a = makeAccount();
    $old = $a->attributes['Certificate'];
    popup($a, fn () => $a->RequestAction('NewCertificate', ''));
    check($a->attributes['Certificate'] !== $old && $a->attributes['Tokens'] === '{}', 'new credentials');
    check($a->currentStatus() === 202 && $a->formUpdates[0] === ['Certificate', 'value', $a->attributes['Certificate']], 'not linked, form updated');
};

$tests['Account: without OpenSSL key generation the instance still opens and says why'] = function () {
    $a = new class(4) extends TestAccount {
        protected function generateCredentials(): array { throw new DataHubException('no openssl config'); }
    };
    $a->Create();
    $a->ApplyChanges();
    check($a->currentStatus() === 205 && count(TestRegistry::$log) === 1, 'status ' . $a->currentStatus());
    check(json_decode($a->GetConfigurationForm(), true)['actions'][1]['caption'] === '', 'no expiry date');
    check(str_contains(popup($a, fn () => $a->RequestAction('NewCertificate', '')), 'Could not create'), 'action says so');
};
