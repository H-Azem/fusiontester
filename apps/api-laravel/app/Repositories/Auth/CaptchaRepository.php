<?php

namespace App\Repositories\Auth;

use App\Models\CaptchaChallenge;

class CaptchaRepository
{
    public function create(string $answerHash, ?string $ip, \DateTimeInterface $expiresAt): CaptchaChallenge
    {
        $this->deleteExpired();

        return CaptchaChallenge::query()->create([
            CaptchaChallenge::ANSWER_HASH => $answerHash,
            CaptchaChallenge::IP => $ip,
            CaptchaChallenge::CREATED_AT => now(),
            CaptchaChallenge::EXPIRES_AT => $expiresAt,
        ]);
    }

    public function find(string $id): ?CaptchaChallenge
    {
        return CaptchaChallenge::query()
            ->where(CaptchaChallenge::ID, $id)
            ->first();
    }

    /**
     * Marks the challenge used. Consumption happens before the answer is
     * compared, so a wrong guess cannot be replayed against the same challenge.
     */
    public function consume(string $id): bool
    {
        return CaptchaChallenge::query()
            ->where(CaptchaChallenge::ID, $id)
            ->whereNull(CaptchaChallenge::CONSUMED_AT)
            ->where(CaptchaChallenge::EXPIRES_AT, '>', now())
            ->update([CaptchaChallenge::CONSUMED_AT => now()]) === 1;
    }

    public function hashAnswer(string $answer): string
    {
        return hash('sha256', mb_strtolower(trim($answer)));
    }

    private function deleteExpired(): void
    {
        CaptchaChallenge::query()
            ->where(CaptchaChallenge::EXPIRES_AT, '<', now())
            ->delete();
    }
}
