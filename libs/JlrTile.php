<?php

declare(strict_types=1);

// Data of the tile visualization, from the values of JlrStatus. No dependency on Symcon.
// Texts are German on purpose, like the tile itself.
class JlrTile
{
    const HISTORY_DAYS = 32;
    const CHART_DAYS = 30;
    const MAX_JOURNEYS = 30;
    // JLR send no sleep signal: the tile infers it from the age of the last report
    const RESTING_AFTER_DAYS = 4;

    const SIDES = ['FrontLeft' => 'vorne links', 'FrontRight' => 'vorne rechts', 'RearLeft' => 'hinten links', 'RearRight' => 'hinten rechts'];
    const WARNINGS = [
        'WasherFluidLow' => 'Wischwasser niedrig',
        'BrakeFluidLow'  => 'Bremsflüssigkeit niedrig',
        'CoolantLow'     => 'Kühlmittel niedrig',
        'OilLow'         => 'Motoröl niedrig',
    ];

    // history: date => odometer in km at the last report of that day
    public static function recordDay(array $days, float $km, int $time): array
    {
        $days[date('Y-m-d', $time)] = $km;
        ksort($days);
        return array_slice($days, -self::HISTORY_DAYS, null, true);
    }

    // km per day of the last 30 days; the distance since the previous report counts on the day of the report
    public static function statistics(array $days, int $now): array
    {
        ksort($days);
        $driven = [];
        $previous = null;
        foreach ($days as $date => $km) {
            if ($previous !== null) {
                $driven[$date] = max(0, (int) round($km - $previous));
            }
            $previous = $km;
        }
        $chart = [];
        $today = new DateTimeImmutable(date('Y-m-d', $now));
        for ($i = self::CHART_DAYS - 1; $i >= 0; $i--) {
            $date = $today->modify("-$i days")->format('Y-m-d');
            $chart[] = ['date' => $date, 'km' => $driven[$date] ?? 0];
        }
        $km = array_column($chart, 'km');
        $total = array_sum($km);
        $drivingDays = count(array_filter($km));
        return [
            'days'        => $chart,
            'total'       => $total,
            'drivingDays' => $drivingDays,
            'perDay'      => $drivingDays === 0 ? 0 : (int) round($total / $drivingDays),
        ];
    }

    // $info: name, online, lastReport, position ([lat, lon] or null)
    public static function payload(array $values, array $info, array $days, int $now): array
    {
        $tyres = [];
        foreach (self::SIDES as $side => $label) {
            if (isset($values["Tyre$side"])) {
                $tyres[] = ['label' => ucfirst($label), 'bar' => $values["Tyre$side"], 'low' => $values["TyreLow$side"] ?? false];
            }
        }
        $warnings = [];
        foreach (self::WARNINGS as $ident => $text) {
            if (($values[$ident] ?? false) === true) {
                $warnings[] = $text;
            }
        }
        $position = $info['position'];
        return [
            'name'        => $info['name'],
            'online'      => $info['online'],
            'lastReport'  => $info['lastReport'],
            'restingDays' => self::restingDays((int) $info['lastReport'], $now),
            'fuel'        => $values['FuelLevel'] ?? null,
            'range'       => $values['FuelRange'] ?? null,
            'fuelState'   => self::fuelState($values['FuelLevel'] ?? null, $values['FuelRange'] ?? null),
            'odometer'    => $values['Odometer'] ?? null,
            'voltage'     => $values['BatteryVoltage'] ?? null,
            'service'     => $values['ServiceDistance'] ?? null,
            'locked'      => $values['Locked'] ?? null,
            'bootLocked'  => $values['BootLocked'] ?? null,
            // locking after this report is often not sent, so "unlocked" may be out of date
            'ignitionOn'  => strpos((string) ($values['VehicleState'] ?? ''), 'KEY_ON') === 0,
            'doors'       => self::openOnes($values, 'Door'),
            'windows'     => self::openOnes($values, 'Window'),
            'boot'        => $values['Boot'] ?? null,
            'hood'        => $values['Hood'] ?? null,
            'convertible' => $values['Convertible'] ?? null,
            'tyres'       => $tyres,
            'warnings'    => $warnings,
            'position'    => $position === null ? null : ['lat' => $position[0], 'lon' => $position[1]],
            'stats'       => self::statistics($days, $now),
            'journeys'    => $info['journeys'] ?? [],
            'journeyState' => $info['journeyState'] ?? 'unknown',
            'journeyError' => $info['journeyError'] ?? '',
        ];
    }

    // journeys of the API, newest first; start and end position are left out on purpose
    public static function journeys(array $answer): array
    {
        $out = [];
        foreach ($answer['items'] ?? [] as $item) {
            $start = strtotime((string) ($item['startTime'] ?? ''));
            $end = strtotime((string) ($item['endTime'] ?? ''));
            if ($start === false || $end === false) {
                continue;
            }
            $out[$start] = [
                'start'   => $start,
                'end'     => $end,
                'km'      => self::measure($item['distance'] ?? null, ['km' => 1.0, 'm' => 0.001, 'mi' => 1.609344]),
                'minutes' => is_numeric($item['duration'] ?? null) ? (int) round($item['duration'] / 60) : (int) round(($end - $start) / 60),
                'speed'   => self::measure($item['averageSpeed'] ?? null, ['km/h' => 1.0, 'm/s' => 3.6, 'mph' => 1.609344]),
            ];
        }
        krsort($out);
        return array_slice(array_values($out), 0, self::MAX_JOURNEYS);
    }

    // whole days without a report once the vehicle counts as resting, else null
    private static function restingDays(int $lastReport, int $now): ?int
    {
        if ($lastReport <= 0) {
            return null;
        }
        $days = (int) floor(($now - $lastReport) / 86400);
        return $days >= self::RESTING_AFTER_DAYS ? $days : null;
    }

    // short text for the tile, without details of the request
    public static function journeyError(string $message): string
    {
        return preg_match('/HTTP \d+/', $message, $code) ? 'Abfrage fehlgeschlagen (' . $code[0] . ')' : 'Abfrage fehlgeschlagen';
    }

    // value in the wanted unit, null without a value or with an unknown unit
    private static function measure($field, array $factors): ?float
    {
        $factor = $factors[strtolower((string) ($field['unit'] ?? ''))] ?? null;
        return $factor === null || !is_numeric($field['value'] ?? null) ? null : round((float) $field['value'] * $factor, 1);
    }

    // JLR send no fuel warning, so the tile rates level and range itself
    private static function fuelState(?int $level, ?int $range): string
    {
        if (($level !== null && $level <= 10) || ($range !== null && $range <= 50)) {
            return 'critical';
        }
        if (($level !== null && $level <= 20) || ($range !== null && $range <= 100)) {
            return 'low';
        }
        return 'ok';
    }

    // names of the open doors or windows, null if the vehicle reports none of them
    private static function openOnes(array $values, string $prefix): ?array
    {
        $open = [];
        $known = false;
        foreach (self::SIDES as $side => $label) {
            if (isset($values[$prefix . $side])) {
                $known = true;
                if ($values[$prefix . $side] === true) {
                    $open[] = $label;
                }
            }
        }
        return $known ? $open : null;
    }
}
