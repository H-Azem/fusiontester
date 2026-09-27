<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts the credentials this app stores on behalf of the user.
 *
 * Only the ciphertext is persisted, and hints exist so the UI can say which
 * credential is stored without ever disclosing it.
 */
class SecretBox
{
    public function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $payload): string
    {
        return Crypt::decryptString($payload);
    }

    public function hint(string $secret): string
    {
        if (mb_strlen($secret) <= 4) {
            return '••••';
        }

        return '••••'.mb_substr($secret, -4);
    }
}
