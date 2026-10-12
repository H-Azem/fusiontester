<?php

namespace App\Repositories\Manual;

use App\Models\ManualApp;
use Illuminate\Support\Collection;

/**
 * The apps a person builds to install by hand: which repository, which branch, and
 * what to call it.
 */
class ManualAppRepository
{
    /** @return Collection<int, ManualApp> */
    public function all(): Collection
    {
        return ManualApp::query()->orderBy(ManualApp::ID)->get();
    }

    public function find(int $id): ?ManualApp
    {
        return ManualApp::query()->find($id);
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): ManualApp
    {
        return ManualApp::query()->create($values);
    }

    public function delete(ManualApp $app): void
    {
        $app->delete();
    }
}
