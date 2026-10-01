<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSession;
use App\Models\Contracts\UserInterface;
use App\Models\LoginAttempt;
use App\Repositories\Auth\AuthSessionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second password: it guards Settings, where credentials live.
 *
 * The default is a placeholder that config carries, so the first person in can
 * open Settings and replace it. Failures are recorded but never counted towards
 * the sign-in lockout — forgetting this password should not lock anybody out of
 * the application.
 */
class SettingsPasswordController extends Controller
{
    const FAILED_MESSAGE = 'That settings password is not correct.';

    const THROTTLED_MESSAGE = 'Too many attempts on the settings password. Sign in again.';

    const REASON = 'settings_password';

    const MAX_ATTEMPTS = 5;

    const ATTEMPT_WINDOW_MINUTES = 15;

    public function __construct(private AuthSessionRepository $sessions = new AuthSessionRepository) {}

    public function status(Request $request): JsonResponse
    {
        $session = RequireSession::session($request);

        return $this->legacyResponse(['unlocked' => $session?->getSettingsUnlockedAt() !== null]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate(['password' => ['required', 'string', 'max:72']]);
        $session = RequireSession::session($request);

        if ($session === null) {
            return $this->legacyResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($this->tooManyAttempts($request->ip())) {
            return $this->legacyResponse(
                ['error' => 'throttled', 'message' => self::THROTTLED_MESSAGE],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        if (! $this->matches($session->user, (string) $validated['password'])) {
            $this->recordFailure($request, $session->user?->getUsername());

            return $this->legacyResponse(
                ['error' => 'invalid', 'message' => self::FAILED_MESSAGE],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $this->sessions->unlockSettings($session);

        return $this->legacyResponse(['ok' => true, 'unlocked' => true]);
    }

    public function change(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string', 'max:72'],
            'newPassword' => ['required', 'string', 'min:5', 'max:72'],
        ]);

        $session = RequireSession::session($request);

        if ($session === null) {
            return $this->legacyResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($this->tooManyAttempts($request->ip())) {
            return $this->legacyResponse(
                ['error' => 'throttled', 'message' => self::THROTTLED_MESSAGE],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        if (! $this->matches($session->user, (string) $validated['currentPassword'])) {
            $this->recordFailure($request, $session->user?->getUsername());

            return $this->legacyResponse(
                ['error' => 'invalid', 'message' => self::FAILED_MESSAGE],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $user = $session->user;
        $user->setAttribute(UserInterface::SETTINGS_PASSWORD_HASH, Hash::make((string) $validated['newPassword']));
        $user->save();

        // The new password is the one they just typed, so Settings stay open.
        $this->sessions->unlockSettings($session);

        return $this->legacyResponse(['ok' => true]);
    }

    /** The stored hash, or the placeholder that config carries until it is changed. */
    private function matches(?\App\Models\User $user, string $password): bool
    {
        $stored = $user?->getSettingsPasswordHash();

        if (is_string($stored) && $stored !== '') {
            return Hash::check($password, $stored);
        }

        return hash_equals((string) config('fusion.admin.settings_password'), $password);
    }

    private function tooManyAttempts(?string $ip): bool
    {
        if (! is_string($ip) || $ip === '') {
            return false;
        }

        return LoginAttempt::query()
            ->where(LoginAttempt::IP, $ip)
            ->where(LoginAttempt::REASON, self::REASON)
            ->where(LoginAttempt::SUCCESS, false)
            ->where(LoginAttempt::CREATED_AT, '>', now()->subMinutes(self::ATTEMPT_WINDOW_MINUTES))
            ->count() >= self::MAX_ATTEMPTS;
    }

    private function recordFailure(Request $request, ?string $username): void
    {
        LoginAttempt::query()->create([
            LoginAttempt::IP => (string) $request->ip(),
            LoginAttempt::USERNAME => $username,
            LoginAttempt::SUCCESS => false,
            // Deliberately not counted: this is not a sign-in attempt.
            LoginAttempt::COUNTED => false,
            LoginAttempt::REASON => self::REASON,
            LoginAttempt::USER_AGENT => $request->userAgent(),
            LoginAttempt::CREATED_AT => now(),
        ]);
    }
}
