<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATION_MESSAGE = 'Validation failed.';

    public function test_unauthenticated_project_index_returns_401(): void
    {
        $response = $this->getJson('/api/projects');

        $response->assertUnauthorized();
        $response->assertExactJson([
            'message' => 'Unauthenticated.',
            'errors' => null,
        ]);
    }

    public function test_authenticated_user_creates_a_project(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/projects', [
                'name' => 'Launch',
                'description' => 'Ship it',
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Project created.');
        $response->assertJsonPath('data.name', 'Launch');
        $response->assertJsonPath('data.description', 'Ship it');
        $response->assertJsonPath('data.assigned_users', []);
        $response->assertJsonMissingPath('data.company_id');
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('errors');

        $this->assertDatabaseHas('projects', [
            'id' => $response->json('data.id'),
            'company_id' => $user->company_id,
            'name' => 'Launch',
            'description' => 'Ship it',
        ]);
    }

    public function test_index_returns_paginated_projects_for_the_company(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();

        Project::factory()->for($company)->create([
            'name' => 'Older',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        Project::factory()->for($company)->create([
            'name' => 'Newer',
            'created_at' => '2026-02-01 00:00:00',
            'updated_at' => '2026-02-01 00:00:00',
        ]);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonPath('message', 'Projects retrieved.');
        $response->assertJsonPath('data.0.name', 'Newer');
        $response->assertJsonPath('data.1.name', 'Older');
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $response->assertJsonPath('meta.pagination.per_page', 15);
        $response->assertJsonPath('meta.pagination.total', 2);
        $response->assertJsonPath('meta.pagination.last_page', 1);
        $response->assertJsonMissingPath('meta.links');
        $response->assertJsonMissingPath('errors');
    }

    public function test_index_filters_projects_by_a_case_insensitive_name_substring(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();

        Project::factory()->for($company)->create([
            'name' => 'Website redesign',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        Project::factory()->for($company)->create([
            'name' => 'Web analytics',
            'created_at' => '2026-02-01 00:00:00',
            'updated_at' => '2026-02-01 00:00:00',
        ]);
        Project::factory()->for($company)->create([
            'name' => 'Mobile checkout',
            'created_at' => '2026-03-01 00:00:00',
            'updated_at' => '2026-03-01 00:00:00',
        ]);

        $otherCompany = Company::factory()->create();
        Project::factory()->for($otherCompany)->create([
            'name' => 'Website rebuild',
            'created_at' => '2026-04-01 00:00:00',
            'updated_at' => '2026-04-01 00:00:00',
        ]);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/projects?name=WEB');

        $response->assertOk();
        $response->assertJsonPath('message', 'Projects retrieved.');
        $response->assertJsonPath('meta.pagination.total', 2);
        $response->assertJsonPath('meta.pagination.current_page', 1);
        $this->assertSame(
            ['Web analytics', 'Website redesign'],
            collect($response->json('data'))->pluck('name')->all(),
        );
    }

    public function test_index_returns_422_when_name_is_longer_than_255_characters(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/projects?name='.str_repeat('a', 256));

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'name' => ['The name field must not be greater than 255 characters.'],
            ],
        ]);
    }

    public function test_index_returns_422_when_name_is_not_a_string(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/projects?name[]=web');

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'name' => ['The name field must be a string.'],
            ],
        ]);
    }

    public function test_show_returns_the_project(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create([
            'name' => 'Visible',
            'description' => 'Details',
        ]);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/projects/'.$project->id);

        $response->assertOk();
        $response->assertJsonPath('message', 'Project retrieved.');
        $response->assertJsonPath('data.id', $project->id);
        $response->assertJsonPath('data.name', 'Visible');
        $response->assertJsonPath('data.description', 'Details');
        $response->assertJsonPath('data.assigned_users', []);
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('errors');
    }

    public function test_update_changes_the_project(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create([
            'name' => 'Old',
            'description' => 'Keep me',
        ]);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->patchJson('/api/projects/'.$project->id, [
                'name' => 'Renamed',
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Project updated.');
        $response->assertJsonPath('data.id', $project->id);
        $response->assertJsonPath('data.name', 'Renamed');
        $response->assertJsonPath('data.description', 'Keep me');
        $response->assertJsonMissingPath('meta');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'company_id' => $company->id,
            'name' => 'Renamed',
            'description' => 'Keep me',
        ]);
    }

    public function test_delete_returns_200_with_null_data(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->deleteJson('/api/projects/'.$project->id);

        $response->assertOk();
        $response->assertExactJson([
            'message' => 'Project deleted.',
            'data' => null,
        ]);
        $this->assertModelMissing($project);
    }

    public function test_empty_name_returns_422_with_the_same_message_when_debug_is_on_or_off(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $debug = config('app.debug');
        $messages = [];

        try {
            foreach ([true, false] as $appDebug) {
                config(['app.debug' => $appDebug]);

                $response = $this->withToken($token)->postJson('/api/projects', [
                    'name' => '',
                ]);

                $response->assertUnprocessable();
                $response->assertExactJson([
                    'message' => self::VALIDATION_MESSAGE,
                    'errors' => [
                        'name' => ['The name field is required.'],
                    ],
                ]);

                $messages[] = $response->json('message');
            }
        } finally {
            config(['app.debug' => $debug]);
        }

        $this->assertSame(self::VALIDATION_MESSAGE, $messages[0]);
        $this->assertSame($messages[0], $messages[1]);
        $this->assertDatabaseCount('projects', 0);
    }

    public function test_assigning_a_same_company_user_returns_200(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $colleague = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/projects/'.$project->id.'/users', [
                'user_id' => $colleague->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'User assigned to project.');
        $response->assertJsonPath('data.assigned_users.0.id', $colleague->id);
        $response->assertJsonPath('data.assigned_users.0.name', $colleague->name);
        $response->assertJsonPath('data.assigned_users.0.phone', $colleague->phone);
        $response->assertJsonPath('data.assigned_users.0.company.id', $company->id);
        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $colleague->id,
        ]);
    }

    public function test_assigning_a_missing_user_returns_422(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();
        $missingId = $user->id + 1000;

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/projects/'.$project->id.'/users', [
                'user_id' => $missingId,
            ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'user_id' => ['The selected user is invalid.'],
            ],
        ]);
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
        ]);
    }

    public function test_assigning_a_user_from_another_company_returns_422(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();
        $outsider = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/projects/'.$project->id.'/users', [
                'user_id' => $outsider->id,
            ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'user_id' => ['The selected user is invalid.'],
            ],
        ]);
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_unassigning_a_user_returns_200_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $colleague = User::factory()->for($company)->create();
        $project = Project::factory()->for($company)->create();
        $project->users()->attach($colleague->id);

        $token = $user->createToken('test')->plainTextToken;

        $first = $this->withToken($token)
            ->deleteJson('/api/projects/'.$project->id.'/users/'.$colleague->id);

        $first->assertOk();
        $first->assertExactJson([
            'message' => 'User removed from project.',
            'data' => null,
        ]);
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
            'user_id' => $colleague->id,
        ]);

        $second = $this->withToken($token)
            ->deleteJson('/api/projects/'.$project->id.'/users/'.$colleague->id);

        $second->assertOk();
        $second->assertExactJson([
            'message' => 'User removed from project.',
            'data' => null,
        ]);
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
            'user_id' => $colleague->id,
        ]);
    }

    public function test_company_id_on_create_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/projects', [
                'name' => 'Launch',
                'company_id' => $user->company_id,
            ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'company_id' => ['The company id field is prohibited.'],
            ],
        ]);
        $this->assertDatabaseCount('projects', 0);
    }
}
