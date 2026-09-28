<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiUserTest extends TestCase
{
    public function test_unauthenticated_api_user_request_is_rejected(): void
    {
        $response = $this->get('/api/user');

        $response->assertUnauthorized();
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }
}
