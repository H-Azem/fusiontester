<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AttemptLoginAction;
use App\Actions\Auth\ChangePasswordAction;
use App\Actions\Auth\RevokeSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Middleware\RequireSession;
use App\Repositories\Auth\AuthSessionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers with the contract the existing dashboard already reads; the shared
 * envelope lives in the base controller but is intentionally not used here.
 */
class AuthController extends Controller
{
    const AUTH_FAILED_MESSAGE = 'Invalid username, password, or captcha.';

    const BLOCKED_MESSAGE = 'Too many failed attempts. Try again later.';

    public function __construct(private AuthSessionRepository $sessions = new AuthSessionRepository) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $outcome = run(new AttemptLoginAction(
            (string) $request->string('username'),
            (string) $request->string('password'),
            (string) $request->string('captchaId'),
            (string) $request->string('captchaText'),
            $request->ip(),
            $request->userAgent()
        ));

        if ($outcome->status === 'blocked') {
            return $this->legacyResponse([
                'error' => 'blocked',
                'message' => self::BLOCKED_MESSAGE,
                'blockedUntil' => $outcome->blockedUntil?->format(DATE_ATOM),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        if ($outcome->status === 'invalid') {
            return $this->legacyResponse(
                ['error' => 'invalid', 'message' => self::AUTH_FAILED_MESSAGE],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $cookie = cookie(
            (string) config('fusion.session.cookie'),
            (string) $outcome->token,
            now()->diffInMinutes($outcome->expiresAt),
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax'
        );

        return $this->legacyResponse(
            ['user' => (new UserResource($outcome->user))->resolve()],
            Response::HTTP_OK
        )->withCookie($cookie);
    }

    public function me(Request $request): JsonResponse
    {
        $session = RequireSession::session($request);

        return $this->legacyResponse([
            'user' => (new UserResource($session?->user))->resolve(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Logout is deliberately not behind the session middleware: signing out
        // must succeed even when the session already expired, so the cookie still
        // gets cleared. The session is therefore resolved here.
        $token = $request->cookie((string) config('fusion.session.cookie'));
        $session = is_string($token) && $token !== '' ? $this->sessions->findActiveByToken($token) : null;

        run(new RevokeSessionAction($session, $request->ip(), $request->userAgent()));

        return $this->legacyResponse(['ok' => true])
            ->withCookie(cookie()->forget((string) config('fusion.session.cookie'), '/'));
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $session = RequireSession::session($request);

        $result = run(new ChangePasswordAction(
            $session->user,
            $session,
            (string) $request->string('currentPassword'),
            (string) $request->string('newPassword'),
            $request->ip(),
            $request->userAgent()
        ));

        if (! $result['ok']) {
            return $this->legacyResponse(
                [
                    'error' => ($result['weak'] ?? false) ? 'weak_password' : 'invalid',
                    'message' => $result['message'],
                ],
                ($result['weak'] ?? false) ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNAUTHORIZED
            );
        }

        return $this->legacyResponse([
            'ok' => true,
            'revokedSessions' => $result['revokedSessions'],
        ]);
    }
}
