<?php

namespace App\Actions\Projects;

use App\Models\Project;

class AssignProjectUser
{
    public function __construct(private ResolveCompanyUsers $resolveCompanyUsers) {}

    /**
     * Attach one user from the current company. Attaching an existing assignee changes nothing.
     */
    public function handle(Project $project, int $userId): Project
    {
        $users = $this->resolveCompanyUsers->handle([$userId], 'user_id');

        $project->users()->syncWithoutDetaching($users->modelKeys());

        return $project->load('users');
    }
}
