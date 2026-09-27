<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::KIND, self::PROJECT_ID, self::PROJECT_PATH, self::BRANCH])]
class Pin extends Model implements BaseModelInterface
{
    use HasConstantGetters;

    const TABLE = 'pins';

    const KIND = 'kind';

    const PROJECT_ID = 'project_id';

    const PROJECT_PATH = 'project_path';

    const BRANCH = 'branch';

    const KIND_REPOSITORY = 'repository';

    const KIND_BRANCH = 'branch';

    /** Sentinel for a repository pin, so the unique index stays meaningful. */
    const NO_BRANCH = '';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [self::CREATED_AT => 'datetime'];
    }
}
