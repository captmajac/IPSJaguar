<?php

// Shows what the stored link is allowed to do: the claims of the access token (no secrets)
// and the raw answer of every endpoint.  ../tools/php/php tests/diagnose.php

require_once __DIR__ . '/../libs/DataHubClient.php';

$dir = __DIR__ . '/../tests/fixtures/raw';
$read = fn (string $name) => trim((string) @file_get_contents("$dir/$name"));
$answers = [];
$curl = function (string $method, string $url, array $headers, ?string $body) use (&$answers): array {
    $lines = [];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $lines, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => true]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $responseHeaders = [];
    foreach (explode("\r\n", substr($response, 0, $size)) as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    $answers[] = [$method, $url, $status, $responseHeaders, substr($response, $size)];
    return ['status' => $status, 'body' => substr($response, $size), 'headers' => $responseHeaders];
};

$client = new DataHubClient($read('client-id.txt'), $read('key.pem'), $curl);
$tokens = json_decode($read('tokens.json'), true);
$tokens = $client->refresh($tokens['refresh_token']);
file_put_contents("$dir/tokens.json", json_encode($tokens, JSON_PRETTY_PRINT));

$claims = json_decode(base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true);
unset($claims['jti'], $claims['sid'], $claims['cnf'], $claims['sub'], $claims['email'], $claims['name'], $claims['preferred_username'], $claims['given_name'], $claims['family_name']);
echo "--- Token claims ---\n" . json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$vin = $tokens['vehicles'][0] ?? '';
$answers = [];
foreach (['vehicle', 'status', 'location'] as $what) {
    try {
        $client->$what($vin, $tokens['access_token']);
    } catch (DataHubException $e) {
    }
}
foreach ($answers as [$method, $url, $status, $headers, $body]) {
    echo "\n--- $method " . str_replace($vin, '{vin}', parse_url($url, PHP_URL_PATH)) . " -> HTTP $status ---\n";
    foreach (['www-authenticate', 'content-type', 'dpop-nonce'] as $h) {
        if (isset($headers[$h])) {
            echo "$h: {$headers[$h]}\n";
        }
    }
    echo mb_substr(str_replace($vin, '{vin}', $body), 0, 1500) . "\n";
}
