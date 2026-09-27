<?php

namespace App\Actions\Auth;

use App\Models\AuditEntry;
use App\Models\AuthSession;
use App\Models\User;
use App\Repositories\Auth\AuthSessionRepository;
use App\Repositories\Auth\UserRepository;
use Illuminate\Support\Facades\Hash;

/**
 * Changes the password and signs every other session out. The current session is
 * kept so the user is not thrown out of the tab they are working in.
 */
class ChangePasswordAction
{
    const MIN_LENGTH = 12;

    public function __construct(
        private User $user,
        private AuthSession $session,
        private string $currentPassword,
        private string $newPassword,
        private ?string $ip,
        private ?string $userAgent,
        private UserRepository $users = new UserRepository,
        private AuthSessionRepository $sessions = new AuthSessionRepository,
    ) {}

    public function handle(): array
    {
        if (! Hash::check($this->currentPassword, (string) $this->user->getPasswordHash())) {
            // Already authenticated, so this is not counted towards the IP block:
            // otherwise a user could lock their own network out from the inside.
            return ['ok' => false, 'message' => 'Current password is incorrect.'];
        }

        $strengthError = $this->strengthError();
        if ($strengthError !== null) {
            return ['ok' => false, 'message' => $strengthError, 'weak' => true];
        }

        $this->users->updatePassword($this->user, $this->newPassword);
        $revoked = $this->sessions->revokeAllFor((int) $this->user->getId(), (string) $this->session->getId());

        run(new WriteAuditAction(
            AuditEntry::ACTION_PASSWORD_CHANGED,
            (int) $this->user->getId(),
            $this->ip,
            $this->userAgent,
            ['revokedSessions' => $revoked]
        ));

        return ['ok' => true, 'revokedSessions' => $revoked];
    }

    private function strengthError(): ?string
    {
        if (strlen($this->newPassword) < self::MIN_LENGTH) {
            return sprintf('New password must be at least %d characters.', self::MIN_LENGTH);
        }

        if (mb_strtolower($this->newPassword) === mb_strtolower((string) $this->user->getUsername())) {
            return 'New password must not be the username.';
        }

        return null;
    }
}
