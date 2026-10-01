<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps Settings shut until the settings password has been entered for this
 * session. The session already proves who is signed in; this proves they are
 * allowed to read the credentials that reach the machine under test.
 */
class RequireSettingsUnlock
{
    const MESSAGE = 'Settings are locked. Enter the settings password to continue.';

    public function handle(Request $request, Closure $next): Response
    {
        $session = RequireSession::session($request);

        if ($session === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if ($session->getSettingsUnlockedAt() === null) {
            return response()->json(
                ['error' => 'settings_locked', 'message' => self::MESSAGE],
                Response::HTTP_FORBIDDEN
            );
        }

        return $next($request);
    }
}
