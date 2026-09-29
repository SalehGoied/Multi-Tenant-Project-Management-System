<?php

namespace App\Actions\Projects;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    public function __construct(private ResolveCompanyUsers $resolveCompanyUsers) {}

    /**
     * Update project fields and, when user_ids is present, replace the assignee pivot.
     *
     * An omitted user_ids key leaves current assignments unchanged.
     *
     * @param  array{name?: string, description?: string|null, user_ids?: list<int>}  $attributes
     */
    public function handle(Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($project, $attributes): Project {
            $changes = array_intersect_key($attributes, array_flip(['name', 'description']));

            if ($changes !== []) {
                $project->update($changes);
            }

            if (array_key_exists('user_ids', $attributes)) {
                $users = $this->resolveCompanyUsers->handle($attributes['user_ids'], 'user_ids');

                $project->users()->sync($users->modelKeys());
            }

            return $project->load('users.company');
        });
    }
}
