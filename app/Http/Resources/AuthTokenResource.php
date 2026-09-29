<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;

class AuthTokenResource extends JsonResource
{
    /**
     * @return array{token: string, user: UserResource}
     */
    public function toArray(Request $request): array
    {
        /** @var NewAccessToken $accessToken */
        $accessToken = $this->resource;

        return [
            'token' => $accessToken->plainTextToken,
            'user' => new UserResource($accessToken->accessToken->tokenable),
        ];
    }
}
