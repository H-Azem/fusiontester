<?php

namespace App\Models\Contracts;

interface UserInterface extends BaseModelInterface
{
    const TABLE = 'users';

    const USERNAME = 'username';

    const PASSWORD_HASH = 'password_hash';

    /** A second password, asked for before Settings will open. */
    const SETTINGS_PASSWORD_HASH = 'settings_password_hash';

    const ROLE = 'role';

    const PASSWORD_CHANGED_AT = 'password_changed_at';

    const ROLE_ADMIN = 'admin';
}
