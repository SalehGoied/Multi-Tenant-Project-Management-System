<?php

namespace Tests\Feature;

use App\Contracts\OtpSender;
use App\Models\OtpCode;
use App\Models\Project;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_MESSAGE = 'If an account exists for that phone number, a verification code has been sent.';

    private const VALIDATION_MESSAGE = 'Validation failed.';

    protected function tearDown(): void
    {
        if ($this->app->resolved(CurrentCompany::class)) {
            $this->app->make(CurrentCompany::class)->set(null);
        }

        $this->travelBack();
        Cache::flush();

        parent::tearDown();
    }

    public function test_unknown_phone_returns_202_and_writes_no_otp_code(): void
    {
        $sender = $this->bindOtpSender();

        $response = $this->postJson('/api/otp/request', [
            'phone' => '+14155550100',
        ]);

        $response->assertAccepted();
        $response->assertExactJson([
            'message' => self::REQUEST_MESSAGE,
            'data' => null,
        ]);
        $this->assertSame([], $sender->codes);
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_known_phone_returns_202_and_stores_a_hashed_unconsumed_code(): void
    {
        $this->travelTo('2026-06-01 12:00:00');

        $user = User::factory()->create();
        $sender = $this->bindOtpSender();

        $response = $this->postJson('/api/otp/request', [
            'phone' => $user->phone,
        ]);

        $response->assertAccepted();
        $response->assertExactJson([
            'message' => self::REQUEST_MESSAGE,
            'data' => null,
        ]);

        $code = $sender->latestCode();
        $this->assertSame(1, count($sender->codes));
        $this->assertDoesNotMatchRegularExpression('/'.$code.'/', $response->getContent());

        app(CurrentCompany::class)->set($user->company);

        $this->assertSame(1, OtpCode::query()->count());
        $this->assertDatabaseCount('otp_codes', 1);

        $otp = OtpCode::query()->first();
        $this->assertInstanceOf(OtpCode::class, $otp);
        $this->assertSame($user->id, $otp->user_id);
        $this->assertSame($user->company_id, $otp->company_id);
        $this->assertNull($otp->consumed_at);
        $this->assertSame(0, $otp->attempts);
        $this->assertSame('2026-06-01 12:05:00', $otp->expires_at->toDateTimeString());
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
    }

    public function test_second_request_for_the_same_phone_consumes_the_earlier_code(): void
    {
        $user = User::factory()->create();
        $sender = $this->bindOtpSender();

        $this->postJson('/api/otp/request', [
            'phone' => $user->phone,
        ])->assertAccepted();

        $this->postJson('/api/otp/request', [
            'phone' => $user->phone,
        ])->assertAccepted();

        $this->assertSame(2, count($sender->codes));

        app(CurrentCompany::class)->set($user->company);

        $codes = OtpCode::query()->orderBy('id')->get();
        $this->assertCount(2, $codes);
        $this->assertNotNull($codes[0]->consumed_at);
        $this->assertNull($codes[1]->consumed_at);
        $this->assertSame(1, OtpCode::query()->whereNull('consumed_at')->count());
    }

    public function test_verify_with_the_right_code_returns_200_and_consumes_the_code(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);

        $response = $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Authenticated.');
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.name', $user->name);
        $response->assertJsonPath('data.user.phone', $user->phone);
        $response->assertJsonPath('data.user.company.id', $user->company_id);
        $response->assertJsonPath('data.user.company.name', $user->company->name);
        $response->assertJsonPath('data.user.company.slug', $user->company->slug);
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('errors');

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString($code, $response->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 1);

        app(CurrentCompany::class)->set($user->company);

        $otp = OtpCode::query()->first();
        $this->assertInstanceOf(OtpCode::class, $otp);
        $this->assertNotNull($otp->consumed_at);
    }

    public function test_reusing_a_consumed_code_returns_422(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);

        $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ])->assertOk();

        $response = $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'code' => ['The verification code is invalid.'],
            ],
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_expired_code_returns_422(): void
    {
        $this->travelTo('2026-06-01 12:00:00');

        $user = User::factory()->create();
        $code = $this->requestCode($user);

        $this->travelTo('2026-06-01 12:05:00');

        $response = $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ]);

        $response->assertUnprocessable();
        $response->assertExactJson([
            'message' => self::VALIDATION_MESSAGE,
            'errors' => [
                'code' => ['The verification code is invalid.'],
            ],
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        app(CurrentCompany::class)->set($user->company);

        $otp = OtpCode::query()->first();
        $this->assertInstanceOf(OtpCode::class, $otp);
        $this->assertNull($otp->consumed_at);
        $this->assertSame(0, $otp->attempts);
    }

    public function test_five_wrong_guesses_consume_the_code_and_the_sixth_attempt_returns_422(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/otp/verify', [
                'phone' => $user->phone,
                'code' => $wrong,
            ])->assertUnprocessable()
                ->assertExactJson([
                    'message' => self::VALIDATION_MESSAGE,
                    'errors' => [
                        'code' => ['The verification code is invalid.'],
                    ],
                ]);
        }

        app(CurrentCompany::class)->set($user->company);

        $otp = OtpCode::query()->first();
        $this->assertInstanceOf(OtpCode::class, $otp);
        $this->assertSame(5, $otp->attempts);
        $this->assertNotNull($otp->consumed_at);

        app(CurrentCompany::class)->set(null);

        $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => self::VALIDATION_MESSAGE,
                'errors' => [
                    'code' => ['The verification code is invalid.'],
                ],
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_fourth_otp_request_for_the_same_phone_and_ip_returns_429(): void
    {
        $user = User::factory()->create();
        $sender = $this->bindOtpSender();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/otp/request', [
                'phone' => $user->phone,
            ])->assertAccepted()
                ->assertExactJson([
                    'message' => self::REQUEST_MESSAGE,
                    'data' => null,
                ]);
        }

        $response = $this->postJson('/api/otp/request', [
            'phone' => $user->phone,
        ]);

        $response->assertTooManyRequests();
        $response->assertExactJson([
            'message' => 'Too Many Attempts.',
            'errors' => null,
        ]);
        $response->assertHeader('Retry-After');
        $response->assertHeader('X-RateLimit-Limit');
        $this->assertSame(3, count($sender->codes));
        $this->assertDatabaseCount('otp_codes', 3);
    }

    public function test_verified_token_returns_the_user_and_company(): void
    {
        $user = User::factory()->create();
        $code = $this->requestCode($user);

        $verified = $this->postJson('/api/otp/verify', [
            'phone' => $user->phone,
            'code' => $code,
        ])->assertOk();

        $token = $verified->json('data.token');
        $this->assertIsString($token);

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('message', 'Authenticated user retrieved.');
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.name', $user->name);
        $response->assertJsonPath('data.phone', $user->phone);
        $response->assertJsonPath('data.company.id', $user->company_id);
        $response->assertJsonPath('data.company.name', $user->company->name);
        $response->assertJsonPath('data.company.slug', $user->company->slug);
        $response->assertJsonMissingPath('meta');
        $response->assertJsonMissingPath('errors');
    }

    public function test_verified_token_lists_only_the_matched_users_company_projects(): void
    {
        $companyA = User::factory()->create()->company;
        $userB = User::factory()->create();

        Project::factory()->for($companyA)->create(['name' => 'Company A']);
        Project::factory()->for($userB->company)->create(['name' => 'Company B']);

        $this->assertNull(app(CurrentCompany::class)->get());

        $code = $this->requestCode($userB);

        $verified = $this->postJson('/api/otp/verify', [
            'phone' => $userB->phone,
            'code' => $code,
        ]);

        $verified->assertOk();
        $verified->assertJsonPath('data.user.company.id', $userB->company_id);

        $token = $verified->json('data.token');
        $this->assertIsString($token);

        $projects = $this->withToken($token)->getJson('/api/projects');

        $projects->assertOk();
        $projects->assertJsonPath('message', 'Projects retrieved.');
        $projects->assertJsonPath('meta.pagination.total', 1);
        $this->assertSame(['Company B'], collect($projects->json('data'))->pluck('name')->all());
    }

    private function bindOtpSender(): FakeOtpSender
    {
        $sender = new FakeOtpSender;

        $this->app->instance(OtpSender::class, $sender);

        return $sender;
    }

    private function requestCode(User $user): string
    {
        $sender = $this->bindOtpSender();

        $this->postJson('/api/otp/request', [
            'phone' => $user->phone,
        ])->assertAccepted();

        return $sender->latestCode();
    }
}

final class FakeOtpSender implements OtpSender
{
    /**
     * @var list<string>
     */
    public array $codes = [];

    public function send(User $user, string $code): void
    {
        $this->codes[] = $code;
    }

    public function latestCode(): string
    {
        $code = $this->codes[array_key_last($this->codes)] ?? null;

        if (! is_string($code)) {
            throw new \RuntimeException('No OTP code was sent.');
        }

        return $code;
    }
}
