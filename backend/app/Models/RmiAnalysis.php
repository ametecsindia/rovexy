<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SmartRMI's analysis of one call: the main fields for lists and reports, plus the engine's results as sent. */
class RmiAnalysis extends Model
{
    use BelongsToCompany;

    protected $fillable = ['call_id', 'user_id', 'status', 'score', 'tone', 'sentiment', 'aggression', 'compliance_risk', 'angry', 'summary', 'transcript', 'coaching', 'results', 'analysed_at'];

    protected function casts(): array
    {
        return ['angry' => 'boolean', 'score' => 'float', 'coaching' => 'array', 'results' => 'array', 'analysed_at' => 'datetime'];
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
