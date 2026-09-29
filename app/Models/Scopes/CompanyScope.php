<?php

namespace App\Models\Scopes;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    /**
     * Limit the query to the bound company.
     *
     * An unbound company matches nothing, so a forgotten bind cannot return every tenant's rows.
     *
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Resolve per query. This scope outlives a single request, so holding a
        // CurrentCompany instance would keep the previous tenant after the container drops it.
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->qualifyColumn('company_id'), $company->getKey());
    }
}
