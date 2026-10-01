<?php

namespace App\Repositories\Settings;

use App\Models\ProjectSetting;

/**
 * Per-project preferences the dashboard remembers between runs: the shape of the
 * device, which lane the app should be driven on, and any extra build defines.
 */
class ProjectSettingRepository
{
    public function row(int $projectId): ?ProjectSetting
    {
        return ProjectSetting::query()
            ->where(ProjectSetting::PROJECT_ID, $projectId)
            ->first();
    }

    /**
     * Only the keys actually present are written, so the sheet can save one choice
     * without having to send the others back.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(int $projectId, array $values): ProjectSetting
    {
        $present = array_intersect_key($values, array_flip([
            ProjectSetting::ORIENTATION,
            ProjectSetting::PLATFORM,
            ProjectSetting::DART_DEFINES,
        ]));

        // A key the caller left out arrives as null, and those columns are NOT NULL:
        // dropping them is what lets the sheet save one choice without touching the
        // others. An empty string still means "clear this", and is kept.
        $present = array_filter($present, static fn (mixed $value) => $value !== null);

        return ProjectSetting::query()->updateOrCreate(
            [ProjectSetting::PROJECT_ID => $projectId],
            $present
        );
    }
}
