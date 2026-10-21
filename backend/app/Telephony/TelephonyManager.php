<?php

declare(strict_types=1);

namespace App\Telephony;

final class TelephonyManager
{
    private ?TelephonyAdapter $adapter = null;
    private ?TelephonyAdapter $swapped = null;

    public function adapter(): TelephonyAdapter
    {
        if ($this->swapped) {
            return $this->swapped;
        }
        // Click2Call switched on by the company admin (Calling → Configuration) overrides the server's driver.
        if ($cfg = Click2CallSettings::effective()) {
            return new Click2CallAdapter($cfg);   // ponytail: built per use (cheap); the company can change it any time
        }
        return $this->adapter ??= match (config('ammp.telephony.driver')) {
            'freeswitch' => new FreeSwitchAdapter(config('ammp.telephony.freeswitch')),
            'exotel' => new ExotelAdapter(config('ammp.telephony.exotel')),
            'click2call' => new Click2CallAdapter(config('ammp.telephony.click2call')),
            'simulator' => app()->environment('local', 'testing')
                ? new SimulatorAdapter()
                : throw new \RuntimeException('TELEPHONY_DRIVER=simulator is only allowed in local/testing environments.'),
            default => throw new \RuntimeException('Unknown TELEPHONY_DRIVER.'),
        };
    }

    public function swap(?TelephonyAdapter $adapter): void
    {
        $this->swapped = $adapter;
    }
}
