<?php

namespace App\Repositories\Run;

use App\Models\Pin;

/**
 * Pins are application-wide rather than per-user, which matches the single-admin
 * setup, and a repository pin stores an empty sentinel branch so the unique
 * index keeps working.
 */
class PinRepository
{
    public function all(): array
    {
        return Pin::query()
            ->orderBy(Pin::CREATED_AT)
            ->get()
            ->all();
    }

    /** @return array<int, int> */
    public function pinnedRepositoryIds(): array
    {
        return Pin::query()
            ->where(Pin::KIND, Pin::KIND_REPOSITORY)
            ->pluck(Pin::PROJECT_ID)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return array<int, string> */
    public function pinnedBranches(int $projectId): array
    {
        return Pin::query()
            ->where(Pin::KIND, Pin::KIND_BRANCH)
            ->where(Pin::PROJECT_ID, $projectId)
            ->pluck(Pin::BRANCH)
            ->all();
    }

    public function upsert(string $kind, int $projectId, string $projectPath, ?string $branch): Pin
    {
        return Pin::query()->updateOrCreate(
            [
                Pin::KIND => $kind,
                Pin::PROJECT_ID => $projectId,
                Pin::BRANCH => $kind === Pin::KIND_BRANCH ? (string) $branch : Pin::NO_BRANCH,
            ],
            [
                Pin::PROJECT_PATH => $projectPath,
                Pin::CREATED_AT => now(),
            ]
        );
    }

    public function delete(string $kind, int $projectId, ?string $branch): bool
    {
        return Pin::query()
            ->where(Pin::KIND, $kind)
            ->where(Pin::PROJECT_ID, $projectId)
            ->where(Pin::BRANCH, $kind === Pin::KIND_BRANCH ? (string) $branch : Pin::NO_BRANCH)
            ->delete() > 0;
    }
}
