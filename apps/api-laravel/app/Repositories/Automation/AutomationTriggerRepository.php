<?php

namespace App\Repositories\Automation;

use App\Models\AutomationTrigger;
use App\Services\Settings\SecretBox;
use Illuminate\Support\Collection;

class AutomationTriggerRepository
{
    public function __construct(private SecretBox $box = new SecretBox) {}

    /** @return Collection<int, AutomationTrigger> */
    public function all(): Collection
    {
        return AutomationTrigger::query()->orderBy('id')->get();
    }

    public function find(int $id): ?AutomationTrigger
    {
        return AutomationTrigger::query()->find($id);
    }

    /** The webhook presents the token itself; the table only keeps its hash. */
    public function findByToken(string $token): ?AutomationTrigger
    {
        if ($token === '') {
            return null;
        }

        return AutomationTrigger::query()
            ->where(AutomationTrigger::WEBHOOK_TOKEN_HASH, hash('sha256', $token))
            ->first();
    }

    public function token(AutomationTrigger $trigger): string
    {
        return $this->box->decrypt((string) $trigger->getWebhookTokenCiphertext());
    }

    public function create(array $values): AutomationTrigger
    {
        $token = bin2hex(random_bytes(24));

        return AutomationTrigger::query()->create($values + [
            AutomationTrigger::WEBHOOK_TOKEN_CIPHERTEXT => $this->box->encrypt($token),
            AutomationTrigger::WEBHOOK_TOKEN_HASH => hash('sha256', $token),
            AutomationTrigger::WEBHOOK_TOKEN_HINT => $this->box->hint($token),
        ]);
    }

    public function update(AutomationTrigger $trigger, array $values): AutomationTrigger
    {
        $trigger->fill($values);
        $trigger->save();

        return $trigger->refresh();
    }

    public function delete(AutomationTrigger $trigger): void
    {
        $trigger->delete();
    }

    public function recordFire(AutomationTrigger $trigger, string $branch, string $sha): void
    {
        $trigger->setAttribute(AutomationTrigger::LAST_FIRED_AT, now());
        $trigger->setAttribute(AutomationTrigger::LAST_FIRED_BRANCH, $branch);
        $trigger->setAttribute(AutomationTrigger::LAST_FIRED_SHA, $sha);
        $trigger->setAttribute(AutomationTrigger::FIRE_COUNT, (int) $trigger->getFireCount() + 1);
        $trigger->save();
    }
}
