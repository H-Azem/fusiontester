<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::PROJECT_ID, self::PROJECT_PATH, self::BRANCH, self::TITLE])]
class ManualApp extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'manual_apps';

    const PROJECT_ID = 'project_id';

    const PROJECT_PATH = 'project_path';

    const BRANCH = 'branch';

    /** What a person calls this app; the repository name is not always enough. */
    const TITLE = 'title';

    protected $table = self::TABLE;
}
