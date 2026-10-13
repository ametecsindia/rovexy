<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CallDisposition extends Model
{
    use BelongsToCompany;

    protected $table = 'call_dispositions';

    public $timestamps = false;

    protected $fillable = ['call_id','user_id','code','notes','follow_up_at','submitted_at','amount','payment_mode','payment_ref','paid_on',
        'slip_key','slip_mime','slip_name','review_status','reviewed_by','reviewed_at','review_note','emailed_to','emailed_at'];

    protected $hidden = ['slip_key'];

    protected function casts(): array
    {
        return ['follow_up_at' => 'datetime','submitted_at' => 'datetime','paid_on' => 'date:Y-m-d','reviewed_at' => 'datetime','emailed_at' => 'datetime','amount' => 'decimal:2'];
    }

    protected $appends = ['has_slip'];

    public function getHasSlipAttribute(): bool
    {
        return $this->slip_key !== null;
    }

    public function call(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
