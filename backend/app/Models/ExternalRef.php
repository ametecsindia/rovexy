<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Another system's id for one of our rows (SmartDCM user 57 = our user 12). One row per (system, entity, remote id). */
class ExternalRef extends Model
{
    use BelongsToCompany;

    protected $table = 'external_refs';

    protected $fillable = ['system', 'entity', 'remote_id', 'local_id'];

    /** remote id → local id for one entity. */
    public static function map(string $system, string $entity): array
    {
        return self::query()->where('system', $system)->where('entity', $entity)->pluck('local_id', 'remote_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function remoteFor(string $system, string $entity, int $localId): ?string
    {
        return self::query()->where('system', $system)->where('entity', $entity)->where('local_id', $localId)->value('remote_id');
    }

    public static function link(string $system, string $entity, string $remoteId, int $localId): void
    {
        self::query()->updateOrCreate(['system' => $system, 'entity' => $entity, 'remote_id' => $remoteId], ['local_id' => $localId]);
    }
}
