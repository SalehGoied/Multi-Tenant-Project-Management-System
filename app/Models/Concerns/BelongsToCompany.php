<?php

namespace App\Models\Concerns;

use App\Models\Scopes\CompanyScope;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if (filled($model->getAttribute('company_id'))) {
                return;
            }

            // Resolve at create time so this callback does not keep a company the container has already dropped.
            $company = app(CurrentCompany::class)->get();

            if ($company === null) {
                throw new LogicException(
                    sprintf('Cannot create [%s] without a current company.', $model::class),
                );
            }

            $model->setAttribute('company_id', $company->getKey());
        });
    }
}
