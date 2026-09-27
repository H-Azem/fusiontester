<?php

namespace App\Actions\Auth;

use App\Models\AuditEntry;

/**
 * Writes one audit row. Kept as an action so callers never build the entry by
 * hand and the action names stay in one place.
 */
class WriteAuditAction
{
    public function __construct(
        private string $action,
        private ?int $actorUserId = null,
        private ?string $ip = null,
        private ?string $userAgent = null,
        private array $metadata = [],
    ) {}

    public function handle(): AuditEntry
    {
        return AuditEntry::query()->create([
            AuditEntry::ACTION => $this->action,
            AuditEntry::ACTOR_USER_ID => $this->actorUserId,
            AuditEntry::IP => $this->ip,
            AuditEntry::USER_AGENT => $this->userAgent,
            AuditEntry::METADATA => $this->metadata === [] ? null : $this->metadata,
            AuditEntry::CREATED_AT => now(),
        ]);
    }
}
