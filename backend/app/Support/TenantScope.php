<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Fails closed: a tenant query with no bound company throws rather than returning cross-company data. */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $ctx = app(CurrentCompany::class);
        if ($ctx->bypassing()) {
            return;
        }
        $id = $ctx->id() ?? throw new \LogicException('Tenant query on ' . $model::class . ' without a bound company.');
        $builder->where($model->qualifyColumn('company_id'), $id);
    }
}
