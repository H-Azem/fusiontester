import { and, count, desc, eq, gt, gte, isNotNull, isNull } from "drizzle-orm";

import { config } from "../config.js";
import { db } from "../db/index.js";
import { ipBlocks, loginAttempts } from "../db/schema.js";

export type ActiveBlock = {
  ip: string;
  failedCount: number;
  expiresAt: Date;
};

export async function getActiveBlock(ip: string): Promise<ActiveBlock | null> {
  const rows = await db
    .select({
      ip: ipBlocks.ip,
      failedCount: ipBlocks.failedCount,
      expiresAt: ipBlocks.expiresAt,
    })
    .from(ipBlocks)
    .where(
      and(
        eq(ipBlocks.ip, ip),
        // A manually released block stays released until the IP is blocked again.
        isNull(ipBlocks.releasedAt),
        gt(ipBlocks.expiresAt, new Date()),
      ),
    )
    .limit(1);

  const row = rows[0];
  return row ?? null;
}

/**
 * Records a failed login that should count toward the lockout and blocks the IP
 * once the threshold is reached. Captcha failures are logged separately with
 * `counted: false` so a misread captcha cannot lock a real user out for hours.
 */
export async function recordCountedFailure(
  ip: string,
  username: string | null,
  userAgent: string | null,
  reason: string,
): Promise<{ blocked: boolean; failedCount: number }> {
  await recordAttempt({ ip, username, success: false, counted: true, reason, userAgent });

  const windowStart = new Date(Date.now() - config.failedAttemptWindowMs);

  // A manual unblock hands back a full set of attempts. Without this, the
  // failures that caused the block are still inside the window and the very
  // next mistake re-blocks the IP for another full duration.
  const lastRelease = await db
    .select({ releasedAt: ipBlocks.releasedAt })
    .from(ipBlocks)
    .where(and(eq(ipBlocks.ip, ip), isNotNull(ipBlocks.releasedAt)))
    .orderBy(desc(ipBlocks.releasedAt))
    .limit(1);

  const releasedAt = lastRelease[0]?.releasedAt ?? null;
  const since = releasedAt && releasedAt > windowStart ? releasedAt : windowStart;

  const countedRows = await db
    .select({ value: count() })
    .from(loginAttempts)
    .where(
      and(
        eq(loginAttempts.ip, ip),
        eq(loginAttempts.counted, true),
        eq(loginAttempts.success, false),
        gte(loginAttempts.createdAt, since),
      ),
    );

  const total = Number(countedRows[0]?.value ?? 0);

  if (total >= config.maxFailedAttempts) {
    await blockIp(ip, total, `Exceeded ${config.maxFailedAttempts} failed attempts`);
    return { blocked: true, failedCount: total };
  }

  return { blocked: false, failedCount: total };
}

export async function blockIp(
  ip: string,
  failedCount: number,
  reason: string,
): Promise<Date> {
  const expiresAt = new Date(Date.now() + config.blockDurationMs);

  await db
    .insert(ipBlocks)
    .values({ ip, failedCount, reason, expiresAt })
    .onConflictDoUpdate({
      target: ipBlocks.ip,
      set: {
        failedCount,
        reason,
        blockedAt: new Date(),
        expiresAt,
        releasedAt: null,
        releasedBy: null,
      },
    });

  return expiresAt;
}

export async function releaseBlock(ip: string, releasedBy: string): Promise<boolean> {
  const released = await db
    .update(ipBlocks)
    .set({ releasedAt: new Date(), releasedBy })
    .where(and(eq(ipBlocks.ip, ip), gt(ipBlocks.expiresAt, new Date())))
    .returning({ id: ipBlocks.id });

  return released.length > 0;
}

export async function recordAttempt(attempt: {
  ip: string;
  username: string | null;
  success: boolean;
  counted: boolean;
  reason?: string;
  userAgent: string | null;
}): Promise<void> {
  await db.insert(loginAttempts).values({
    ip: attempt.ip,
    username: attempt.username,
    success: attempt.success,
    counted: attempt.counted,
    reason: attempt.reason ?? null,
    userAgent: attempt.userAgent,
  });
}

export async function listActiveBlocks(): Promise<ActiveBlock[]> {
  return db
    .select({
      ip: ipBlocks.ip,
      failedCount: ipBlocks.failedCount,
      expiresAt: ipBlocks.expiresAt,
    })
    .from(ipBlocks)
    .where(and(isNull(ipBlocks.releasedAt), gt(ipBlocks.expiresAt, new Date())));
}
