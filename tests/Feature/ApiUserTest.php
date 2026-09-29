<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiUserTest extends TestCase
{
    public function test_unauthenticated_api_user_request_is_rejected(): void
    {
        $response = $this->get('/api/user');

        $response->assertUnauthorized();
        $response->assertExactJson([
            'message' => 'Unauthenticated.',
            'errors' => null,
        ]);
    }

    public function test_unknown_api_route_returns_404(): void
    {
        $debug = config('app.debug');

        try {
            config(['app.debug' => true]);

            $response = $this->getJson('/api/does-not-exist');

            $response->assertNotFound();
            $response->assertExactJson([
                'message' => 'Resource not found.',
                'errors' => null,
            ]);
        } finally {
            config(['app.debug' => $debug]);
        }
    }

    public function test_unexpected_exception_returns_500_without_debug_details(): void
    {
        $debug = config('app.debug');

        Route::get('/api/phase7-unexpected-failure', function (): void {
            throw new RuntimeException('phase7-secret-leak');
        });

        try {
            config(['app.debug' => true]);

            $response = $this->getJson('/api/phase7-unexpected-failure');

            $response->assertInternalServerError();
            $response->assertExactJson([
                'message' => 'Something went wrong.',
                'errors' => null,
            ]);
        } finally {
            config(['app.debug' => $debug]);
        }
    }
}
