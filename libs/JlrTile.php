<?php

declare(strict_types=1);

// Data of the tile visualization, from the values of JlrStatus. No dependency on Symcon.
// Texts are German on purpose, like the tile itself.
class JlrTile
{
    const HISTORY_DAYS = 32;
    const CHART_DAYS = 30;

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
            'fuel'        => $values['FuelLevel'] ?? null,
            'range'       => $values['FuelRange'] ?? null,
            'odometer'    => $values['Odometer'] ?? null,
            'voltage'     => $values['BatteryVoltage'] ?? null,
            'service'     => $values['ServiceDistance'] ?? null,
            'locked'      => $values['Locked'] ?? null,
            'bootLocked'  => $values['BootLocked'] ?? null,
            'doors'       => self::openOnes($values, 'Door'),
            'windows'     => self::openOnes($values, 'Window'),
            'boot'        => $values['Boot'] ?? null,
            'hood'        => $values['Hood'] ?? null,
            'convertible' => $values['Convertible'] ?? null,
            'tyres'       => $tyres,
            'warnings'    => $warnings,
            'position'    => $position === null ? null : ['lat' => $position[0], 'lon' => $position[1]],
            'stats'       => self::statistics($days, $now),
        ];
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
