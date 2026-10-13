<?php

declare(strict_types=1);

namespace App\Domain\Policy;

final class Capability
{
    public const SUPPORTED = 'supported';
    public const UNSUPPORTED = 'unsupported';
    public const REQUIRES_SETUP = 'requires_setup';

    /**
     * @param array{management:string, sdk:int, usb_data_signaling?:bool} $device
     * @return array{status:string, reason:?string}
     */
    public static function evaluate(Control $control, array $device, mixed $value = null): array
    {
        if ($device['sdk'] < $control->minSdk) {
            return ['status' => self::UNSUPPORTED, 'reason' => "Requires Android API {$control->minSdk}+ (device is API {$device['sdk']})."];
        }
        if ($control->scope === 'device' && $device['management'] !== 'amapi') {
            return ['status' => self::REQUIRES_SETUP, 'reason' => 'Device-level control: enrol the device in Android Management (fully managed).'];
        }
        if ($control->key === 'device.usb_data' && $value === 'no_data' && !($device['usb_data_signaling'] ?? false)) {
            return ['status' => self::REQUIRES_SETUP, 'reason' => $control->setupNote];
        }
        if ($control->key === 'location.background' && $value === true && $device['management'] !== 'amapi') {
            return ['status' => self::REQUIRES_SETUP, 'reason' => $control->setupNote];
        }
        return ['status' => self::SUPPORTED, 'reason' => null];
    }

    /** @return array<string,array{status:string,reason:?string,scope:string}> */
    public static function matrix(array $effectiveValues, array $device): array
    {
        $out = [];
        foreach (PolicyCatalog::all() as $key => $control) {
            $out[$key] = self::evaluate($control, $device, $effectiveValues[$key] ?? null) + ['scope' => $control->scope];
        }
        return $out;
    }
}
