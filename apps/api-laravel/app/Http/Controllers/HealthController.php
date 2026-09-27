<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Used by the container healthcheck, so it must not touch the database.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->legacyResponse([
            'status' => 'ok',
            'service' => 'fusion-tester-api',
            'version' => '0.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
