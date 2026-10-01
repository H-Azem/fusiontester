<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AutomationTrigger;
use App\Repositories\Automation\AutomationTriggerRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rules that turn pushes into runs. Each one shows the webhook URL to paste into
 * GitLab; the token in it is stored hashed for lookup and encrypted for display.
 */
class AutomationTriggerController extends Controller
{
    public function __construct(private AutomationTriggerRepository $triggers = new AutomationTriggerRepository) {}

    public function index(): JsonResponse
    {
        return $this->legacyResponse([
            'triggers' => $this->triggers->all()
                ->map(fn (AutomationTrigger $trigger) => $this->present($trigger))
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $trigger = $this->triggers->create($this->validated($request));

        return $this->legacyResponse(
            ['trigger' => $this->present($trigger)],
            Response::HTTP_CREATED
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $trigger = $this->triggers->find($id);

        if ($trigger === null) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'branchPattern' => ['sometimes', 'string', 'max:200'],
            'tests' => ['sometimes', 'array', 'max:200'],
            'tests.*' => ['string', 'max:300'],
            'runKinds' => ['sometimes', 'array', 'min:1', 'max:2'],
            'runKinds.*' => ['in:maestro,ai'],
            'environments' => ['sometimes', 'array', 'min:1', 'max:2'],
            'environments.*' => ['in:development,production'],
            'orientation' => ['sometimes', 'in:horizontal,vertical'],
            'platform' => ['sometimes', 'in:web,android'],
            'live' => ['sometimes', 'boolean'],
            'dartDefines' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $values = [];

        foreach ([
            'branchPattern' => AutomationTrigger::BRANCH_PATTERN,
            'tests' => AutomationTrigger::TESTS,
            'runKinds' => AutomationTrigger::RUN_KINDS,
            'environments' => AutomationTrigger::ENVIRONMENTS,
            'orientation' => AutomationTrigger::ORIENTATION,
            'platform' => AutomationTrigger::PLATFORM,
            'live' => AutomationTrigger::LIVE,
            'dartDefines' => AutomationTrigger::DART_DEFINES,
            'enabled' => AutomationTrigger::ENABLED,
        ] as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $values[$column] = $validated[$input];
            }
        }

        return $this->legacyResponse([
            'trigger' => $this->present($this->triggers->update($trigger, $values)),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $trigger = $this->triggers->find($id);

        if ($trigger === null) {
            return $this->legacyResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $this->triggers->delete($trigger);

        return $this->legacyResponse(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'projectId' => ['required', 'integer', 'min:1'],
            'projectPath' => ['required', 'string', 'max:500'],
            'branchPattern' => ['required', 'string', 'max:200'],
            'tests' => ['sometimes', 'array', 'max:200'],
            'tests.*' => ['string', 'max:300'],
            'runKinds' => ['sometimes', 'array', 'min:1', 'max:2'],
            'runKinds.*' => ['in:maestro,ai'],
            'environments' => ['sometimes', 'array', 'min:1', 'max:2'],
            'environments.*' => ['in:development,production'],
            'orientation' => ['sometimes', 'in:horizontal,vertical'],
            'platform' => ['sometimes', 'in:web,android'],
            'live' => ['sometimes', 'boolean'],
            'dartDefines' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return [
            AutomationTrigger::PROJECT_ID => (int) $validated['projectId'],
            AutomationTrigger::PROJECT_PATH => (string) $validated['projectPath'],
            AutomationTrigger::BRANCH_PATTERN => (string) $validated['branchPattern'],
            AutomationTrigger::TESTS => $validated['tests'] ?? [],
            AutomationTrigger::RUN_KINDS => $validated['runKinds'] ?? ['maestro'],
            AutomationTrigger::ENVIRONMENTS => $validated['environments'] ?? ['development'],
            AutomationTrigger::ORIENTATION => (string) ($validated['orientation'] ?? 'vertical'),
            AutomationTrigger::PLATFORM => (string) ($validated['platform'] ?? 'android'),
            AutomationTrigger::LIVE => (bool) ($validated['live'] ?? false),
            AutomationTrigger::DART_DEFINES => (string) ($validated['dartDefines'] ?? ''),
            AutomationTrigger::ENABLED => true,
        ];
    }

    /** @return array<string, mixed> */
    private function present(AutomationTrigger $trigger): array
    {
        return [
            'id' => (int) $trigger->getId(),
            'projectId' => (int) $trigger->getProjectId(),
            'projectPath' => $trigger->getProjectPath(),
            'branchPattern' => $trigger->getBranchPattern(),
            'tests' => $trigger->getTests() ?? [],
            'runKinds' => $trigger->getRunKinds() ?? [],
            'environments' => $trigger->getEnvironments() ?? [],
            'orientation' => $trigger->getOrientation(),
            'platform' => $trigger->getPlatform(),
            'live' => (bool) $trigger->getLive(),
            'dartDefines' => $trigger->getDartDefines() ?? '',
            'enabled' => (bool) $trigger->getEnabled(),
            'webhookUrl' => rtrim((string) config('fusion.web_origin'), '/')
                .'/api/hooks/gitlab/'.$this->triggers->token($trigger),
            'lastFiredAt' => $trigger->getLastFiredAt()?->toIso8601String(),
            'lastFiredBranch' => $trigger->getLastFiredBranch(),
            'fireCount' => (int) $trigger->getFireCount(),
        ];
    }
}
