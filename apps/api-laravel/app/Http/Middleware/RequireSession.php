<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use App\Repositories\Auth\AuthSessionRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the session cookie into a live session.
 *
 * The cookie is validated against the database rather than trusted, so a revoked
 * or expired session cannot reach a protected route. The resolved session is
 * attached to the request so controllers read it instead of querying again.
 */
class RequireSession
{
    const SESSION_ATTRIBUTE = 'auth_session';

    public function __construct(private AuthSessionRepository $sessions = new AuthSessionRepository) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie((string) config('fusion.session.cookie'));
        $session = is_string($token) && $token !== '' ? $this->sessions->findActiveByToken($token) : null;

        if (! $session) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $this->sessions->touch($session);
        $request->attributes->set(self::SESSION_ATTRIBUTE, $session);

        return $next($request);
    }

    public static function session(Request $request): ?AuthSession
    {
        $session = $request->attributes->get(self::SESSION_ATTRIBUTE);

        return $session instanceof AuthSession ? $session : null;
    }

    /** The signed-in user's id, for audit rows written outside a controller. */
    public static function actorId(Request $request): ?int
    {
        $userId = self::session($request)?->getUserId();

        return $userId === null ? null : (int) $userId;
    }
}
