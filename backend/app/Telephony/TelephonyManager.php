<?php

declare(strict_types=1);

namespace App\Telephony;

final class TelephonyManager
{
    private ?TelephonyAdapter $adapter = null;

    public function adapter(): TelephonyAdapter
    {
        return $this->adapter ??= match (config('ammp.telephony.driver')) {
            'freeswitch' => new FreeSwitchAdapter(config('ammp.telephony.freeswitch')),
            'exotel' => new ExotelAdapter(config('ammp.telephony.exotel')),
            'simulator' => app()->environment('local', 'testing')
                ? new SimulatorAdapter()
                : throw new \RuntimeException('TELEPHONY_DRIVER=simulator is only allowed in local/testing environments.'),
            default => throw new \RuntimeException('Unknown TELEPHONY_DRIVER.'),
        };
    }

    public function swap(TelephonyAdapter $adapter): void
    {
        $this->adapter = $adapter;
    }
}
