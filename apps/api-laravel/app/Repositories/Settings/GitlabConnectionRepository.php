<?php

namespace App\Repositories\Settings;

use App\Models\GitlabConnection;
use App\Services\Gitlab\GitlabConnectionData;
use App\Services\Settings\SecretBox;

/**
 * The connection is a single row: the token is stored as ciphertext and the hint
 * is what the UI shows instead of ever disclosing it.
 */
class GitlabConnectionRepository
{
    public function __construct(private SecretBox $box = new SecretBox) {}

    public function row(): ?GitlabConnection
    {
        return GitlabConnection::query()->first();
    }

    public function data(): ?GitlabConnectionData
    {
        $row = $this->row();

        if ($row === null) {
            return null;
        }

        return new GitlabConnectionData(
            (string) $row->getBaseUrl(),
            $this->box->decrypt((string) $row->getTokenCiphertext()),
            $row->getCaCertificate()
        );
    }

    public function save(string $baseUrl, ?string $token, ?string $caCertificate): GitlabConnection
    {
        $current = $this->row();

        $tokenCiphertext = $token !== null && $token !== ''
            ? $this->box->encrypt($token)
            : (string) $current?->getTokenCiphertext();

        $tokenHint = $token !== null && $token !== ''
            ? $this->box->hint($token)
            : (string) $current?->getTokenHint();

        GitlabConnection::query()->delete();

        return GitlabConnection::query()->create([
            GitlabConnection::BASE_URL => $baseUrl,
            GitlabConnection::TOKEN_CIPHERTEXT => $tokenCiphertext,
            GitlabConnection::TOKEN_HINT => $tokenHint,
            GitlabConnection::CA_CERTIFICATE => $caCertificate,
        ]);
    }

    public function recordVerification(bool $ok, ?string $error): void
    {
        $row = $this->row();
        if ($row === null) {
            return;
        }

        $row->setAttribute(GitlabConnection::LAST_VERIFIED_AT, now());
        $row->setAttribute(GitlabConnection::LAST_VERIFY_OK, $ok);
        $row->setAttribute(GitlabConnection::LAST_VERIFY_ERROR, $error);
        $row->save();
    }
}
