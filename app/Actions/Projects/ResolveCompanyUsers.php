<?php

namespace App\Actions\Projects;

use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class ResolveCompanyUsers
{
    public function __construct(private CurrentCompany $currentCompany) {}

    /**
     * Users in the bound company for these ids.
     *
     * User is unscoped, so the company filter has to be explicit. A missing id and an id
     * from another company fail with the same message.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function handle(array $ids, string $errorKey): Collection
    {
        $company = $this->currentCompany->get();

        if ($company === null) {
            throw new LogicException('Cannot resolve project users without a current company.');
        }

        $ids = array_values(array_unique(array_map(intval(...), $ids)));

        if ($ids === []) {
            return new Collection;
        }

        $users = User::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $ids)
            ->get();

        if ($users->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $errorKey => 'The selected user is invalid.',
            ]);
        }

        return $users;
    }
}
