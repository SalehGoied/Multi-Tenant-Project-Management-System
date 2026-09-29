<?php

namespace App\Actions\Projects;

use App\Models\Project;

class DeleteProject
{
    /**
     * Delete a project the route already resolved inside the current company.
     */
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
