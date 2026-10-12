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

    /** The APK replaces whatever was there: only the newest build is kept. */
    public function recordBuild(ManualApp $app, string $apkPath, string $sha): void
    {
        $app->setAttribute(ManualApp::APK_PATH, $apkPath);
        $app->setAttribute(ManualApp::APK_BUILT_AT, now());
        $app->setAttribute(ManualApp::BUILT_SHA, $sha);
        $app->setAttribute(ManualApp::BUILD_ERROR, null);
        $app->save();
    }

    public function recordFailure(ManualApp $app, string $error): void
    {
        $app->setAttribute(ManualApp::BUILD_ERROR, $error);
        $app->save();
    }
}
