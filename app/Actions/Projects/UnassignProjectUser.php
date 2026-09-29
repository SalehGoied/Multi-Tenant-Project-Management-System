<?php

namespace App\Actions\Projects;

use App\Models\Project;

class UnassignProjectUser
{
    public function __construct(private ResolveCompanyUsers $resolveCompanyUsers) {}

    /**
     * Detach one user from the current company. A user who is not assigned is left as-is.
     */
    public function handle(Project $project, int $userId): void
    {
        $users = $this->resolveCompanyUsers->handle([$userId], 'user');

        $project->users()->detach($users->modelKeys());
    }
}
