<?php

namespace App\Actions\Auth;

use App\Models\AuditEntry;
use App\Models\AuthSession;
use App\Repositories\Auth\AuthSessionRepository;

/**
 * Signs one session out. Deliberately tolerant: signing out succeeds even when
 * the session has already expired, so the cookie still gets cleared.
 */
class RevokeSessionAction
{
    public function __construct(
        private ?AuthSession $session,
        private ?string $ip,
        private ?string $userAgent,
        private AuthSessionRepository $sessions = new AuthSessionRepository,
    ) {}

    public function handle(): void
    {
        if ($this->session === null) {
            return;
        }

        $this->sessions->revoke($this->session);

        run(new WriteAuditAction(
            AuditEntry::ACTION_LOGOUT,
            (int) $this->session->getUserId(),
            $this->ip,
            $this->userAgent
        ));
    }
}
