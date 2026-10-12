<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    self::PROJECT_ID,
    self::PROJECT_PATH,
    self::BRANCH,
    self::TITLE,
    self::APK_PATH,
    self::APK_BUILT_AT,
    self::BUILT_SHA,
    self::BUILD_ERROR,
])]
class ManualApp extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'manual_apps';

    const PROJECT_ID = 'project_id';

    const PROJECT_PATH = 'project_path';

    const BRANCH = 'branch';

    /** What a person calls this app; the repository name is not always enough. */
    const TITLE = 'title';

    /** Where the built APK lives; replaced on every new commit. */
    const APK_PATH = 'apk_path';

    const APK_BUILT_AT = 'apk_built_at';

    const BUILT_SHA = 'built_sha';

    /** Why the last build failed, so the page can say so instead of staying blank. */
    const BUILD_ERROR = 'build_error';

    protected $table = self::TABLE;

    protected function casts(): array
    {
        return [
            self::APK_BUILT_AT => 'datetime',
        ];
    }
}
