<?php

declare(strict_types=1);

namespace App\Domain\Telephony;

/**
 * Incremental parser for the FreeSWITCH Event Socket protocol.
 * Frames: header block ("Key: Value\n" … "\n"), optional body of Content-Length bytes.
 * For "text/event-plain" bodies the event headers are URL-encoded "Key: Value" lines.
 */
final class EslFrameParser
{
    private string $buffer = '';

    /** @return list<array{headers:array<string,string>, body:string, event:?array<string,string>}> */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $frames = [];
        while (true) {
            $sep = strpos($this->buffer, "\n\n");
            if ($sep === false) {
                break;
            }
            $headers = self::parseHeaders(substr($this->buffer, 0, $sep), false);
            $len = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : 0;
            if (strlen($this->buffer) < $sep + 2 + $len) {
                break; // wait for the full body
            }
            $body = substr($this->buffer, $sep + 2, $len);
            $this->buffer = (string) substr($this->buffer, $sep + 2 + $len);
            $event = null;
            if (($headers['Content-Type'] ?? '') === 'text/event-plain') {
                $split = strpos($body, "\n\n");
                $event = self::parseHeaders($split === false ? $body : substr($body, 0, $split), true);
                if ($split !== false && isset($event['Content-Length'])) {
                    $event['_body'] = substr($body, $split + 2, (int) $event['Content-Length']);
                }
            }
            $frames[] = ['headers' => $headers, 'body' => $body, 'event' => $event];
        }
        return $frames;
    }

    /** @return array<string,string> */
    private static function parseHeaders(string $block, bool $urlDecode): array
    {
        $out = [];
        foreach (explode("\n", trim($block, "\n")) as $line) {
            $p = strpos($line, ':');
            if ($p === false) {
                continue;
            }
            $v = ltrim(substr($line, $p + 1));
            $out[substr($line, 0, $p)] = $urlDecode ? rawurldecode($v) : $v;
        }
        return $out;
    }
}
