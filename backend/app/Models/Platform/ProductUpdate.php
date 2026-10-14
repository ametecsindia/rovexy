<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

final class ProductUpdate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'released_at' => 'datetime', 'size_bytes' => 'integer', 'downloads' => 'integer'];
    }

    public function path(): string
    {
        return storage_path('app/updates/' . $this->filename);
    }

    /** Published builds on this channel whose file is present, newer than $current, highest first. */
    private static function newer(string $current, string $channel)
    {
        return self::query()->where('is_published', true)->where('channel', $channel)->get()
            ->filter(fn (self $u) => version_compare($u->version, $current, '>') && is_file($u->path()))
            ->sort(fn ($a, $b) => version_compare($b->version, $a->version))->values();
    }

    /** What a server on $current may install now (respects min_version stepping stones), or null. */
    public static function latestFor(string $current, string $channel = 'stable'): ?self
    {
        return self::newer($current, $channel)->first(fn (self $u) => !$u->min_version || version_compare($current, $u->min_version, '>='));
    }

    /** The newest build this server cannot reach yet because of min_version — to say "install X first". */
    public static function blockedFor(string $current, string $channel = 'stable'): ?self
    {
        $top = self::newer($current, $channel)->first();
        return $top && $top->min_version && version_compare($current, $top->min_version, '<') ? $top : null;
    }
}
