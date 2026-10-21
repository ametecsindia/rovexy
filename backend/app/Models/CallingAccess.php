<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One grant: a team (group) or one person may use a calling feature (click2call | smart31). */
class CallingAccess extends Model
{
    use BelongsToCompany;

    protected $table = 'calling_access';

    protected $fillable = ['feature', 'team_id', 'user_id'];
}
