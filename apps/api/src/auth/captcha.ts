import { createHash, randomUUID } from "node:crypto";
import svgCaptcha from "svg-captcha";
import { and, eq, gt, isNull, lt } from "drizzle-orm";

import { config } from "../config.js";
import { db } from "../db/index.js";
import { captchaChallenges } from "../db/schema.js";

const IGNORE_CHARS = "0oO1ilI";

function hashAnswer(answer: string): string {
  return createHash("sha256").update(answer.trim().toLowerCase()).digest("hex");
}

export async function createCaptcha(
  ip: string | null,
): Promise<{ id: string; svg: string }> {
  const captcha = svgCaptcha.create({
    size: 5,
    noise: 3,
    color: true,
    background: "#161b22",
    ignoreChars: IGNORE_CHARS,
    width: 180,
    height: 60,
    fontSize: 46,
  });

  const id = randomUUID();

  await db.insert(captchaChallenges).values({
    id,
    answerHash: hashAnswer(captcha.text),
    ip,
    expiresAt: new Date(Date.now() + config.captchaTtlMs),
  });

  await db
    .delete(captchaChallenges)
    .where(lt(captchaChallenges.expiresAt, new Date()));

  return { id, svg: captcha.data };
}

/**
 * Verifies and consumes a captcha challenge. Single use: the challenge is
 * consumed before the answer is compared, so a wrong guess cannot be replayed
 * against the same challenge.
 */
export async function consumeCaptcha(id: string, answer: string): Promise<boolean> {
  const rows = await db
    .select({
      id: captchaChallenges.id,
      answerHash: captchaChallenges.answerHash,
    })
    .from(captchaChallenges)
    .where(
      and(
        eq(captchaChallenges.id, id),
        isNull(captchaChallenges.consumedAt),
        gt(captchaChallenges.expiresAt, new Date()),
      ),
    )
    .limit(1);

  const challenge = rows[0];
  if (!challenge) return false;

  const consumed = await db
    .update(captchaChallenges)
    .set({ consumedAt: new Date() })
    .where(
      and(
        eq(captchaChallenges.id, id),
        isNull(captchaChallenges.consumedAt),
      ),
    )
    .returning({ id: captchaChallenges.id });

  if (consumed.length === 0) return false;

  return challenge.answerHash === hashAnswer(answer);
}
