<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    use BelongsToCompany;

    protected $table = 'follow_ups';

    protected $fillable = ['customer_id','user_id','call_id','due_at','status','notes'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime'];
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
