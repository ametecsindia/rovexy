<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Domain\Telephony\OriginateCommand;
use App\Models\Call;

/**
 * Originates calls on an existing FreeSWITCH deployment via ESL. Does not change any FreeSWITCH configuration.
 * Requirements on the FreeSWITCH side are documented in docs/TELEPHONY.md (gateway, ESL ACL, recordings path).
 */
final class FreeSwitchAdapter implements TelephonyAdapter
{
    public function __construct(private readonly array $cfg)
    {
        foreach (['password', 'gateway', 'caller_id'] as $k) {
            if (empty($cfg[$k])) {
                throw new \RuntimeException("FreeSWITCH adapter misconfigured: missing {$k}.");
            }
        }
    }

    public function name(): string
    {
        return 'freeswitch';
    }

    public function originate(Call $call, array $p): void
    {
        $cmd = OriginateCommand::build($p + [
            'gateway' => $this->cfg['gateway'],
            'caller_id' => $this->cfg['caller_id'],
            'sip_domain' => $this->cfg['sip_domain'] ?? '',
        ]);
        $this->run($cmd);
    }

    public function hangup(Call $call, string $cause): void
    {
        $this->run(OriginateCommand::hangup($call->uuid, $cause));
    }

    public function health(): array
    {
        try {
            $c = $this->connection();
            $r = $c->send('api status');
            $c->close();
            return ['ok' => true, 'detail' => strtok($r['body'] ?? 'UP', "\n") ?: 'UP'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private function run(string $cmd): void
    {
        $c = $this->connection();
        try {
            $r = $c->send($cmd);
            if (!str_starts_with($r['headers']['Reply-Text'] ?? '', '+OK')) {
                throw new TelephonyUnavailable('FreeSWITCH rejected command: ' . ($r['headers']['Reply-Text'] ?? 'no reply'));
            }
        } finally {
            $c->close();
        }
    }

    private function connection(): EslConnection
    {
        $c = new EslConnection($this->cfg['host'], (int) $this->cfg['port'], (string) $this->cfg['password']);
        $c->connect();
        return $c;
    }
}
