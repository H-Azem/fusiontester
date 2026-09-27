<?php

namespace App\Http\Resources;

/**
 * Where a run's workspace and its screenshots live on disk.
 *
 * Keeping this in one place means the pipeline, the resource and the download
 * routes can never disagree about a path.
 */
class RunArtifacts
{
    public static function workspace(string $runId): string
    {
        return storage_path('app/fusion/runs/'.$runId);
    }

    public static function path(string $runId, string $file): string
    {
        return self::workspace($runId).'/'.$file;
    }

    public static function hasScreenshot(string $runId, string $file): bool
    {
        return is_file(self::path($runId, $file));
    }
}
