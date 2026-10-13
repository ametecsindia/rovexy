<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\GoogleMaps;
use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One change to a case's address or map pin, with the before and after values (see Customer::booted). */
class CustomerAddressChange extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;
    public const ADDRESS_FIELDS = ['address', 'area', 'city', 'state', 'pincode'];
    public const SOURCES = ['upload' => 'Lead file upload', 'phone_gps' => 'Phone · GPS at the customer', 'phone_address' => 'Phone · from the address',
        'bulk_geocode' => 'Bulk address lookup', 'admin' => 'Admin console', 'system' => 'System'];

    protected $fillable = ['customer_id', 'user_id', 'source', 'what', 'old_address', 'new_address', 'old_fields', 'new_fields',
        'old_lat', 'old_lng', 'new_lat', 'new_lng', 'moved_m', 'note', 'import_job_id'];

    protected function casts(): array
    {
        return ['old_fields' => 'array', 'new_fields' => 'array', 'old_lat' => 'float', 'old_lng' => 'float', 'new_lat' => 'float', 'new_lng' => 'float', 'moved_m' => 'integer'];
    }

    /** Who / what is changing addresses right now; set by the caller with Customer::changedBy(). */
    public static array $context = [];

    /** Called after a Customer update: logs the change (and a timeline line) when the address or pin really changed. */
    public static function capture(Customer $c): void
    {
        $text = fn (array $f) => implode(', ', array_filter(array_map(fn ($k) => trim((string) ($f[$k] ?? '')), self::ADDRESS_FIELDS))) ?: null;
        $pin = fn ($lat, $lng) => Customer::validCoords($lat, $lng) ? [round((float) $lat, 7), round((float) $lng, 7)] : null;
        $old = $c->getOriginal();
        $new = $c->getAttributes();
        $oldText = $text($old);
        $newText = $text($new);
        $oldPin = $pin($old['lat'] ?? null, $old['lng'] ?? null);
        $newPin = $pin($new['lat'] ?? null, $new['lng'] ?? null);
        $addr = $oldText !== $newText;   // blank ↔ null and spacing-only edits are not changes
        $moved = $oldPin && $newPin ? (int) round(GoogleMaps::straightMeters($oldPin, $newPin)) : null;
        $pinChanged = ($oldPin === null) !== ($newPin === null) || ($moved !== null && $moved >= 1);
        if (!$addr && !$pinChanged) return;

        $ctx = self::$context + ['source' => 'system', 'user_id' => auth()->id(), 'note' => null, 'import_job_id' => null];
        $what = $addr && $pinChanged ? 'both' : ($addr ? 'address' : 'pin');
        $pick = fn (array $f) => array_intersect_key($f, array_flip(self::ADDRESS_FIELDS));
        $row = self::query()->create(['customer_id' => $c->id, 'user_id' => $ctx['user_id'], 'source' => $ctx['source'], 'what' => $what,
            'old_address' => $oldText, 'new_address' => $newText, 'old_fields' => $pick($old), 'new_fields' => $pick($new),
            'old_lat' => $oldPin[0] ?? null, 'old_lng' => $oldPin[1] ?? null, 'new_lat' => $newPin[0] ?? null, 'new_lng' => $newPin[1] ?? null,
            'moved_m' => $moved, 'note' => $ctx['note'] ? mb_substr((string) $ctx['note'], 0, 255) : null, 'import_job_id' => $ctx['import_job_id']]);

        $how = self::SOURCES[$ctx['source']] ?? $ctx['source'];
        $body = match (true) {
            $what === 'address' => "Address changed ({$how}).",
            !$oldPin => "Map location added ({$how})" . ($addr ? ', address changed too.' : '.'),
            !$newPin => "Map location removed ({$how}).",
            default => "Map location moved {$moved} m ({$how})" . ($addr ? ', address changed too.' : '.'),
        };
        CustomerEvent::query()->create(['customer_id' => $c->id, 'user_id' => $ctx['user_id'], 'type' => 'location',
            'body' => $body . ($ctx['note'] ? ' ' . $ctx['note'] : ''), 'meta' => ['change_id' => $row->id, 'source' => $ctx['source']]]);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Shape shared by the case profile (phone + console) and the admin report. */
    public function toRow(): array
    {
        $p = fn ($lat, $lng) => $lat !== null && $lng !== null ? ['lat' => $lat, 'lng' => $lng] : null;
        return ['id' => $this->id, 'at' => $this->created_at?->toAtomString(), 'what' => $this->what, 'source' => $this->source,
            'source_label' => self::SOURCES[$this->source] ?? $this->source, 'by' => $this->user?->name,
            'old_address' => $this->old_address, 'new_address' => $this->new_address,
            'old_pin' => $p($this->old_lat, $this->old_lng), 'new_pin' => $p($this->new_lat, $this->new_lng), 'moved_m' => $this->moved_m, 'note' => $this->note];
    }
}
