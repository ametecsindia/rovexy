<?php

declare(strict_types=1);

namespace App\Domain\Retention;

/** Storage estimates and reviewable deletion scopes. Nothing here deletes anything. */
final class RetentionPlanner
{
    /** Bytes for a retention period. Default 32 kbit/s ≈ mono 16 kHz Opus/MP3-ish after transcode. */
    public static function estimateBytes(int $agents, int $callsPerAgentPerDay, float $avgTalkMinutes, int $retentionDays, int $kbps = 32): int
    {
        return (int) round($agents * $callsPerAgentPerDay * $avgTalkMinutes * 60 * ($kbps * 1000 / 8) * $retentionDays);
    }

    /**
     * @param list<array{id:int, expires_at:string, legal_hold:bool, size:int}> $recordings
     * @return array{delete_ids:list<int>, bytes:int, held:int}
     */
    public static function scope(array $recordings, \DateTimeImmutable $now, int $limit = 1000): array
    {
        $ids = [];
        $bytes = 0;
        $held = 0;
        foreach ($recordings as $r) {
            if (new \DateTimeImmutable($r['expires_at']) > $now) {
                continue;
            }
            if ($r['legal_hold']) {
                $held++;
                continue;
            }
            if (count($ids) >= $limit) {
                break;
            }
            $ids[] = $r['id'];
            $bytes += $r['size'];
        }
        return ['delete_ids' => $ids, 'bytes' => $bytes, 'held' => $held];
    }
}
