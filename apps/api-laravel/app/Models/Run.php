<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    self::PROJECT_ID,
    self::PROJECT_PATH,
    self::BRANCH,
    self::TESTS,
    self::RUN_KINDS,
    self::ENVIRONMENTS,
    self::STATUS,
    self::CURRENT_STEP,
    self::ORIENTATION,
    self::PLATFORM,
    self::LIVE,
    self::DART_DEFINES,
    self::APK_TOKEN,
    self::APK_DOWNLOADED_AT,
    self::ERROR_MESSAGE,
    self::CREATED_AT,
    self::STARTED_AT,
    self::FINISHED_AT,
])]
class Run extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'runs';

    const PROJECT_ID = 'project_id';

    const PROJECT_PATH = 'project_path';

    const BRANCH = 'branch';

    const TESTS = 'tests';

    const RUN_KINDS = 'run_kinds';

    const ENVIRONMENTS = 'environments';

    const STATUS = 'status';

    const CURRENT_STEP = 'current_step';

    const ORIENTATION = 'orientation';

    const PLATFORM = 'platform';

    const LIVE = 'live';

    const DART_DEFINES = 'dart_defines';

    const ERROR_MESSAGE = 'error_message';

    const STARTED_AT = 'started_at';

    const FINISHED_AT = 'finished_at';

    const STATUS_QUEUED = 'queued';

    const STATUS_RUNNING = 'running';

    const STATUS_PASSED = 'passed';

    const STATUS_FAILED = 'failed';

    const STEP_QUEUED = 'queued';

    const STEP_DONE = 'done';

    const ORIENTATION_DEFAULT = ProjectSetting::ORIENTATION_DEFAULT;

    const PLATFORM_WEB = ProjectSetting::PLATFORM_WEB;

    const PLATFORM_ANDROID = ProjectSetting::PLATFORM_ANDROID;

    const PLATFORM_DEFAULT = ProjectSetting::PLATFORM_DEFAULT;

    const KIND_MAESTRO = 'maestro';

    const KIND_AI = 'ai';

    /** Build the APK and hand it over; run no tests and drive no device. */
    const KIND_MANUAL = 'manual';

    /**
     * Where a run's one-time APK hand-off lives. Cleared the moment the file is
     * downloaded, which is what makes the link single-use.
     */
    const APK_TOKEN = 'apk_token';

    /** Set the moment the APK is taken, so the page can say the link is spent. */
    const APK_DOWNLOADED_AT = 'apk_downloaded_at';

    const ENVIRONMENT_DEVELOPMENT = 'development';

    const ENVIRONMENT_PRODUCTION = 'production';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::TESTS => 'array',
            self::RUN_KINDS => 'array',
            self::ENVIRONMENTS => 'array',
            self::LIVE => 'boolean',
            self::APK_DOWNLOADED_AT => 'datetime',
            self::CREATED_AT => 'datetime',
            self::STARTED_AT => 'datetime',
            self::FINISHED_AT => 'datetime',
        ];
    }

    public function steps()
    {
        return $this->hasMany(RunStep::class, RunStep::RUN_ID)->orderBy(RunStep::POSITION);
    }
}
