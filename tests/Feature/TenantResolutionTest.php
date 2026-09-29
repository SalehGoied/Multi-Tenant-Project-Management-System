<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(CurrentCompany::class)->set(null);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->set(null);

        parent::tearDown();
    }

    public function test_creating_a_project_without_a_current_company_throws(): void
    {
        app(CurrentCompany::class)->set(null);

        try {
            Project::query()->create([
                'name' => 'Unscoped',
            ]);
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot create ['.Project::class.'] without a current company.',
                $exception->getMessage(),
            );
            $this->assertDatabaseCount('projects', 0);

            return;
        }

        $this->fail('Creating a project without a current company was expected to throw.');
    }

    public function test_creating_an_otp_code_without_a_current_company_throws(): void
    {
        $user = User::factory()->create();

        app(CurrentCompany::class)->set(null);

        try {
            OtpCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => Hash::make('123456'),
                'expires_at' => '2026-06-01 12:05:00',
            ]);
        } catch (LogicException $exception) {
            $this->assertSame(
                'Cannot create ['.OtpCode::class.'] without a current company.',
                $exception->getMessage(),
            );
            $this->assertDatabaseCount('otp_codes', 0);

            return;
        }

        $this->fail('Creating an OTP code without a current company was expected to throw.');
    }

    public function test_querying_projects_with_no_company_bound_returns_no_rows(): void
    {
        $project = Project::factory()->create();

        app(CurrentCompany::class)->set(null);

        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertSame(0, Project::query()->count());
        $this->assertNull(Project::query()->find($project->id));
    }

    public function test_querying_otp_codes_with_no_company_bound_returns_no_rows(): void
    {
        $user = User::factory()->create();

        app(CurrentCompany::class)->set(null);

        $otp = OtpCode::query()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => '2026-06-01 12:05:00',
        ]);

        $this->assertSame(1, DB::table('otp_codes')->count());
        $this->assertSame(0, OtpCode::query()->count());
        $this->assertNull(OtpCode::query()->find($otp->id));
    }

    public function test_binding_a_company_scopes_projects_and_fills_company_id_on_create(): void
    {
        $acme = User::factory()->create()->company;
        $globex = User::factory()->create()->company;

        Project::factory()->for($acme)->create(['name' => 'Acme Work']);
        Project::factory()->for($globex)->create(['name' => 'Globex Work']);

        app(CurrentCompany::class)->set($acme);

        $this->assertSame(['Acme Work'], Project::query()->orderBy('id')->pluck('name')->all());

        $created = Project::query()->create([
            'name' => 'Scoped Work',
        ]);

        $this->assertSame($acme->id, $created->company_id);
        $this->assertSame(
            ['Acme Work', 'Scoped Work'],
            Project::query()->orderBy('id')->pluck('name')->all(),
        );
        $this->assertDatabaseHas('projects', [
            'id' => $created->id,
            'company_id' => $acme->id,
            'name' => 'Scoped Work',
        ]);
    }

    public function test_binding_a_company_scopes_otp_codes_and_fills_company_id_on_create(): void
    {
        $acmeUser = User::factory()->create();
        $globexUser = User::factory()->create();

        OtpCode::query()->create([
            'company_id' => $acmeUser->company_id,
            'user_id' => $acmeUser->id,
            'code_hash' => Hash::make('111111'),
            'expires_at' => '2026-06-01 12:05:00',
        ]);
        OtpCode::query()->create([
            'company_id' => $globexUser->company_id,
            'user_id' => $globexUser->id,
            'code_hash' => Hash::make('222222'),
            'expires_at' => '2026-06-01 12:05:00',
        ]);

        app(CurrentCompany::class)->set($acmeUser->company);

        $this->assertSame(1, OtpCode::query()->count());
        $this->assertSame($acmeUser->id, OtpCode::query()->value('user_id'));

        $created = OtpCode::query()->create([
            'user_id' => $acmeUser->id,
            'code_hash' => Hash::make('333333'),
            'expires_at' => '2026-06-01 12:10:00',
        ]);

        $this->assertSame($acmeUser->company_id, $created->company_id);
        $this->assertSame(2, OtpCode::query()->count());
        $this->assertDatabaseHas('otp_codes', [
            'id' => $created->id,
            'company_id' => $acmeUser->company_id,
            'user_id' => $acmeUser->id,
        ]);
    }
}
