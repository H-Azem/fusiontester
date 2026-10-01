<?php

namespace App\Http\Controllers\Hooks;

use App\Actions\Automation\FireAutomationTriggerAction;
use App\Http\Controllers\Controller;
use App\Repositories\Automation\AutomationTriggerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GitLab posts here on every push. The URL carries the rule's own token, so a leak
 * reaches one rule and nothing else.
 *
 * Everything a push can ask for answers 202: GitLab treats a non-2xx as a delivery
 * failure and disables a webhook that keeps failing, and "this push does not
 * interest me" is not a failure.
 */
class GitlabWebhookController extends Controller
{
    const DELETE_SHA = '0000000000000000000000000000000000000000';

    public function __construct(private AutomationTriggerRepository $triggers = new AutomationTriggerRepository) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $trigger = $this->triggers->findByToken($token);

        if ($trigger === null) {
            return response()->json(['error' => 'unknown_trigger'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            $payload = [];
        }

        $event = strtolower((string) ($request->header('X-Gitlab-Event') ?? $payload['object_kind'] ?? ''));

        if (! str_contains($event, 'push')) {
            return response()->json(['ok' => true, 'ignored' => 'not_a_push'], Response::HTTP_ACCEPTED);
        }

        $sha = (string) ($payload['after'] ?? '');

        // Removing a branch arrives as a push with an all-zero head.
        if ($sha === self::DELETE_SHA) {
            return response()->json(['ok' => true, 'ignored' => 'branch_removed'], Response::HTTP_ACCEPTED);
        }

        $ref = (string) ($payload['ref'] ?? '');
        $branch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : '';

        if ($branch === '') {
            return response()->json(['ok' => true, 'ignored' => 'not_a_branch'], Response::HTTP_ACCEPTED);
        }

        $result = run(new FireAutomationTriggerAction($trigger, $branch, $sha));

        return response()->json(['ok' => true, 'branch' => $branch] + $result, Response::HTTP_ACCEPTED);
    }
}
