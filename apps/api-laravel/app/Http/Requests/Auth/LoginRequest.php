<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class LoginRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
            'captchaId' => ['required', 'string'],
            'captchaText' => ['required', 'string', 'max:20'],
        ];
    }
}
