<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User|null user($guard = null)
 */
abstract class BaseRequest extends FormRequest
{
    protected array $excluded = [];

    public function authorize(): bool
    {
        return true;
    }

    public function safeValidated(): array
    {
        return array_diff_key($this->validated(), array_flip($this->excluded));
    }

    public function perPage(): int
    {
        return self::DEFAULT_PAGE_SIZE;
    }
}
