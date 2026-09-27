<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::PROJECT_ID, self::ORIENTATION, self::DART_DEFINES])]
class ProjectSetting extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'project_settings';

    const PROJECT_ID = 'project_id';

    const ORIENTATION = 'orientation';

    const DART_DEFINES = 'dart_defines';

    const ORIENTATION_HORIZONTAL = 'horizontal';

    const ORIENTATION_VERTICAL = 'vertical';

    const ORIENTATION_DEFAULT = self::ORIENTATION_HORIZONTAL;

    protected $table = self::TABLE;
}
