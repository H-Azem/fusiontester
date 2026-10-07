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
    /** The newest frame of the live view; overwritten in place as the run goes. */
    const LIVE_FRAME = 'live.jpg';

    /** The AI lane's verdict: every goal with PASS/FAIL and its evidence. */
    const AI_REPORT = 'ai-report.json';

    /** Downscaled frames the AI lane captured when a goal failed. */
    const AI_SHOTS = 'ai';

    /** Exactly what the lane was told, so a run that goes somewhere odd is explainable. */
    const AI_MISSION = 'ai-mission.txt';

    /** The lane's own NDJSON stream: models, tools and permission decisions. */
    const AI_TRANSCRIPT = 'ai-transcript.ndjson';

    public static function workspace(string $runId): string
    {
        return storage_path('app/fusion/runs/'.$runId);
    }

    public static function liveFrame(string $runId): string
    {
        return self::path($runId, self::LIVE_FRAME);
    }

    public static function path(string $runId, string $file): string
    {
        return self::workspace($runId).'/'.$file;
    }

    public static function hasScreenshot(string $runId, string $file): bool
    {
        return is_file(self::path($runId, $file));
    }

    public static function hasAiReport(string $runId): bool
    {
        return is_file(self::path($runId, self::AI_REPORT));
    }
}
