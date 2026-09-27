<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseRequest;

class ChangePasswordRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:200'],
            'newPassword' => ['required', 'string', 'max:200'],
        ];
    }
}
