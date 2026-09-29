<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function __construct(private ResolveCompanyUsers $resolveCompanyUsers) {}

    /**
     * Create a project in the company already bound by middleware.
     *
     * company_id is filled by BelongsToCompany. When user_ids is present, the pivot
     * is replaced with those users; an omitted key leaves the project with no assignees.
     *
     * @param  array{name: string, description?: string|null, user_ids?: list<int>}  $attributes
     */
    public function handle(array $attributes): Project
    {
        return DB::transaction(function () use ($attributes): Project {
            $project = Project::query()->create([
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
            ]);

            if (array_key_exists('user_ids', $attributes)) {
                $users = $this->resolveCompanyUsers->handle($attributes['user_ids'], 'user_ids');

                $project->users()->sync($users->modelKeys());
            }

            return $project->load('users.company');
        });
    }
}
