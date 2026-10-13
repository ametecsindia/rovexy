<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new TenantScope());
        static::creating(function ($model): void {
            $ctx = app(CurrentCompany::class);
            if ($model->company_id === null) {
                $model->company_id = $ctx->id() ?? throw new \LogicException('Cannot create ' . static::class . ' without a company.');
            } elseif ($ctx->bound() && (int) $model->company_id !== $ctx->id()) {
                throw new \LogicException('Refusing to create a record for a different company.');
            }
        });
        static::updating(function ($model): void {
            if ($model->isDirty('company_id')) {
                throw new \LogicException('company_id is immutable.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
