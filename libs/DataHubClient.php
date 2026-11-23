<?php

declare(strict_types=1);

class DataHubException extends Exception {}
// the link is no longer accepted (client deleted, certificate replaced, consent withdrawn): link again
class DataHubAuthException extends DataHubException {}
// signed in, but this vehicle or data category is not permitted
class DataHubForbiddenException extends DataHubException {}

// JLR Data Hub, the official API for owners (https://developer.jlr.com). No dependency on Symcon.
// The owner creates a client at data.jlr.com with our certificate; token requests are signed with
// the private key (private_key_jwt, PS256) and every call carries a DPoP proof of the same key.
class DataHubClient
{
    const AUTH = 'https://auth.jlr-vcdp.com/realms/public/protocol/openid-connect';
    const API = 'https://data.jlr-vcdp.com';
    const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    const DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';
    const CERTIFICATE_DAYS = 365;
    const USER_AGENT = 'IPSJaguar/1.0';

    private string $clientId;
    private $key;
    private array $jwk;
    private $http;
    private $clock;
    private string $nonce = '';

    // $http: fn (string $method, string $url, array $headers, ?string $body): array{status: int, body: string, headers: array}
    //        response header names in lower case
    public function __construct(string $clientId, string $privateKey, ?callable $http = null, ?callable $clock = null)
    {
        $this->clientId = $clientId;
        $this->key = openssl_pkey_get_private($privateKey);
        $details = $this->key === false ? false : openssl_pkey_get_details($this->key);
        if ($details === false || !isset($details['rsa'])) {
            throw new DataHubException('The stored key is not an RSA key');
        }
        $this->jwk = ['kty' => 'RSA', 'n' => self::b64($details['rsa']['n']), 'e' => self::b64($details['rsa']['e'])];
        $this->http = $http ?? [self::class, 'curl'];
        $this->clock = $clock ?? 'time';
    }

    // ['private_key' => PEM, 'certificate' => PEM]: RSA key and the self-signed certificate the portal asks for
    public static function generateCredentials(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => 'IP-Symcon'], $key, ['digest_alg' => 'sha256']);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, self::CERTIFICATE_DAYS, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        if ($cert === false || !openssl_x509_export($cert, $certificate) || !openssl_pkey_export($key, $privateKey)) {
            throw new DataHubException('Could not create key and certificate: ' . openssl_error_string());
        }
        return ['private_key' => $privateKey, 'certificate' => $certificate];
    }

    // unix time the certificate stops being accepted, 0 if unreadable
    public static function certificateExpiry(string $certificate): int
    {
        $info = @openssl_x509_parse($certificate);
        return (int) ($info['validTo_time_t'] ?? 0);
    }

    // Asks for a code the owner approves in the browser.
    // ['device_code', 'user_code', 'verification_uri', 'interval', 'expires', 'verifier']
    public function startDeviceLogin(): array
    {
        $verifier = self::b64(random_bytes(48));
        [$status, $body] = $this->post(self::AUTH . '/auth/device', [
            'response_type'         => 'device_code',
            'code_challenge'        => self::b64(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], false);
        if (in_array($body['error'] ?? '', ['invalid_client', 'unauthorized_client'], true)) {
            throw new DataHubAuthException($body['error_description'] ?? $body['error']);
        }
        if ($status !== 200 || !isset($body['device_code'])) {
            throw new DataHubException("Device sign-in refused (HTTP $status " . ($body['error'] ?? '') . ')');
        }
        return [
            'device_code'      => (string) $body['device_code'],
            'user_code'        => (string) ($body['user_code'] ?? ''),
            'verification_uri' => (string) ($body['verification_uri_complete'] ?? $body['verification_uri'] ?? ''),
            'interval'         => max(1, (int) ($body['interval'] ?? 5)),
            'expires'          => ($this->clock)() + (int) ($body['expires_in'] ?? 600),
            'verifier'         => $verifier,
        ];
    }

    // One poll: tokens, or null while the owner has not approved yet
    public function pollDeviceLogin(array $login): ?array
    {
        [$status, $body] = $this->post(self::AUTH . '/token', [
            'grant_type'    => self::DEVICE_GRANT,
            'device_code'   => $login['device_code'],
            'code_verifier' => $login['verifier'],
        ], true);
        if ($status === 200) {
            return $this->tokens($body);
        }
        $error = $body['error'] ?? '';
        if (in_array($error, ['authorization_pending', 'slow_down'], true)) {
            return null;
        }
        if (in_array($error, ['access_denied', 'expired_token', 'invalid_client', 'unauthorized_client'], true)) {
            throw new DataHubAuthException($body['error_description'] ?? $error);
        }
        throw new DataHubException("Sign-in failed (HTTP $status $error)");
    }

    // New tokens from the refresh token. It is rotated on use: store the returned one.
    public function refresh(string $refreshToken): array
    {
        [$status, $body] = $this->post(self::AUTH . '/token', ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], true);
        if ($status === 200) {
            $tokens = $this->tokens($body);
            if ($tokens['refresh_token'] === '') {
                $tokens['refresh_token'] = $refreshToken;
            }
            return $tokens;
        }
        $error = $body['error'] ?? '';
        if (in_array($error, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true)) {
            throw new DataHubAuthException($body['error_description'] ?? $error);
        }
        throw new DataHubException("Renewal failed (HTTP $status $error)");
    }

    // status snapshot: ['timestamp' => ISO 8601, 'Vehicle' => nested signals]
    public function status(string $vin, string $accessToken): array
    {
        return $this->get('/v1/vehicles/' . rawurlencode($vin) . '/status', $accessToken);
    }

    public function location(string $vin, string $accessToken): array
    {
        return $this->get('/v1/vehicles/' . rawurlencode($vin) . '/location', $accessToken);
    }

    // brand, model, year, fuel type
    public function vehicle(string $vin, string $accessToken): array
    {
        return $this->get('/v1/vehicles/' . rawurlencode($vin), $accessToken);
    }

    private function get(string $path, string $accessToken): array
    {
        $url = self::API . $path;
        for ($attempt = 0; ; $attempt++) {
            $res = $this->request('GET', $url, [
                'Authorization' => 'DPoP ' . $accessToken,
                'DPoP'          => $this->proof('GET', $url, $accessToken),
                'Accept'        => 'application/json',
            ], null);
            if ($attempt === 0 && $res['status'] === 401 && $this->newNonce($res)) {
                continue;
            }
            break;
        }
        $body = json_decode($res['body'], true);
        $what = basename($path);
        if ($res['status'] === 200 && is_array($body)) {
            return $body;
        }
        if ($res['status'] === 403) {
            throw new DataHubForbiddenException("$what: " . (is_array($body) && isset($body['detail']) ? $body['detail'] : 'not permitted'));
        }
        throw new DataHubException("$what failed (HTTP " . $res['status'] . ')');
    }

    // [status, decoded body] of a form POST with the client assertion
    private function post(string $url, array $data, bool $dpop): array
    {
        for ($attempt = 0; ; $attempt++) {
            // the assertion is single use, so it is built for every attempt
            $form = $data + [
                'client_id'             => $this->clientId,
                'client_assertion_type' => self::ASSERTION_TYPE,
                'client_assertion'      => $this->jwt(['typ' => 'JWT'], [
                    'iss' => $this->clientId,
                    'sub' => $this->clientId,
                    'aud' => self::AUTH . '/token',
                    'jti' => self::uuid(),
                    'iat' => ($this->clock)(),
                    'exp' => ($this->clock)() + 300,
                ]),
            ];
            $headers = ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
            if ($dpop) {
                $headers['DPoP'] = $this->proof('POST', $url);
            }
            $res = $this->request('POST', $url, $headers, http_build_query($form));
            $body = json_decode($res['body'], true);
            $body = is_array($body) ? $body : [];
            if ($attempt === 0 && ($body['error'] ?? '') === 'use_dpop_nonce' && $this->newNonce($res)) {
                continue;
            }
            return [$res['status'], $body];
        }
    }

    // the server asks for its nonce in the proof: remember it, true if it is new
    private function newNonce(array $res): bool
    {
        $nonce = (string) ($res['headers']['dpop-nonce'] ?? '');
        if ($nonce === '' || $nonce === $this->nonce) {
            return false;
        }
        $this->nonce = $nonce;
        return true;
    }

    // access token, refresh token, expiry and the VINs the token covers
    private function tokens(array $body): array
    {
        if (!is_string($body['access_token'] ?? null)) {
            throw new DataHubException('Token response carried no access token');
        }
        $claims = json_decode((string) base64_decode(strtr(explode('.', $body['access_token'])[1] ?? '', '-_', '+/')), true);
        $vehicles = is_array($claims) && is_array($claims['vehicles'] ?? null) ? $claims['vehicles'] : [];
        return [
            'access_token'  => $body['access_token'],
            'refresh_token' => (string) ($body['refresh_token'] ?? ''),
            // measured at 300 s, the documentation says 3600
            'expires'       => ($this->clock)() + (int) ($body['expires_in'] ?? 300),
            'vehicles'      => array_values(array_filter($vehicles, 'is_string')),
        ];
    }

    private function proof(string $method, string $url, string $accessToken = ''): string
    {
        $claims = ['jti' => self::uuid(), 'htm' => $method, 'htu' => explode('?', $url, 2)[0], 'iat' => ($this->clock)()];
        if ($accessToken !== '') {
            $claims['ath'] = self::b64(hash('sha256', $accessToken, true));
        }
        if ($this->nonce !== '') {
            $claims['nonce'] = $this->nonce;
        }
        return $this->jwt(['typ' => 'dpop+jwt', 'jwk' => $this->jwk], $claims);
    }

    private function jwt(array $header, array $claims): string
    {
        $input = self::b64(json_encode(['alg' => 'PS256'] + $header)) . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $input . '.' . self::b64($this->signPss($input));
    }

    // RSASSA-PSS with SHA-256 and MGF1 (RFC 8017, 9.1.1); PHP's openssl_sign only knows PKCS#1 v1.5
    private function signPss(string $message): string
    {
        $bits = openssl_pkey_get_details($this->key)['bits'];
        $emBits = $bits - 1;
        $emLen = intdiv($emBits + 7, 8);
        $salt = random_bytes(32);
        $h = hash('sha256', str_repeat("\0", 8) . hash('sha256', $message, true) . $salt, true);
        $db = str_repeat("\0", $emLen - 66) . "\x01" . $salt;
        $mask = '';
        for ($i = 0; strlen($mask) < strlen($db); $i++) {
            $mask .= hash('sha256', $h . pack('N', $i), true);
        }
        $db ^= substr($mask, 0, strlen($db));
        $db[0] = chr(ord($db[0]) & (0xff >> (8 * $emLen - $emBits)));
        if (!openssl_private_encrypt($db . $h . "\xbc", $signature, $this->key, OPENSSL_NO_PADDING)) {
            throw new DataHubException('Signing failed: ' . openssl_error_string());
        }
        return $signature;
    }

    private function request(string $method, string $url, array $headers, ?string $body): array
    {
        // without a User-Agent JLR answer every request with 403 Forbidden
        $res = ($this->http)($method, $url, $headers + ['User-Agent' => self::USER_AGENT], $body);
        return ['status' => (int) ($res['status'] ?? 0), 'body' => (string) ($res['body'] ?? ''), 'headers' => (array) ($res['headers'] ?? [])];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new DataHubException('Could not reach JLR: ' . $error);
        }
        return ['status' => $status, 'body' => (string) $response, 'headers' => $responseHeaders];
    }
}
