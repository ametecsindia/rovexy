<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Telephony\EslEventMapper;
use App\Services\CallService;
use App\Telephony\EslConnection;
use App\Telephony\TelephonyUnavailable;
use Illuminate\Console\Command;

/**
 * Long-running ESL event consumer (run under Supervisor/systemd, one per FreeSWITCH node).
 * Subscribes only to channel lifecycle + recording events; ignores channels without ametecs_* tags.
 */
final class TelephonyListen extends Command
{
    protected $signature = 'telephony:listen';
    protected $description = 'Consume FreeSWITCH ESL events and ingest call lifecycle (idempotent).';

    public function handle(CallService $calls): int
    {
        $cfg = config('ammp.telephony.freeswitch');
        $backoff = 1;
        while (true) {
            try {
                $c = new EslConnection($cfg['host'], (int) $cfg['port'], (string) $cfg['password'], 30.0);
                $c->connect();
                $c->subscribe('CHANNEL_PROGRESS CHANNEL_PROGRESS_MEDIA CHANNEL_ANSWER CHANNEL_HANGUP_COMPLETE RECORD_START RECORD_STOP');
                $this->info('Connected to FreeSWITCH ESL.');
                $backoff = 1;
                while (true) {
                    $event = $c->nextEvent();
                    if ($event === null) {
                        continue; // idle timeout; keep waiting
                    }
                    $mapped = EslEventMapper::map($event);
                    if ($mapped !== null) {
                        $result = $calls->ingest($mapped);
                        $this->line("{$mapped['type']} {$mapped['call_id']} → {$result}");
                    }
                }
            } catch (TelephonyUnavailable $e) {
                $this->error('ESL: ' . $e->getMessage() . " — reconnecting in {$backoff}s");
                sleep($backoff);
                $backoff = min(60, $backoff * 2);
            }
        }
    }
}
