<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Domain\Telephony\EslFrameParser;

/** Minimal inbound Event Socket client (TCP to FreeSWITCH mod_event_socket, ACL-restricted to the app servers). */
final class EslConnection
{
    /** @var resource|null */
    private $sock = null;
    private EslFrameParser $parser;
    /** @var list<array> */
    private array $pending = [];

    public function __construct(private readonly string $host, private readonly int $port, private readonly string $password, private readonly float $timeout = 3.0)
    {
        $this->parser = new EslFrameParser();
    }

    public function connect(): void
    {
        $errno = 0;
        $err = '';
        $sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $err, $this->timeout);
        if ($sock === false) {
            throw new TelephonyUnavailable("ESL connect failed: {$err}");
        }
        stream_set_timeout($sock, (int) ceil($this->timeout));
        $this->sock = $sock;
        $this->expect('auth/request');
        $reply = $this->send("auth {$this->password}");
        if (!str_starts_with($reply['headers']['Reply-Text'] ?? '', '+OK')) {
            throw new TelephonyUnavailable('ESL authentication rejected.');
        }
    }

    /** Sends a command and returns its command/reply (or api/response) frame. */
    public function send(string $command): array
    {
        if (str_contains($command, "\n")) {
            throw new \InvalidArgumentException('ESL commands must be single-line.');
        }
        fwrite($this->sock, $command . "\n\n");
        return $this->expect(null, ['command/reply', 'api/response']);
    }

    public function subscribe(string $events): void
    {
        $r = $this->send('event plain ' . $events);
        if (!str_starts_with($r['headers']['Reply-Text'] ?? '', '+OK')) {
            throw new TelephonyUnavailable('ESL subscribe failed.');
        }
    }

    /** Blocks for the next event frame (or null on timeout). */
    public function nextEvent(): ?array
    {
        $f = $this->expect(null, ['text/event-plain'], allowTimeout: true);
        return $f['event'] ?? null;
    }

    private function expect(?string $type, array $types = [], bool $allowTimeout = false): ?array
    {
        $types = $type ? [$type] : $types;
        while (true) {
            foreach ($this->pending as $i => $f) {
                if (in_array($f['headers']['Content-Type'] ?? '', $types, true)) {
                    array_splice($this->pending, $i, 1);
                    return $f;
                }
            }
            $chunk = fread($this->sock, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->sock))) {
                throw new TelephonyUnavailable('ESL connection closed.');
            }
            if ($chunk === '' && stream_get_meta_data($this->sock)['timed_out']) {
                if ($allowTimeout) {
                    return null;
                }
                throw new TelephonyUnavailable('ESL timeout.');
            }
            foreach ($this->parser->feed($chunk) as $f) {
                if (($f['headers']['Content-Type'] ?? '') === 'text/disconnect-notice') {
                    throw new TelephonyUnavailable('ESL disconnected by server.');
                }
                $this->pending[] = $f;
            }
        }
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "exit\n\n");
            fclose($this->sock);
            $this->sock = null;
        }
    }
}
