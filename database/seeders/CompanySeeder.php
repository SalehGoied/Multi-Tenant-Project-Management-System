<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Local companies for OTP and project checks.
 *
 * Phones are fixed, globally unique, and stored in E.164. README local-run
 * should list these (no other contact fields exist on users):
 * - Acme / Maya Chen / +15555550101
 * - Acme / Luis Ortega / +15555550102
 * - Globex / Priya Shah / +15555550103
 * - Globex / Jonah Berg / +15555550104
 */
class CompanySeeder extends Seeder
{
    public function __construct(private CurrentCompany $currentCompany) {}

    /**
     * Seed companies, their users, and their projects.
     */
    public function run(): void
    {
        try {
            DB::transaction(function (): void {
                foreach ($this->companies() as $companyData) {
                    $this->seedCompany($companyData);
                }
            });
        } finally {
            $this->currentCompany->set(null);
        }
    }

    /**
     * @param  array{name: string, slug: string, users: list<array{name: string, phone: string}>, projects: list<array{name: string, description: string, assignee_phones: list<string>}>}  $companyData
     */
    private function seedCompany(array $companyData): void
    {
        $company = Company::query()->create([
            'name' => $companyData['name'],
            'slug' => $companyData['slug'],
        ]);

        $usersByPhone = [];

        foreach ($companyData['users'] as $userData) {
            $user = User::query()->create([
                'name' => $userData['name'],
                'phone' => $userData['phone'],
                'company_id' => $company->getKey(),
            ]);

            $usersByPhone[$user->phone] = $user;
        }

        // Same binding ResolveCompany uses, so BelongsToCompany fills company_id.
        $this->currentCompany->set($company);

        foreach ($companyData['projects'] as $projectData) {
            $project = Project::query()->create([
                'name' => $projectData['name'],
                'description' => $projectData['description'],
            ]);

            $assigneeIds = array_map(
                fn (string $phone): int => $usersByPhone[$phone]->getKey(),
                $projectData['assignee_phones'],
            );

            $project->users()->sync($assigneeIds);
        }
    }

    /**
     * @return list<array{name: string, slug: string, users: list<array{name: string, phone: string}>, projects: list<array{name: string, description: string, assignee_phones: list<string>}>}>
     */
    private function companies(): array
    {
        return [
            [
                'name' => 'Acme',
                'slug' => 'acme',
                'users' => [
                    ['name' => 'Maya Chen', 'phone' => '+15555550101'],
                    ['name' => 'Luis Ortega', 'phone' => '+15555550102'],
                ],
                'projects' => [
                    [
                        'name' => 'Website redesign',
                        'description' => 'Public marketing site for Acme.',
                        'assignee_phones' => ['+15555550101', '+15555550102'],
                    ],
                    [
                        'name' => 'Mobile checkout',
                        'description' => 'In-app checkout for Acme customers.',
                        'assignee_phones' => ['+15555550102'],
                    ],
                ],
            ],
            [
                'name' => 'Globex',
                'slug' => 'globex',
                'users' => [
                    ['name' => 'Priya Shah', 'phone' => '+15555550103'],
                    ['name' => 'Jonah Berg', 'phone' => '+15555550104'],
                ],
                'projects' => [
                    [
                        'name' => 'Inventory sync',
                        'description' => 'Nightly stock sync for Globex warehouses.',
                        'assignee_phones' => ['+15555550103'],
                    ],
                    [
                        'name' => 'Partner portal',
                        'description' => 'Portal for Globex suppliers.',
                        'assignee_phones' => ['+15555550103', '+15555550104'],
                    ],
                ],
            ],
        ];
    }
}
