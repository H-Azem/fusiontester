<?php

namespace App\Models\Contracts;

/**
 * Columns shared by every model here.
 *
 * Model already declares CREATED_AT and UPDATED_AT, and redeclaring them would
 * make the constant ambiguous, so those two are inherited rather than repeated.
 */
interface BaseModelInterface
{
    const ID = 'id';

    const UUID = 'uuid';
}
