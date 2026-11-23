<?php

declare(strict_types=1);

// Turns the status snapshot of the JLR Data Hub into typed values. No dependency on Symcon.
class JlrStatus
{
    const BOOL = 0;
    const INT = 1;
    const FLOAT = 2;
    const STRING = 3;

    // signal (without "Vehicle.") => [ident, name, type, conversion, suffix, digits, profile]
    // Order is the order of the variables.
    const DEFINITIONS = [
        'TraveledDistance'                                   => ['Odometer', 'Odometer', self::FLOAT, 'km', ' km', 0, ''],
        'Powertrain.FuelSystem.RelativeLevel'                => ['FuelLevel', 'Fuel level', self::INT, 'int', ' %', 0, ''],
        'Powertrain.FuelSystem.AbsoluteLevel'                => ['FuelLitres', 'Fuel', self::FLOAT, 'float', ' l', 1, ''],
        'Powertrain.FuelSystem.Range'                        => ['FuelRange', 'Range', self::INT, 'int', ' km', 0, ''],
        'Body.Door.Lock.Status'                              => ['Locked', 'Locked', self::BOOL, 'true', '', 0, '~Lock'],
        'System.DriveCycle.Status'                           => ['VehicleState', 'Vehicle state', self::STRING, 'text', '', 0, ''],
        'Body.Security.Alarm.Mode'                           => ['Alarm', 'Alarm', self::STRING, 'text', '', 0, ''],
        'Cabin.Door.Row1.DriverSide.IsOpen'                  => ['DoorFrontLeft', 'Door front left', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row1.PassengerSide.IsOpen'               => ['DoorFrontRight', 'Door front right', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row2.DriverSide.IsOpen'                  => ['DoorRearLeft', 'Door rear left', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row2.PassengerSide.IsOpen'               => ['DoorRearRight', 'Door rear right', self::BOOL, 'open', '', 0, '~Window'],
        'Body.Hood.IsOpen'                                   => ['Hood', 'Hood', self::BOOL, 'open', '', 0, '~Window'],
        'Body.Trunk.Rear.IsOpen'                             => ['Boot', 'Boot', self::BOOL, 'open', '', 0, '~Window'],
        'Body.Trunk.Rear.IsLocked'                           => ['BootLocked', 'Boot locked', self::BOOL, 'true', '', 0, '~Lock'],
        'Body.Convertible.IsOpen'                            => ['Convertible', 'Convertible top', self::BOOL, 'open', '', 0, '~Window'],
        'Body.Roof.IsOpen'                                   => ['Sunroof', 'Sunroof', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row1.DriverSide.Window.Position'         => ['WindowFrontLeft', 'Window front left', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row1.PassengerSide.Window.Position'      => ['WindowFrontRight', 'Window front right', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row2.DriverSide.Window.Position'         => ['WindowRearLeft', 'Window rear left', self::BOOL, 'open', '', 0, '~Window'],
        'Cabin.Door.Row2.PassengerSide.Window.Position'      => ['WindowRearRight', 'Window rear right', self::BOOL, 'open', '', 0, '~Window'],
        'Chassis.Axle.Row1.Wheel.Left.Tire.Pressure'         => ['TyreFrontLeft', 'Tyre pressure front left', self::FLOAT, 'bar', ' bar', 2, ''],
        'Chassis.Axle.Row1.Wheel.Right.Tire.Pressure'        => ['TyreFrontRight', 'Tyre pressure front right', self::FLOAT, 'bar', ' bar', 2, ''],
        'Chassis.Axle.Row2.Wheel.Left.Tire.Pressure'         => ['TyreRearLeft', 'Tyre pressure rear left', self::FLOAT, 'bar', ' bar', 2, ''],
        'Chassis.Axle.Row2.Wheel.Right.Tire.Pressure'        => ['TyreRearRight', 'Tyre pressure rear right', self::FLOAT, 'bar', ' bar', 2, ''],
        'Chassis.Axle.Row1.Wheel.Left.Tire.IsPressureLow'    => ['TyreLowFrontLeft', 'Tyre pressure low front left', self::BOOL, 'warn', '', 0, '~Alert'],
        'Chassis.Axle.Row1.Wheel.Right.Tire.IsPressureLow'   => ['TyreLowFrontRight', 'Tyre pressure low front right', self::BOOL, 'warn', '', 0, '~Alert'],
        'Chassis.Axle.Row2.Wheel.Left.Tire.IsPressureLow'    => ['TyreLowRearLeft', 'Tyre pressure low rear left', self::BOOL, 'warn', '', 0, '~Alert'],
        'Chassis.Axle.Row2.Wheel.Right.Tire.IsPressureLow'   => ['TyreLowRearRight', 'Tyre pressure low rear right', self::BOOL, 'warn', '', 0, '~Alert'],
        'LowVoltageBattery.CurrentVoltage'                   => ['BatteryVoltage', '12 V battery', self::FLOAT, 'float', ' V', 1, ''],
        'Service.DistanceToService'                          => ['ServiceDistance', 'Distance to service', self::INT, 'int', ' km', 0, ''],
        'Body.Windshield.Front.WasherFluid.IsLevelLow'       => ['WasherFluidLow', 'Washer fluid low', self::BOOL, 'warn', '', 0, '~Alert'],
        'Chassis.Brake.FluidLevel.IsLevelLow'                => ['BrakeFluidLow', 'Brake fluid low', self::BOOL, 'warn', '', 0, '~Alert'],
        'Powertrain.CombustionEngine.EngineCoolant.IsLevelLow' => ['CoolantLow', 'Coolant low', self::BOOL, 'warn', '', 0, '~Alert'],
        'Powertrain.CombustionEngine.EngineOil.IsLevelLow'   => ['OilLow', 'Engine oil low', self::BOOL, 'warn', '', 0, '~Alert'],
    ];

    // dotted signal name (without "Vehicle.") => value as text, booleans as TRUE / FALSE
    public static function flatten(array $snapshot): array
    {
        $out = [];
        self::walk($snapshot['Vehicle'] ?? [], '', $out);
        unset($out['VehicleIdentification.VIN']);
        return $out;
    }

    private static function walk($node, string $prefix, array &$out): void
    {
        foreach (is_array($node) ? $node : [] as $key => $value) {
            $name = $prefix === '' ? (string) $key : "$prefix.$key";
            if (is_array($value)) {
                self::walk($value, $name, $out);
            } elseif ($value !== null) {
                $out[$name] = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value;
            }
        }
    }

    // ident => value, only for signals the vehicle delivers with a usable value
    public static function values(array $flat): array
    {
        $out = [];
        foreach (self::DEFINITIONS as $signal => $def) {
            if (!isset($flat[$signal])) {
                continue;
            }
            $value = self::convert($flat[$signal], $def[3]);
            if ($value !== null) {
                $out[$def[0]] = $value;
            }
        }
        return $out;
    }

    // ident => [name, type, suffix, digits, profile, position]
    public static function variables(): array
    {
        $out = [];
        $position = 10;
        foreach (self::DEFINITIONS as $def) {
            $out[$def[0]] = ['name' => $def[1], 'type' => $def[2], 'suffix' => $def[4], 'digits' => $def[5], 'profile' => $def[6], 'position' => $position++];
        }
        return $out;
    }

    // unix time of the snapshot (last report of the vehicle), 0 if unknown
    public static function lastUpdated(array $snapshot): int
    {
        $time = strtotime((string) ($snapshot['timestamp'] ?? ''));
        return $time === false ? 0 : $time;
    }

    // [latitude, longitude] of the location answer, null without a fix
    public static function position(array $location): ?array
    {
        $current = $location['Vehicle']['CurrentLocation'] ?? [];
        if (!is_numeric($current['Latitude'] ?? null) || !is_numeric($current['Longitude'] ?? null)) {
            return null;
        }
        return [(float) $current['Latitude'], (float) $current['Longitude']];
    }

    private static function convert(string $value, string $how)
    {
        $numeric = is_numeric($value);
        $upper = strtoupper($value);
        switch ($how) {
            case 'text':
                return $value;
            case 'true':
                // TRUE / FALSE, locks also LOCKED / UNLOCKED
                return in_array($upper, ['TRUE', 'FALSE', 'LOCKED', 'UNLOCKED'], true) ? in_array($upper, ['TRUE', 'LOCKED'], true) : null;
            case 'warn':
                // TRUE / FALSE or a word such as NORMAL; UNKNOWN says nothing
                return in_array($upper, ['', 'UNKNOWN'], true) ? null : !in_array($upper, ['FALSE', 'NORMAL', 'OK'], true);
            case 'open':
                // TRUE / FALSE or CLOSED, OPEN, AJAR, ...; UNKNOWN says nothing
                return in_array($upper, ['', 'UNKNOWN'], true) ? null : !in_array($upper, ['FALSE', 'CLOSED'], true);
            case 'int':
                return $numeric ? (int) round((float) $value) : null;
            case 'float':
                return $numeric ? (float) $value : null;
            case 'km':
                // delivered in metres
                return $numeric ? (float) $value / 1000 : null;
            case 'bar':
                // delivered in kPa (F-Type: 215 = 2.15 bar)
                return $numeric ? (float) $value / 100 : null;
        }
        return null;
    }
}
