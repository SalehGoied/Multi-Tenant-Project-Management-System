<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('db:seed-benchmark')]
#[Description('Insert a large local dataset for project-list EXPLAIN and timing checks. Not part of db:seed.')]
class SeedBenchmarkData extends Command
{
    private const COMPANY_COUNT = 500;

    private const USERS_PER_COMPANY = 5;

    private const PROJECTS_PER_COMPANY = 200;

    private const INSERT_CHUNK = 1000;

    /**
     * Cycled into project names so a substring filter matches a predictable slice.
     *
     * @var list<string>
     */
    private const NAME_STEMS = [
        'Website redesign',
        'Mobile checkout',
        'Inventory sync',
        'Partner portal',
        'Billing migration',
        'Lighthouse audit',
        'Warehouse rollout',
        'Support inbox',
        'Analytics pipeline',
        'Vendor onboarding',
    ];

    /**
     * Mass-insert a synthetic dataset. This command is not called from DatabaseSeeder and refuses to run outside local.
     */
    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            $this->error('db:seed-benchmark only runs when APP_ENV is local.');

            return self::FAILURE;
        }

        if (DB::table('companies')->where('slug', 'like', 'benchmark-%')->exists()) {
            $this->error('Benchmark companies already exist. Run php artisan migrate:fresh --seed to restore the normal dataset, then run this command again.');

            return self::FAILURE;
        }

        $timestamp = now()->toDateTimeString();
        $startedAt = hrtime(true);

        $this->info('Inserting companies...');
        $this->insertCompanies($timestamp);

        $companyIds = DB::table('companies')
            ->where('slug', 'like', 'benchmark-%')
            ->orderBy('id')
            ->pluck('id');

        $this->info('Inserting users...');
        $this->insertUsers($companyIds, $timestamp);

        $this->info('Inserting projects...');
        $this->insertProjects($companyIds, $timestamp);

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $projectCount = self::COMPANY_COUNT * self::PROJECTS_PER_COMPANY;
        $firstCompanyId = $companyIds->first();
        $samplePhone = DB::table('users')
            ->where('company_id', $firstCompanyId)
            ->orderBy('id')
            ->value('phone');

        $this->info(sprintf(
            'Seeded %d companies, %d users, and %d projects in %.1f seconds.',
            self::COMPANY_COUNT,
            self::COMPANY_COUNT * self::USERS_PER_COMPANY,
            $projectCount,
            $elapsedSeconds,
        ));
        $this->line("Sample company id: {$firstCompanyId}");
        $this->line("Sample user phone: {$samplePhone}");
        $this->line('Name filter substring: lighthouse');
        $this->line('This dataset is not the default. Restore the small seed with: php artisan migrate:fresh --seed');

        return self::SUCCESS;
    }

    private function insertCompanies(string $timestamp): void
    {
        $rows = [];

        for ($companyNumber = 1; $companyNumber <= self::COMPANY_COUNT; $companyNumber++) {
            $rows[] = [
                'name' => "Benchmark Company {$companyNumber}",
                'slug' => "benchmark-{$companyNumber}",
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('companies')->insert($chunk);
        }
    }

    /**
     * @param  Collection<int, int>  $companyIds
     */
    private function insertUsers(Collection $companyIds, string $timestamp): void
    {
        $rows = [];
        $sequence = 1;

        foreach ($companyIds as $companyId) {
            for ($userNumber = 1; $userNumber <= self::USERS_PER_COMPANY; $userNumber++) {
                $rows[] = [
                    'name' => "Benchmark User {$sequence}",
                    'phone' => sprintf('+1555%08d', $sequence),
                    'company_id' => $companyId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $sequence++;

                if (count($rows) === self::INSERT_CHUNK) {
                    DB::table('users')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('users')->insert($rows);
        }
    }

    /**
     * Mass inserts skip model events. company_id is set on each row so the tenant column is still populated.
     *
     * @param  Collection<int, int>  $companyIds
     */
    private function insertProjects(Collection $companyIds, string $timestamp): void
    {
        $rows = [];
        $projectNumber = 0;
        $createdAtBase = now()->getTimestamp();
        $stemCount = count(self::NAME_STEMS);

        foreach ($companyIds as $companyOffset => $companyId) {
            for ($localIndex = 0; $localIndex < self::PROJECTS_PER_COMPANY; $localIndex++) {
                $rows[] = [
                    'company_id' => $companyId,
                    'name' => self::NAME_STEMS[$localIndex % $stemCount]." {$companyOffset}-{$localIndex}",
                    'description' => null,
                    'created_at' => date('Y-m-d H:i:s', $createdAtBase - $projectNumber),
                    'updated_at' => $timestamp,
                ];
                $projectNumber++;

                if (count($rows) === self::INSERT_CHUNK) {
                    DB::table('projects')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            DB::table('projects')->insert($rows);
        }
    }
}
