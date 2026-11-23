<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DataHubClient.php';

// Link to the JLR Data Hub (official API for owners): key pair, client ID and approval.
// The vehicle instances read through it: Jaguar::api -> SendDataToParent -> ForwardData.
class JaguarAccount extends IPSModule
{
    const STATUS_NOT_CONFIGURED = 201;
    const STATUS_NOT_LINKED = 202;
    const STATUS_WAITING = 204;
    const STATUS_NO_KEY = 205;
    const TOKEN_MARGIN_S = 30;
    const TO_CHILDREN = '{17C78EC2-6CE0-4230-8E87-EEC2DB660FA7}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // client ID from data.jlr.com, e.g. dsas-0123456789ab
        $this->RegisterPropertyString('ClientId', '');

        // key pair of this instance; the certificate is uploaded at data.jlr.com
        $this->RegisterAttributeString('PrivateKey', '');
        $this->RegisterAttributeString('Certificate', '');
        // DataHubClient tokens plus 'client_id'
        $this->RegisterAttributeString('Tokens', '{}');
        // device login waiting for approval, see DataHubClient::startDeviceLogin
        $this->RegisterAttributeString('Login', '{}');

        $this->RegisterTimer('LinkPoll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "LinkPoll", "");');
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval('LinkPoll', 0);
        $this->WriteAttributeString('Login', '{}');
        if ($this->ReadAttributeString('PrivateKey') === '' && !$this->newCredentials()) {
            return;
        }
        if ($this->ReadPropertyString('ClientId') === '') {
            $this->SetStatus(self::STATUS_NOT_CONFIGURED);
        } elseif ($this->tokens() === []) {
            $this->SetStatus(self::STATUS_NOT_LINKED);
        } else {
            $this->SetStatus(102);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'Certificate') {
                $action['value'] = $this->ReadAttributeString('Certificate');
            }
            if (($action['name'] ?? '') === 'Info') {
                $expiry = DataHubClient::certificateExpiry($this->ReadAttributeString('Certificate'));
                $vins = $this->tokens()['vehicles'] ?? [];
                $action['caption'] = $expiry === 0 ? '' : sprintf($this->Translate("Certificate valid until %s"), date('d.m.Y', $expiry))
                    . ($vins === [] ? '' : ' – ' . sprintf($this->Translate("Linked vehicles: %s"), implode(', ', $vins)));
            }
        }
        return json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Link':
                $this->startLink();
                break;
            case 'LinkPoll':
                $this->pollLink();
                break;
            case 'NewCertificate':
                $this->SetTimerInterval('LinkPoll', 0);
                if (!$this->newCredentials()) {
                    echo $this->Translate("Could not create key and certificate, see the message log");
                    return;
                }
                $this->SetStatus($this->ReadPropertyString('ClientId') === '' ? self::STATUS_NOT_CONFIGURED : self::STATUS_NOT_LINKED);
                $this->UpdateFormField('Certificate', 'value', $this->ReadAttributeString('Certificate'));
                echo $this->Translate("New certificate created. Upload it at data.jlr.com and link again.");
                break;
            default:
                throw new Exception("Invalid Ident $Ident");
        }
    }

    // Request of a vehicle instance: Buffer {"method": "vehicles" | "status" | "location", "vin": ...}
    // Answer: {"data": ...} or {"error": message, "kind": "auth" | "forbidden" | "api"}
    public function ForwardData($JSONString)
    {
        $request = json_decode((string) (json_decode($JSONString, true)['Buffer'] ?? ''), true) ?: [];
        try {
            switch ($request['method'] ?? '') {
                case 'vehicles':
                    return json_encode(['data' => $this->tokens()['vehicles'] ?? []]);
                case 'status':
                case 'location':
                    return json_encode(['data' => $this->read($request['method'], (string) ($request['vin'] ?? ''))]);
            }
            throw new DataHubException('Unknown request');
        } catch (DataHubAuthException $e) {
            return json_encode(['error' => $e->getMessage(), 'kind' => 'auth']);
        } catch (DataHubForbiddenException $e) {
            return json_encode(['error' => $e->getMessage(), 'kind' => 'forbidden']);
        } catch (DataHubException $e) {
            return json_encode(['error' => $e->getMessage(), 'kind' => 'api']);
        }
    }

    protected function now(): int
    {
        return time();
    }

    protected function client(): DataHubClient
    {
        return new DataHubClient($this->ReadPropertyString('ClientId'), $this->ReadAttributeString('PrivateKey'));
    }

    // status or location of one vehicle ('' = first of the client), with a valid access token
    private function read(string $what, string $vin): array
    {
        // the refresh token is single use: two renewals at once would end the link
        if (!IPS_SemaphoreEnter('JaguarAccount' . $this->InstanceID, 30000)) {
            throw new DataHubException('Account instance is busy');
        }
        try {
            $tokens = $this->tokens();
            if ($tokens === []) {
                throw new DataHubAuthException('Not linked');
            }
            $client = $this->client();
            if ($tokens['expires'] - self::TOKEN_MARGIN_S <= $this->now()) {
                try {
                    $tokens = $client->refresh($tokens['refresh_token']);
                } catch (DataHubAuthException $e) {
                    $this->WriteAttributeString('Tokens', '{}');
                    $this->SetStatus(self::STATUS_NOT_LINKED);
                    $this->LogMessage($e->getMessage(), KL_ERROR);
                    throw $e;
                }
                $this->storeTokens($tokens);
            }
        } finally {
            IPS_SemaphoreLeave('JaguarAccount' . $this->InstanceID);
        }
        $vin = $vin !== '' ? $vin : ($tokens['vehicles'][0] ?? '');
        if ($vin === '') {
            throw new DataHubException('No vehicle selected on the client at data.jlr.com');
        }
        return $client->$what($vin, $tokens['access_token']);
    }

    protected function generateCredentials(): array
    {
        return DataHubClient::generateCredentials();
    }

    // a new key pair ends an existing link; on failure the status says so and the old pair stays
    private function newCredentials(): bool
    {
        try {
            $credentials = $this->generateCredentials();
        } catch (DataHubException $e) {
            $this->SetStatus(self::STATUS_NO_KEY);
            $this->LogMessage($e->getMessage(), KL_ERROR);
            return false;
        }
        $this->WriteAttributeString('PrivateKey', $credentials['private_key']);
        $this->WriteAttributeString('Certificate', $credentials['certificate']);
        $this->WriteAttributeString('Tokens', '{}');
        $this->WriteAttributeString('Login', '{}');
        return true;
    }

    // tokens of the configured client, [] when not linked
    private function tokens(): array
    {
        $tokens = json_decode($this->ReadAttributeString('Tokens'), true) ?: [];
        return ($tokens['client_id'] ?? '') === $this->ReadPropertyString('ClientId') && ($tokens['refresh_token'] ?? '') !== '' ? $tokens : [];
    }

    private function storeTokens(array $tokens): void
    {
        $this->WriteAttributeString('Tokens', json_encode($tokens + ['client_id' => $this->ReadPropertyString('ClientId')]));
    }

    private function startLink(): void
    {
        if ($this->ReadPropertyString('ClientId') === '') {
            echo $this->Translate("Please enter the client ID and apply first");
            return;
        }
        try {
            $login = $this->client()->startDeviceLogin();
        } catch (DataHubException $e) {
            $this->LogMessage($e->getMessage(), KL_ERROR);
            echo $this->Translate("JLR refused the client. Check the client ID and that this certificate was uploaded:") . "\n" . $e->getMessage();
            return;
        }
        $this->WriteAttributeString('Login', json_encode($login));
        $this->SetTimerInterval('LinkPoll', $login['interval'] * 1000);
        $this->SetStatus(self::STATUS_WAITING);
        echo $this->Translate("Open this link, sign in with your JLR account and approve:") . "\n\n" . $login['verification_uri']
            . "\n\n" . $this->Translate("Code:") . ' ' . $login['user_code']
            . "\n\n" . $this->Translate("The instance becomes active by itself after the approval.");
    }

    private function pollLink(): void
    {
        $login = json_decode($this->ReadAttributeString('Login'), true) ?: [];
        try {
            if ($login === [] || $login['expires'] < $this->now()) {
                throw new DataHubAuthException('The code expired before it was approved');
            }
            $tokens = $this->client()->pollDeviceLogin($login);
        } catch (DataHubAuthException $e) {
            $this->SetTimerInterval('LinkPoll', 0);
            $this->WriteAttributeString('Login', '{}');
            $this->SetStatus(self::STATUS_NOT_LINKED);
            $this->LogMessage($e->getMessage(), KL_ERROR);
            return;
        } catch (DataHubException $e) {
            // passing fault: the next poll tries again
            $this->SendDebug('LinkPoll', $e->getMessage(), 0);
            return;
        }
        if ($tokens === null) {
            return;
        }
        $this->SetTimerInterval('LinkPoll', 0);
        $this->WriteAttributeString('Login', '{}');
        $this->storeTokens($tokens);
        $this->SetStatus(102);
        // the vehicles read their first status right away
        $this->SendDataToChildren(json_encode(['DataID' => self::TO_CHILDREN, 'Buffer' => json_encode(['event' => 'linked'])]));
    }
}
