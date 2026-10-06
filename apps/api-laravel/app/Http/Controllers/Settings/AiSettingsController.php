<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\SaveAiConnectionAction;
use App\Actions\Settings\TestAiConnectionAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSession;
use App\Repositories\Settings\AiConnectionRepository;
use App\Services\Ai\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiSettingsController extends Controller
{
    public function __construct(private AiConnectionRepository $connections = new AiConnectionRepository) {}

    public function show(): JsonResponse
    {
        $row = $this->connections->row();

        if ($row === null) {
            return $this->legacyResponse([
                'configured' => false,
                'openaiBaseUrl' => 'https://api.openai.com/v1',
                'jevBaseUrl' => 'https://api.typesafe.ai',
                'aiLaneEnabled' => true,
                'shareReportToTelegram' => false,
            ]);
        }

        return $this->legacyResponse([
            'configured' => true,
            'openaiBaseUrl' => $row->getOpenaiBaseUrl(),
            'openaiModel' => $row->getOpenaiModel(),
            'openaiTokenHint' => $row->getOpenaiTokenHint(),
            'jevBaseUrl' => $row->getJevBaseUrl(),
            'jevTokenHint' => $row->getJevTokenHint(),
            'maxSteps' => $row->getMaxSteps(),
            'aiLaneEnabled' => (bool) $row->getAiLaneEnabled(),
            'shareReportToTelegram' => (bool) $row->getShareReportToTelegram(),
            'lastVerifiedAt' => $row->getLastVerifiedAt()?->toIso8601String(),
            'lastVerifyOk' => $row->getLastVerifyOk(),
            'lastVerifyError' => $row->getLastVerifyError(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'openaiBaseUrl' => ['required', 'string', 'max:500'],
            'openaiModel' => ['required', 'string', 'max:200'],
            'openaiToken' => ['sometimes', 'nullable', 'string', 'max:500'],
            'jevBaseUrl' => ['required', 'string', 'max:500'],
            'jevToken' => ['sometimes', 'nullable', 'string', 'max:500'],
            'maxSteps' => ['sometimes', 'nullable', 'integer', 'min:3', 'max:80'],
            'aiLaneEnabled' => ['sometimes', 'boolean'],
            'shareReportToTelegram' => ['sometimes', 'boolean'],
        ]);

        $result = run(new SaveAiConnectionAction(
            $validated,
            RequireSession::actorId($request),
            $request->ip()
        ));

        if (! $result['ok']) {
            return $this->legacyResponse(
                ['error' => $result['error'], 'message' => $result['message']],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->legacyResponse([
            'configured' => true,
            'openaiBaseUrl' => $result['openaiBaseUrl'],
            'openaiModel' => $result['openaiModel'],
            'openaiTokenHint' => $result['openaiTokenHint'],
            'jevBaseUrl' => $result['jevBaseUrl'],
            'jevTokenHint' => $result['jevTokenHint'],
            'maxSteps' => $result['maxSteps'],
            'aiLaneEnabled' => $result['aiLaneEnabled'],
            'shareReportToTelegram' => $result['shareReportToTelegram'],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'openaiBaseUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'openaiModel' => ['sometimes', 'nullable', 'string', 'max:200'],
            'openaiToken' => ['sometimes', 'nullable', 'string', 'max:500'],
            'jevBaseUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'jevToken' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = run(new TestAiConnectionAction(
            $validated,
            RequireSession::actorId($request),
            $request->ip()
        ));

        if (($result['error'] ?? null) === 'not_configured') {
            return $this->legacyResponse(
                ['error' => 'not_configured', 'message' => $result['message']],
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->legacyResponse($result);
    }
}
