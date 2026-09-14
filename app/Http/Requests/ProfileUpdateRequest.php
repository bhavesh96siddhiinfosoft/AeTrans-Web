<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Name only. The email address identifies the Firebase account and is not editable
     * from this form — see ProfileController::update(). Anything else posted here is
     * ignored rather than validated, so a stray `email` field cannot smuggle a change
     * through if the input is ever put back on the page.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
