<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One employee-day of the activity report, written only by App\Services\Activity\DayBuilder. */
class DailyActivity extends Model
{
    use BelongsToCompany;

    protected $table = 'daily_activity';

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'first_login_at' => 'datetime', 'last_logout_at' => 'datetime', 'built_at' => 'datetime',
            'no_signout' => 'bool', 'scheduled' => 'bool', 'final' => 'bool'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
