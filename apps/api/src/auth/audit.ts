import { db } from "../db/index.js";
import { auditLog } from "../db/schema.js";

export type AuditAction =
  | "login.success"
  | "login.failed"
  | "login.blocked"
  | "login.captcha_failed"
  | "logout"
  | "password.changed"
  | "ip.blocked"
  | "ip.released"
  | "gitlab.connection_saved"
  | "gitlab.connection_tested"
  | "run.queued";

export async function recordAudit(entry: {
  action: AuditAction;
  actorUserId?: string | null;
  ip?: string | null;
  userAgent?: string | null;
  metadata?: Record<string, unknown>;
}): Promise<void> {
  await db.insert(auditLog).values({
    action: entry.action,
    actorUserId: entry.actorUserId ?? null,
    ip: entry.ip ?? null,
    userAgent: entry.userAgent ?? null,
    metadata: entry.metadata ?? null,
  });
}
