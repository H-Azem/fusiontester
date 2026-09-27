<?php

namespace App\Services\Gitlab;

class GitlabApiError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
