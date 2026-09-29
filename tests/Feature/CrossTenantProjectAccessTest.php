<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossTenantProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_for_another_companys_project_returns_404(): void
    {
        [$intruder, $project] = $this->intruderAndForeignProject();
        $owner = User::factory()->for($project->company)->create();

        $this->actingAsToken($intruder)
            ->getJson('/api/projects/'.$project->id)
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
                'errors' => null,
            ]);

        $this->actingAsToken($owner)
            ->getJson('/api/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('message', 'Project retrieved.')
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', $project->name);
    }

    public function test_update_for_another_companys_project_returns_404(): void
    {
        [$intruder, $project] = $this->intruderAndForeignProject();

        $this->actingAsToken($intruder)
            ->patchJson('/api/projects/'.$project->id, [
                'name' => 'Hijacked',
            ])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
                'errors' => null,
            ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'name' => $project->name,
        ]);
    }

    public function test_delete_for_another_companys_project_returns_404_and_the_owner_can_still_read_it(): void
    {
        [$intruder, $project] = $this->intruderAndForeignProject();
        $owner = User::factory()->for($project->company)->create();

        $this->actingAsToken($intruder)
            ->deleteJson('/api/projects/'.$project->id)
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
                'errors' => null,
            ]);

        $this->actingAsToken($owner)
            ->getJson('/api/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.name', $project->name);

        $this->assertModelExists($project);
    }

    public function test_index_excludes_another_companys_projects(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->for($companyA)->create();
        Project::factory()->for($companyA)->create(['name' => 'Visible']);

        $companyB = Company::factory()->create();
        Project::factory()->for($companyB)->create(['name' => 'Secret']);

        $response = $this->actingAsToken($userA)
            ->getJson('/api/projects');

        $response->assertOk();
        $response->assertJsonPath('message', 'Projects retrieved.');
        $response->assertJsonPath('meta.pagination.total', 1);
        $this->assertSame(['Visible'], collect($response->json('data'))->pluck('name')->all());
    }

    public function test_assigning_a_user_from_another_company_returns_422_and_does_not_attach_them(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->for($companyA)->create();
        $project = Project::factory()->for($companyA)->create();
        $outsider = User::factory()->create();

        $response = $this->actingAsToken($userA)
            ->postJson('/api/projects/'.$project->id.'/users', [
                'user_id' => $outsider->id,
            ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => [
                'user_id' => ['The selected user is invalid.'],
            ],
        ]);
        $this->assertStringNotContainsString($outsider->company->name, $response->getContent());
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
            'user_id' => $outsider->id,
        ]);
    }

    /**
     * Sanctum keeps the first resolved user on the guard for this process.
     * A later token in the same test is ignored until that guard is dropped.
     */
    private function actingAsToken(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function intruderAndForeignProject(): array
    {
        $ownerCompany = Company::factory()->create();
        $project = Project::factory()->for($ownerCompany)->create([
            'name' => 'Owned',
        ]);
        $intruder = User::factory()->create();

        return [$intruder, $project];
    }
}
