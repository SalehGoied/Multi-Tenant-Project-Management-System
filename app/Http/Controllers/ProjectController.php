<?php

namespace App\Http\Controllers;

use App\Actions\Projects\AssignProjectUser;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UnassignProjectUser;
use App\Actions\Projects\UpdateProject;
use App\Http\Requests\Projects\AssignProjectUserRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $projects = Project::query()
            ->with('users.company')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): ProjectResource
    {
        $project = $createProject->handle($request->validated());

        return new ProjectResource($project);
    }

    public function show(Project $project): ProjectResource
    {
        $project->load('users.company');

        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): ProjectResource
    {
        $project = $updateProject->handle($project, $request->validated());

        return new ProjectResource($project);
    }

    public function destroy(Project $project, DeleteProject $deleteProject): Response
    {
        $deleteProject->handle($project);

        return response()->noContent();
    }

    public function assign(AssignProjectUserRequest $request, Project $project, AssignProjectUser $assignProjectUser): ProjectResource
    {
        $project = $assignProjectUser->handle($project, (int) $request->validated('user_id'));

        return new ProjectResource($project);
    }

    public function unassign(Project $project, int $user, UnassignProjectUser $unassignProjectUser): Response
    {
        $unassignProjectUser->handle($project, $user);

        return response()->noContent();
    }
}
