<?php

namespace App\Http\Requests\Projects;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignProjectUserRequest extends FormRequest
{
    /**
     * Any member of the bound company may assign a user. The company is not an input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer'],
            'company_id' => ['prohibited'],
        ];
    }
}
