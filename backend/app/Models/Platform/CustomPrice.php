<?php

declare(strict_types=1);

namespace App\Models\Platform;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A negotiated price for one company / buyer e-mail. See CustomPrice::for() for which rule wins; Pricing applies it.
 * mode: fixed = the whole licence price for the period (₹, before GST) · percent = % off the standard price · rate = ₹ per user per month (cloud)
 */
class CustomPrice extends Model
{
    protected $fillable = ['company_id', 'email', 'plan_id', 'applies_to', 'mode', 'value', 'starts_on', 'ends_on', 'active', 'note', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'float', 'active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }


    /** active | scheduled | expired | off — for the dashboard. */
    public function state(): string
    {
        $today = now()->toDateString();
        return match (true) {
            !$this->active => 'off',
            $this->starts_on && $this->starts_on->toDateString() > $today => 'scheduled',
            $this->ends_on && $this->ends_on->toDateString() < $today => 'expired',
            default => 'active',
        };
    }

    /**
     * The rule that applies today, or null (= standard price). Only this company's / e-mail's rules are looked at.
     * Most specific wins: company before e-mail, one package before "every package", then the newest.
     * After ends_on it simply stops matching, so the price falls back to the next valid rule or the standard price.
     */
    public static function for(Plan $plan, string $kind, ?int $companyId, ?string $email): ?self
    {
        $email = $email ? strtolower(trim($email)) : null;
        if ($companyId === null && $email === null) {
            return null;
        }
        $today = now()->toDateString();
        return self::query()->where('active', true)
            ->whereIn('applies_to', [$kind === 'new' ? 'new' : 'renewal', 'any'])
            ->where(fn ($q) => $q->whereNull('plan_id')->orWhere('plan_id', $plan->id))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->where(fn ($q) => $q->when($companyId, fn ($w) => $w->orWhere('company_id', $companyId))
                ->when($email, fn ($w) => $w->orWhere(fn ($x) => $x->whereNull('company_id')->where('email', $email))))
            ->orderByRaw('case when company_id is null then 1 else 0 end')
            ->orderByRaw('case when plan_id is null then 1 else 0 end')
            ->orderByDesc('id')->first();
    }
}
