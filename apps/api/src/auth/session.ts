import { createHash, randomBytes } from "node:crypto";
import { and, eq, gt, isNull, ne } from "drizzle-orm";

import { config } from "../config.js";
import { db } from "../db/index.js";
import { sessions, users } from "../db/schema.js";

function hashToken(token: string): string {
  return createHash("sha256").update(token).digest("hex");
}

export type SessionUser = {
  id: string;
  username: string;
  role: string;
};

export type ResolvedSession = {
  sessionId: string;
  user: SessionUser;
};

export async function createSession(
  userId: string,
  meta: { ip: string | null; userAgent: string | null },
): Promise<{ token: string; expiresAt: Date }> {
  const token = randomBytes(32).toString("base64url");
  const expiresAt = new Date(Date.now() + config.sessionTtlMs);

  await db.insert(sessions).values({
    userId,
    tokenHash: hashToken(token),
    ip: meta.ip,
    userAgent: meta.userAgent,
    expiresAt,
  });

  return { token, expiresAt };
}

export async function resolveSession(token: string): Promise<ResolvedSession | null> {
  const rows = await db
    .select({
      sessionId: sessions.id,
      id: users.id,
      username: users.username,
      role: users.role,
    })
    .from(sessions)
    .innerJoin(users, eq(users.id, sessions.userId))
    .where(
      and(
        eq(sessions.tokenHash, hashToken(token)),
        isNull(sessions.revokedAt),
        gt(sessions.expiresAt, new Date()),
      ),
    )
    .limit(1);

  const row = rows[0];
  if (!row) return null;

  return {
    sessionId: row.sessionId,
    user: { id: row.id, username: row.username, role: row.role },
  };
}

export async function touchSession(sessionId: string): Promise<void> {
  await db
    .update(sessions)
    .set({ lastSeenAt: new Date() })
    .where(eq(sessions.id, sessionId));
}

export async function revokeSession(sessionId: string): Promise<void> {
  await db
    .update(sessions)
    .set({ revokedAt: new Date() })
    .where(eq(sessions.id, sessionId));
}

/**
 * Revokes every active session for a user. Pass `exceptSessionId` to keep the
 * caller's own session alive, which is what a password change should do.
 */
export async function revokeAllSessions(
  userId: string,
  exceptSessionId?: string,
): Promise<number> {
  const conditions = [eq(sessions.userId, userId), isNull(sessions.revokedAt)];
  if (exceptSessionId) {
    conditions.push(ne(sessions.id, exceptSessionId));
  }

  const revoked = await db
    .update(sessions)
    .set({ revokedAt: new Date() })
    .where(and(...conditions))
    .returning({ id: sessions.id });

  return revoked.length;
}
