<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses state-changing requests that arrive from another origin.
 *
 * Only a present Origin header is compared, so command-line and server-to-server
 * calls keep working while a browser on another site is turned away.
 */
class EnforceTrustedOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && rtrim($origin, '/') !== rtrim((string) config('fusion.web_origin'), '/')) {
            return response()->json(['error' => 'forbidden_origin'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
