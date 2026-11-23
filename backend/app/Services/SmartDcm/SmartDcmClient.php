<?php

declare(strict_types=1);

namespace App\Services\SmartDcm;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * SmartDCM's ROVEXY API (built on the SmartDCM side — see docs/SMARTDCM-INTEGRATION-PROMPT.md):
 *   {base}/api/rovexy/v1/...   header X-Api-Key: the key the client's SmartDCM admin created for ROVEXY.
 * Lists page with a cursor: ?updated_since=<ISO>&after_id=<id>&limit=N → {data: [...], next: {updated_since, after_id} | null}.
 */
final class SmartDcmClient
{
    public const PREFIX = '/api/rovexy/v1';

    public function __construct(private readonly IntegrationConnection $conn)
    {
    }

    public function get(string $path, array $query = []): array
    {
        $r = $this->http()->get($this->conn->url(self::PREFIX . $path), array_filter($query, fn ($v) => $v !== null && $v !== ''));
        return $this->body($r, $path);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->body($this->http()->post($this->conn->url(self::PREFIX . $path), $body), $path);
    }

    /**
     * One page of a list. $cursor = ['updated_since' => ..., 'after_id' => ...] from the previous page (or the saved state).
     * @return array{0: list<array>, 1: ?array} rows and the next cursor (null = no more pages now)
     */
    public function page(string $path, ?array $cursor, int $limit = 500, array $query = []): array
    {
        $b = $this->get($path, $query + ['limit' => $limit] + ($cursor ?? []));
        $next = $b['next'] ?? null;
        return [array_values((array) ($b['data'] ?? [])), is_array($next) && $next !== [] ? $next : null];
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['X-Api-Key' => $this->conn->api_key, 'X-Client' => 'ROVEXY'])->acceptJson()->connectTimeout(5)->timeout(30);
    }

    private function body(\Illuminate\Http\Client\Response $r, string $path): array
    {
        if (!$r->successful()) {
            $msg = (string) ($r->json('message') ?? $r->json('error.message') ?? mb_substr($r->body(), 0, 150));
            throw new SmartDcmError("SmartDCM {$path}: HTTP {$r->status()} {$msg}", $r->status());
        }
        $j = $r->json();
        if (!is_array($j)) {
            throw new SmartDcmError("SmartDCM {$path}: the answer is not JSON (is the URL right?)", 0);
        }
        return $j;
    }
}
