<?php

namespace App\Http\Controllers;

use App\Actions\Projects\AssignProjectUser;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UnassignProjectUser;
use App\Actions\Projects\UpdateProject;
use App\Http\Requests\Projects\AssignProjectUserRequest;
use App\Http\Requests\Projects\IndexProjectRequest;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function index(IndexProjectRequest $request): JsonResponse
    {
        $name = $request->validated('name');
        $name = is_string($name) ? trim($name) : '';

        $projects = Project::query()
            ->with('users.company')
            ->when($name !== '', function (Builder $query) use ($name): void {
                $query->whereLike('name', '%'.$name.'%');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate();

        return ApiResponse::success(
            ProjectResource::collection($projects),
            'Projects retrieved.',
            meta: [
                'pagination' => [
                    'current_page' => $projects->currentPage(),
                    'per_page' => $projects->perPage(),
                    'total' => $projects->total(),
                    'last_page' => $projects->lastPage(),
                ],
            ],
        );
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): JsonResponse
    {
        $project = $createProject->handle($request->validated());

        return ApiResponse::success(new ProjectResource($project), 'Project created.');
    }

    public function show(Project $project): JsonResponse
    {
        $project->load('users.company');

        return ApiResponse::success(new ProjectResource($project), 'Project retrieved.');
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): JsonResponse
    {
        $project = $updateProject->handle($project, $request->validated());

        return ApiResponse::success(new ProjectResource($project), 'Project updated.');
    }

    public function destroy(Project $project, DeleteProject $deleteProject): JsonResponse
    {
        $deleteProject->handle($project);

        return ApiResponse::success(null, 'Project deleted.');
    }

    public function assign(AssignProjectUserRequest $request, Project $project, AssignProjectUser $assignProjectUser): JsonResponse
    {
        $project = $assignProjectUser->handle($project, (int) $request->validated('user_id'));

        return ApiResponse::success(new ProjectResource($project), 'User assigned to project.');
    }

    public function unassign(Project $project, int $user, UnassignProjectUser $unassignProjectUser): JsonResponse
    {
        $unassignProjectUser->handle($project, $user);

        return ApiResponse::success(null, 'User removed from project.');
    }
}
