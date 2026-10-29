<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToCompany;

    protected $table = 'customers';

    protected $fillable = ['customer_ref','name','phone','alt_phone','campaign','status','notes','follow_up_on','assigned_user_id','assigned_team_id','withdrawn_at','last_import_id','city','area','pincode',
        'email','address','state','lat','lng','geo_status','geo_precision','preferred_time','loan_amount','outstanding','principal_os','interest_os','charges','overdue_amount','emi_amount',
        'due_date','oldest_due_date','overdue_installments','last_paid_on','last_paid_amount','balance_as_of'];

    /** Loan / dues columns that come from the lead file (only the ones present in a file are updated). */
    public const ACCOUNT_FIELDS = ['email','address','state','lat','lng','preferred_time','loan_amount','outstanding','principal_os','interest_os','charges',
        'overdue_amount','emi_amount','due_date','oldest_due_date','overdue_installments','last_paid_on','last_paid_amount'];

    protected function casts(): array
    {
        return ['follow_up_on' => 'date','withdrawn_at' => 'datetime','due_date' => 'date:Y-m-d','oldest_due_date' => 'date:Y-m-d','last_paid_on' => 'date:Y-m-d','balance_as_of' => 'date:Y-m-d',
            'lat' => 'float','lng' => 'float','overdue_installments' => 'integer'];
    }

    /** Every save path (upload, phone, bulk lookup, console) goes through here, so no address change is missed. */
    protected static function booted(): void
    {
        static::updated(function (Customer $c) {
            if ($c->wasChanged([...CustomerAddressChange::ADDRESS_FIELDS, 'lat', 'lng'])) CustomerAddressChange::capture($c);
        });
    }

    /** Run $fn with "who / what changed the address" set for the history (source: see CustomerAddressChange::SOURCES). */
    public static function changedBy(string $source, ?int $userId, callable $fn, ?string $note = null, ?int $importJobId = null): mixed
    {
        $prev = CustomerAddressChange::$context;
        CustomerAddressChange::$context = ['source' => $source, 'user_id' => $userId, 'note' => $note, 'import_job_id' => $importJobId];
        try { return $fn(); } finally { CustomerAddressChange::$context = $prev; }
    }

    /** Usable map point: both present, in range, and not the 0,0 "null island" that blank spreadsheet cells turn into. */
    public static function validCoords(mixed $lat, mixed $lng): bool
    {
        if (!is_numeric($lat) || !is_numeric($lng)) return false;
        [$lat, $lng] = [(float) $lat, (float) $lng];
        return abs($lat) <= 90 && abs($lng) <= 180 && !(abs($lat) < 0.0001 && abs($lng) < 0.0001);
    }

    public function calls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function followUps(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FollowUp::class);
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerEvent::class);
    }

    public function assignee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** Customers the employee may see/call right now: assigned directly, or to one of their teams with no individual owner. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $q, User $user): \Illuminate\Database\Eloquent\Builder
    {
        $teamIds = $user->teams()->pluck('teams.id');
        return $q->whereNull('withdrawn_at')->where(function ($w) use ($user, $teamIds) {
            $w->where('assigned_user_id', $user->id)
              ->orWhere(fn ($t) => $t->whereNull('assigned_user_id')->whereIn('assigned_team_id', $teamIds));
        });
    }
}
