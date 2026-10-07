<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::PROJECT_ID, self::ORIENTATION, self::PLATFORM, self::DART_DEFINES, self::AI_CONTEXT])]
class ProjectSetting extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'project_settings';

    const PROJECT_ID = 'project_id';

    const ORIENTATION = 'orientation';

    const PLATFORM = 'platform';

    const DART_DEFINES = 'dart_defines';

    /** What the AI lane must type to get in — a PIN, a login, a setup value. */
    const AI_CONTEXT = 'ai_context';

    const ORIENTATION_HORIZONTAL = 'horizontal';

    const ORIENTATION_VERTICAL = 'vertical';

    const ORIENTATION_DEFAULT = self::ORIENTATION_HORIZONTAL;

    /** The lane a run targets: the built web bundle, or an Android device. */
    const PLATFORM_WEB = 'web';

    const PLATFORM_ANDROID = 'android';

    const PLATFORM_DEFAULT = self::PLATFORM_WEB;

    protected $table = self::TABLE;
}
