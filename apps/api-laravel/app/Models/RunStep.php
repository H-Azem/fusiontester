<?php

namespace App\Models;

use App\Models\Concerns\HasConstantGetters;
use App\Models\Contracts\BaseModelInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([self::RUN_ID, self::KEY, self::LABEL, self::STATUS, self::OUTPUT, self::POSITION, self::STARTED_AT, self::FINISHED_AT])]
class RunStep extends Model implements BaseModelInterface
{
    use HasConstantGetters, HasUuids;

    const TABLE = 'run_steps';

    const RUN_ID = 'run_id';

    const KEY = 'key';

    const LABEL = 'label';

    const STATUS = 'status';

    const OUTPUT = 'output';

    const POSITION = 'position';

    const STARTED_AT = 'started_at';

    const FINISHED_AT = 'finished_at';

    const STATUS_PENDING = 'pending';

    const STATUS_RUNNING = 'running';

    const STATUS_DONE = 'done';

    const STATUS_FAILED = 'failed';

    const STATUS_SKIPPED = 'skipped';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            self::POSITION => 'integer',
            self::STARTED_AT => 'datetime',
            self::FINISHED_AT => 'datetime',
        ];
    }
}
