<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\SaveGitlabConnectionAction;
use App\Actions\Settings\TestGitlabConnectionAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSession;
use App\Repositories\Settings\GitlabConnectionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard's own contract: `{configured, baseUrl, tokenHint, …}` rather than
 * the shared envelope, and the token is never returned.
 */
class GitlabSettingsController extends Controller
{
    public function __construct(private GitlabConnectionRepository $connections = new GitlabConnectionRepository) {}

    public function show(): JsonResponse
    {
        $row = $this->connections->row();

        if ($row === null) {
            return $this->legacyResponse(['configured' => false]);
        }

        return $this->legacyResponse([
            'configured' => true,
            'baseUrl' => $row->getBaseUrl(),
            'tokenHint' => $row->getTokenHint(),
            'hasCaCertificate' => $row->getCaCertificate() !== null,
            'lastVerifiedAt' => $row->getLastVerifiedAt()?->toIso8601String(),
            'lastVerifyOk' => $row->getLastVerifyOk(),
            'lastVerifyError' => $row->getLastVerifyError(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'baseUrl' => ['required', 'string', 'max:500'],
            'token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'caCertificate' => ['sometimes', 'nullable', 'string', 'max:50000'],
        ]);

        $result = run(new SaveGitlabConnectionAction(
            $validated['baseUrl'],
            $validated['token'] ?? null,
            $validated['caCertificate'] ?? null,
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
            'baseUrl' => $result['baseUrl'],
            'tokenHint' => $result['tokenHint'],
            'hasCaCertificate' => $result['hasCaCertificate'],
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'baseUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'caCertificate' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'testRepo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = run(new TestGitlabConnectionAction(
            $validated['baseUrl'] ?? null,
            $validated['token'] ?? null,
            array_key_exists('caCertificate', $validated) ? $validated['caCertificate'] : null,
            $validated['testRepo'] ?? null,
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
