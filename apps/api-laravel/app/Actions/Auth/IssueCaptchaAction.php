<?php

namespace App\Actions\Auth;

use App\Repositories\Auth\CaptchaRepository;
use App\Services\Auth\CaptchaSvgService;

/**
 * Issues a verification code and stores only its hash, so the answer never
 * exists in the database in readable form.
 */
class IssueCaptchaAction
{
    public function __construct(
        private ?string $ip,
        private CaptchaSvgService $svg = new CaptchaSvgService,
        private ?CaptchaRepository $repository = null,
    ) {
        $this->repository ??= new CaptchaRepository;
    }

    public function handle(): array
    {
        $characters = $this->svg->generate(
            (int) config('fusion.captcha.length'),
            (string) config('fusion.captcha.ignore_chars')
        );

        $expiresAt = now()->addMinutes((int) config('fusion.captcha.ttl_minutes'));

        $challenge = $this->repository->create(
            $this->repository->hashAnswer($characters),
            $this->ip,
            $expiresAt
        );

        return [
            'id' => $challenge->getId(),
            'svg' => $this->svg->render($characters),
        ];
    }
}
