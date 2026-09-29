<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_stays_flat_as_assigned_projects_grow(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $assignees = User::factory()->count(3)->for($company)->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->seedAssignedProjects($company, $assignees, 5);

        $smallQueryCount = $this->projectIndexQueryCount($token, 5, 5);

        $this->seedAssignedProjects($company, $assignees, 15);

        // The auth guard survives inside one test, and Sanctum skips the last-used update when the timestamp does not change.
        $this->travel(1)->seconds();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $largeQueryCount = $this->projectIndexQueryCount($token, 20, 15);

        $this->assertSame($smallQueryCount, $largeQueryCount);
        // Token lookup, last-used update, user, company, project count, project page, and one assignee query.
        $this->assertLessThanOrEqual(7, $largeQueryCount);
    }

    /**
     * @param  Collection<int, User>  $assignees
     */
    private function seedAssignedProjects(Company $company, Collection $assignees, int $count): void
    {
        $projects = Project::factory()->count($count)->for($company)->create();

        foreach ($projects as $offset => $project) {
            $assigneeCount = ($offset % 2 === 0) ? 2 : 3;

            $project->users()->attach($assignees->take($assigneeCount)->modelKeys());
        }
    }

    private function projectIndexQueryCount(string $token, int $expectedTotal, int $expectedPageCount): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->withToken($token)->getJson('/api/projects');

            $queries = array_column(DB::getQueryLog(), 'query');
            $queryCount = count($queries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $assigneeQueries = array_values(array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'project_user'),
        ));

        $this->assertCount(1, $assigneeQueries);

        $response->assertOk();
        $response->assertJsonPath('message', 'Projects retrieved.');
        $response->assertJsonPath('meta.pagination.total', $expectedTotal);
        $response->assertJsonCount($expectedPageCount, 'data');

        foreach ($response->json('data') as $project) {
            $assignedUserCount = count($project['assigned_users']);

            $this->assertGreaterThanOrEqual(2, $assignedUserCount);
            $this->assertLessThanOrEqual(3, $assignedUserCount);
        }

        return $queryCount;
    }
}
