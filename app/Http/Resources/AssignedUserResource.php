<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Project assignee. Assignment is same-company only, so this omits the company object that {@see UserResource} includes.
 *
 * @mixin User
 */
class AssignedUserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, phone: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
        ];
    }
}
