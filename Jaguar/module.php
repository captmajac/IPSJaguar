<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DataHubClient.php';
require_once __DIR__ . '/../libs/JlrStatus.php';
require_once __DIR__ . '/../libs/JlrTile.php';

// Status values of one Jaguar / Land Rover vehicle, read through a JaguarAccount instance (JLR Data Hub)
class Jaguar extends IPSModule
{
    const STATUS_NO_ACCOUNT = 201;
    const STATUS_API = 203;
    const POS_LAST_UPDATE = 1;
    const POS_LATITUDE = 80;
    const POS_LONGITUDE = 81;
    const ACCOUNT_MODULE = '{CBB7885A-80DA-46AC-8D93-8F1651E76565}';
    const TO_PARENT = '{60CFED1E-F398-4D76-B7D3-F82F4A49F10C}';
    const REFRESH_THROTTLE_S = 60;
    const JOURNEY_INTERVAL_S = 3600;
    const JOURNEY_REFUSED_INTERVAL_S = 86400;

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->ConnectParent(self::ACCOUNT_MODULE);

        // '' = first vehicle of the account's client
        $this->RegisterPropertyString('Vin', '');
        $this->RegisterPropertyBoolean('Position', true);
        $this->RegisterPropertyInteger('IntervalMinutes', 15);

        // signal => value of the last update, for "Show all values"
        $this->RegisterAttributeString('LastStatus', '{}');
        // date => odometer in km, for the statistics of the tile
        $this->RegisterAttributeString('Days', '{}');
        // model as JLR name it, once per vehicle
        $this->RegisterAttributeString('VehicleName', '');
        $this->RegisterAttributeInteger('LastFetch', 0);
        // journeys without positions, see JlrTile::journeys; state: unknown, ok or refused
        $this->RegisterAttributeString('Journeys', '[]');
        $this->RegisterAttributeString('JourneyState', 'unknown');
        $this->RegisterAttributeInteger('JourneyFetch', 0);
        $this->RegisterAttributeInteger('JourneyReport', 0);

        $this->SetVisualizationType(1);

        $this->RegisterTimer('Update', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "Update", "");');
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->MaintainVariable('LastUpdate', $this->Translate("Last report of the vehicle"), 1, '~UnixTimestamp', self::POS_LAST_UPDATE, true);
        $position = $this->ReadPropertyBoolean('Position');
        $this->MaintainVariable('Latitude', $this->Translate("Latitude"), 2, self::presentation('°', 5), self::POS_LATITUDE, $position);
        $this->MaintainVariable('Longitude', $this->Translate("Longitude"), 2, self::presentation('°', 5), self::POS_LONGITUDE, $position);

        // the timer keeps running without an account, so a link made later is picked up
        $this->SetTimerInterval('Update', max(5, $this->ReadPropertyInteger('IntervalMinutes')) * 60000);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->update();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'Vin') {
                $element['options'] = $this->vinOptions();
            }
        }
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP;
        $initial = json_encode(json_encode($this->tilePayload(), $flags), $flags);
        return file_get_contents(__DIR__ . '/module.html') . "\n<script>handleMessage($initial);</script>";
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // ApplyChanges before the kernel was ready did not fetch
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    // the account was linked: read right away
    public function ReceiveData($JSONString)
    {
        $this->update();
        return '';
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $this->update();
                break;
            case 'Refresh':
                // button of the tile
                if ($this->now() - $this->ReadAttributeInteger('LastFetch') >= self::REFRESH_THROTTLE_S) {
                    $this->update();
                } else {
                    $this->render();
                }
                break;
            case 'UpdateNow':
                $this->message($this->update() ? $this->Translate("Status updated") : $this->Translate("Update failed, see the message log"));
                break;
            case 'ShowValues':
                $this->showValues();
                break;
            default:
                throw new Exception("Invalid Ident $Ident");
        }
    }

    // request to the account instance, see JaguarAccount::ForwardData
    private function api(string $method)
    {
        if (!$this->HasActiveParent()) {
            throw new DataHubAuthException('The account instance is not linked');
        }
        $answer = json_decode((string) $this->SendDataToParent(json_encode([
            'DataID' => self::TO_PARENT,
            // Symcon wants the buffer as a string
            'Buffer' => json_encode(['method' => $method, 'vin' => $this->ReadPropertyString('Vin')]),
        ])), true);
        if (!is_array($answer)) {
            throw new DataHubException('No answer from the account instance');
        }
        if (isset($answer['error'])) {
            switch ($answer['kind'] ?? '') {
                case 'auth':
                    throw new DataHubAuthException($answer['error']);
                case 'forbidden':
                    throw new DataHubForbiddenException($answer['error']);
            }
            throw new DataHubException($answer['error']);
        }
        return $answer['data'] ?? [];
    }

    protected function now(): int
    {
        return time();
    }

    private function update(): bool
    {
        $this->WriteAttributeInteger('LastFetch', $this->now());
        $ok = $this->fetch();
        $this->render();
        return $ok;
    }

    private function render(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tilePayload()));
    }

    private function tilePayload(): array
    {
        $values = JlrStatus::values(json_decode($this->ReadAttributeString('LastStatus'), true) ?: []);
        $position = null;
        if ($this->ReadPropertyBoolean('Position') && @$this->GetIDForIdent('Latitude') !== false) {
            $latitude = (float) $this->GetValue('Latitude');
            $longitude = (float) $this->GetValue('Longitude');
            $position = $latitude === 0.0 && $longitude === 0.0 ? null : [$latitude, $longitude];
        }
        return JlrTile::payload($values, [
            'name'       => $this->ReadAttributeString('VehicleName'),
            'online'     => $this->GetStatus() === 102,
            'lastReport' => @$this->GetIDForIdent('LastUpdate') === false ? 0 : (int) $this->GetValue('LastUpdate'),
            'position'   => $position,
            'journeys'   => json_decode($this->ReadAttributeString('Journeys'), true) ?: [],
            'journeyState' => $this->ReadAttributeString('JourneyState'),
        ], json_decode($this->ReadAttributeString('Days'), true) ?: [], $this->now());
    }

    // model for the head of the tile; without it the tile just shows no name
    private function loadName(): void
    {
        if ($this->ReadAttributeString('VehicleName') !== '') {
            return;
        }
        try {
            $model = (string) ($this->api('vehicle')['Vehicle']['VehicleIdentification']['Model'] ?? '');
            $this->WriteAttributeString('VehicleName', mb_strtoupper($model));
        } catch (DataHubException $e) {
            $this->SendDebug('Vehicle', $e->getMessage(), 0);
        }
    }

    private function fetch(): bool
    {
        try {
            $snapshot = $this->api('status');
            $this->SendDebug('Status', json_encode($snapshot), 0);

            $flat = JlrStatus::flatten($snapshot);
            $this->WriteAttributeString('LastStatus', json_encode($flat));
            $variables = JlrStatus::variables();
            foreach (JlrStatus::values($flat) as $ident => $value) {
                $v = $variables[$ident];
                // created once; name, position and presentation then belong to the user
                if (@$this->GetIDForIdent($ident) === false) {
                    $profile = in_array($v['type'], [JlrStatus::BOOL, JlrStatus::STRING], true) ? $v['profile'] : self::presentation($v['suffix'], $v['digits']);
                    $this->MaintainVariable($ident, $this->Translate($v['name']), $v['type'], $profile, $v['position'], true);
                }
                $this->SetValue($ident, $value);
            }
            // an empty snapshot is stamped with the current time: the vehicle has not reported yet
            if ($flat !== []) {
                $reported = JlrStatus::lastUpdated($snapshot);
                $this->SetValue('LastUpdate', $reported);
                $odometer = JlrStatus::values($flat)['Odometer'] ?? null;
                if ($odometer !== null && $reported > 0) {
                    $days = json_decode($this->ReadAttributeString('Days'), true) ?: [];
                    $this->WriteAttributeString('Days', json_encode(JlrTile::recordDay($days, $odometer, $reported)));
                }
            }
            $this->loadName();

            if ($this->ReadPropertyBoolean('Position')) {
                try {
                    $position = JlrStatus::position($this->api('location'));
                    if ($position !== null) {
                        $this->SetValue('Latitude', $position[0]);
                        $this->SetValue('Longitude', $position[1]);
                    }
                } catch (DataHubForbiddenException $e) {
                    // refused until the vehicle is declared at data.jlr.com; the status is still good
                    $this->SendDebug('Position', $e->getMessage(), 0);
                }
            }
            $this->readJourneys();
            $this->SetStatus(102);
            return true;
        } catch (DataHubAuthException $e) {
            // the account instance says why in its own status and log
            $this->SetStatus(self::STATUS_NO_ACCOUNT);
        } catch (DataHubException $e) {
            $this->SetStatus(self::STATUS_API);
            $this->LogMessage($e->getMessage(), KL_WARNING);
        }
        return false;
    }

    // an extra: whatever goes wrong here leaves the status of the instance alone
    private function readJourneys(): void
    {
        $age = $this->now() - $this->ReadAttributeInteger('JourneyFetch');
        $report = (int) $this->GetValue('LastUpdate');
        $due = $this->ReadAttributeString('JourneyState') === 'refused'
            ? $age >= self::JOURNEY_REFUSED_INTERVAL_S
            : ($report !== $this->ReadAttributeInteger('JourneyReport') || $age >= self::JOURNEY_INTERVAL_S);
        if (!$due) {
            return;
        }
        $this->WriteAttributeInteger('JourneyFetch', $this->now());
        $this->WriteAttributeInteger('JourneyReport', $report);
        try {
            $this->WriteAttributeString('Journeys', json_encode(JlrTile::journeys($this->api('journeys'))));
            $this->WriteAttributeString('JourneyState', 'ok');
        } catch (DataHubForbiddenException $e) {
            $this->WriteAttributeString('JourneyState', 'refused');
            $this->SendDebug('Journeys', $e->getMessage(), 0);
        } catch (DataHubException $e) {
            $this->SendDebug('Journeys', $e->getMessage(), 0);
        }
    }

    private function vinOptions(): array
    {
        try {
            $vins = $this->api('vehicles');
        } catch (DataHubException $e) {
            $vins = [];
        }
        $current = $this->ReadPropertyString('Vin');
        if ($current !== '' && !in_array($current, $vins, true)) {
            $vins[] = $current;
        }
        $options = [['caption' => $this->Translate("(first vehicle of the account)"), 'value' => '']];
        foreach ($vins as $vin) {
            $options[] = ['caption' => $vin, 'value' => $vin];
        }
        return $options;
    }

    private function showValues(): void
    {
        $flat = json_decode($this->ReadAttributeString('LastStatus'), true) ?: [];
        if ($flat === []) {
            $this->message($this->Translate("No status loaded yet"));
            return;
        }
        ksort($flat);
        $lines = [];
        foreach ($flat as $key => $value) {
            $lines[] = "$key: $value";
        }
        $this->message(implode("\n", $lines));
    }

    // popup in the configuration form; an echo from RequestAction shows up as a PHP warning with file and line
    private function message(string $text): void
    {
        $this->UpdateFormField('MessageText', 'caption', $text);
        $this->UpdateFormField('Message', 'visible', true);
    }

    private static function presentation(string $suffix, int $digits): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits];
    }
}
