<?php

// Checks the real JLR Data Hub with your account, without Symcon:
//   ../tools/php/php tests/probe.php
// First run: prints a certificate for data.jlr.com and asks for the client ID, then shows a link
// to approve. Key, tokens and the raw responses stay in tests/fixtures/raw/ (not in git).
// Later runs reuse the link and only read the status.

require_once __DIR__ . '/../libs/DataHubClient.php';
require_once __DIR__ . '/../libs/JlrStatus.php';

$dir = __DIR__ . '/../tests/fixtures/raw';
@mkdir($dir, 0700, true);
$read = fn (string $name) => is_file("$dir/$name") ? trim(file_get_contents("$dir/$name")) : '';
$write = function (string $name, string $content) use ($dir) {
    file_put_contents("$dir/$name", $content);
    chmod("$dir/$name", 0600);
};
$save = fn (string $name, array $data) => $write("$name.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

try {
    if ($read('key.pem') === '') {
        $credentials = DataHubClient::generateCredentials();
        $write('key.pem', $credentials['private_key']);
        $write('certificate.pem', $credentials['certificate']);
    }
    $clientId = $read('client-id.txt');
    if ($clientId === '') {
        echo "1. Open https://data.jlr.com, sign in and create a client:\n"
            . "   - credential type: device-based\n"
            . "   - select your vehicle and ALL data categories\n"
            . "   - paste this certificate completely:\n\n" . $read('certificate.pem') . "\n\n"
            . "2. Enter the client ID it shows (looks like dsas-0123456789ab): ";
        $clientId = trim((string) fgets(STDIN));
        if ($clientId === '') {
            exit(1);
        }
        $write('client-id.txt', $clientId);
    }
    $client = new DataHubClient($clientId, $read('key.pem'));

    $tokens = json_decode($read('tokens.json'), true) ?: [];
    if (($tokens['refresh_token'] ?? '') === '') {
        $login = $client->startDeviceLogin();
        echo "\n3. Open this link, sign in with your JLR account and approve:\n\n   " . $login['verification_uri']
            . "\n\n   Code: " . $login['user_code'] . "\n\n   Waiting for the approval ";
        $tokens = null;
        while ($tokens === null && time() < $login['expires']) {
            sleep($login['interval']);
            echo '.';
            $tokens = $client->pollDeviceLogin($login);
        }
        if ($tokens === null) {
            throw new DataHubException('The code expired before it was approved');
        }
        echo " ok\n";
    } else {
        echo "Renewing the token ... ";
        $tokens = $client->refresh($tokens['refresh_token']);
        echo "ok\n";
    }
    $save('tokens', $tokens);
    echo "Vehicles of the client: " . (implode(', ', $tokens['vehicles']) ?: '(none in the token)') . "\n";
    $vin = getenv('JLR_VIN') ?: ($tokens['vehicles'][0] ?? '');
    if ($vin === '') {
        throw new DataHubException('No vehicle selected on the client at data.jlr.com');
    }

    echo "Status of $vin ... ";
    $snapshot = $client->status($vin, $tokens['access_token']);
    $save('status', $snapshot);
    $flat = JlrStatus::flatten($snapshot);
    echo $flat === [] ? "empty: JLR have no report of the vehicle yet (drive or lock it once, then run again)\n" : count($flat) . " values, last report " . date("Y-m-d H:i", JlrStatus::lastUpdated($snapshot)) . "\n";

    foreach (['vehicle', 'location'] as $what) {
        echo ucfirst($what) . " ... ";
        try {
            $save($what, $client->$what($vin, $tokens['access_token']));
            echo "ok\n";
        } catch (DataHubException $e) {
            echo "failed: " . $e->getMessage() . "\n";
        }
    }

    echo "\n--- Variables the module would set ---\n";
    foreach (JlrStatus::values($flat) as $ident => $value) {
        printf("  %-18s %s\n", $ident, var_export($value, true));
    }
    echo "\n--- All other values of the vehicle ---\n";
    ksort($flat);
    foreach ($flat as $key => $value) {
        if (!isset(JlrStatus::DEFINITIONS[$key])) {
            printf("  %-60s %s\n", $key, $value);
        }
    }
    echo "\nRaw responses: " . realpath($dir) . "\n";
} catch (DataHubException $e) {
    echo "FAILED\n" . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
