<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

abstract class Controller
{
    const DEFAULT_PAGE_SIZE = 15;

    const FILTERS = 'filters';

    const QUERYABLES = 'queryables';

    const PER_PAGE = 'per_page';

    const STATUS = 'status';

    const ERRORS = 'errors';

    const MODEL = 'model';

    const RESPONSE = 'response';

    const MESSAGE = 'message';

    public function sendResponse(
        array|null|AnonymousResourceCollection $content = [],
        ?string $message = null,
        int $status = Response::HTTP_OK,
        array $headers = []
    ): JsonResponse {
        $response = [
            self::RESPONSE => $content,
            self::STATUS => $status,
        ];

        if ($message) {
            $response[self::MESSAGE] = $message;
        }

        return response()->json($response, $status, $headers);
    }

    public function sendErrorResponse(
        ?string $message = null,
        int $status = Response::HTTP_UNAUTHORIZED,
        array $errors = [],
        ?array $response = null
    ): JsonResponse {
        $toResponse = [
            self::MESSAGE => $message,
            self::ERRORS => $errors,
        ];

        if ($response) {
            $toResponse[self::RESPONSE] = $response;
        }

        return response()->json($toResponse, $status);
    }

    /**
     * The dashboard was written against an older, flat contract, and the style
     * guide is explicit that an existing project's real contracts win over the
     * envelope. Auth therefore answers with the legacy shapes while the
     * vocabulary above stays available for new endpoints.
     */
    protected function legacyResponse(array $payload, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($payload, $status);
    }
}
