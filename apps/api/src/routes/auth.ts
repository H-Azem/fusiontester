import type { FastifyInstance, FastifyReply } from "fastify";
import { eq } from "drizzle-orm";
import { z } from "zod";

import { recordAudit } from "../auth/audit.js";
import { consumeCaptcha, createCaptcha } from "../auth/captcha.js";
import { getActiveBlock, recordAttempt, recordCountedFailure } from "../auth/lockout.js";
import {
  hashPassword,
  validatePasswordStrength,
  verifyPassword,
} from "../auth/password.js";
import { currentSession, requireSession } from "../auth/require-session.js";
import {
  createSession,
  resolveSession,
  revokeAllSessions,
  revokeSession,
} from "../auth/session.js";
import { config } from "../config.js";
import { db } from "../db/index.js";
import { users } from "../db/schema.js";

const AUTH_FAILED_MESSAGE = "Invalid username, password, or captcha.";
const BLOCKED_MESSAGE = "Too many failed attempts. Try again later.";

const loginSchema = z.object({
  username: z.string().min(1).max(100),
  password: z.string().min(1).max(200),
  captchaId: z.string().min(1),
  captchaText: z.string().min(1).max(20),
});

const changePasswordSchema = z.object({
  currentPassword: z.string().min(1).max(200),
  newPassword: z.string().min(1).max(200),
});

function setSessionCookie(reply: FastifyReply, token: string, expiresAt: Date): void {
  reply.setCookie(config.sessionCookieName, token, {
    path: "/",
    httpOnly: true,
    sameSite: "lax",
    secure: config.isProduction,
    expires: expiresAt,
  });
}

function clearSessionCookie(reply: FastifyReply): void {
  reply.clearCookie(config.sessionCookieName, { path: "/" });
}

/**
 * A real argon2 hash verified against for unknown usernames, so a missing user
 * costs roughly the same time as a wrong password.
 */
let dummyHash: Promise<string> | null = null;
function getDummyHash(): Promise<string> {
  dummyHash ??= hashPassword("timing-equalisation-placeholder");
  return dummyHash;
}

export async function authRoutes(app: FastifyInstance): Promise<void> {
  app.get("/auth/captcha", async (request, reply) => {
    const { id, svg } = await createCaptcha(request.ip);
    return reply.send({ id, svg });
  });

  app.post("/auth/login", async (request, reply) => {
    const parsed = loginSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const { username, password, captchaId, captchaText } = parsed.data;
    const ip = request.ip;
    const userAgent = request.headers["user-agent"] ?? null;

    const block = await getActiveBlock(ip);
    if (block) {
      await recordAudit({ action: "login.blocked", ip, userAgent });
      return reply.code(429).send({
        error: "blocked",
        message: BLOCKED_MESSAGE,
        blockedUntil: block.expiresAt.toISOString(),
      });
    }

    if (!(await consumeCaptcha(captchaId, captchaText))) {
      // Logged, but deliberately not counted toward the IP block: mistyping a
      // captcha must not lock a legitimate user out for hours.
      await recordAttempt({
        ip,
        username,
        success: false,
        counted: false,
        reason: "captcha",
        userAgent,
      });
      await recordAudit({ action: "login.captcha_failed", ip, userAgent });
      return reply.code(401).send({ error: "invalid", message: AUTH_FAILED_MESSAGE });
    }

    const normalized = username.trim().toLowerCase();
    const rows = await db
      .select()
      .from(users)
      .where(eq(users.username, normalized))
      .limit(1);
    const user = rows[0];

    const passwordOk = user
      ? await verifyPassword(user.passwordHash, password)
      : await verifyPassword(await getDummyHash(), password).then(() => false);

    if (!user || !passwordOk) {
      const { blocked, failedCount } = await recordCountedFailure(
        ip,
        normalized,
        userAgent,
        user ? "bad_password" : "unknown_user",
      );

      await recordAudit({
        action: blocked ? "ip.blocked" : "login.failed",
        ip,
        userAgent,
        metadata: { username: normalized, failedCount },
      });

      if (blocked) {
        return reply.code(429).send({
          error: "blocked",
          message: BLOCKED_MESSAGE,
          blockedUntil: new Date(Date.now() + config.blockDurationMs).toISOString(),
        });
      }

      return reply.code(401).send({ error: "invalid", message: AUTH_FAILED_MESSAGE });
    }

    await recordAttempt({
      ip,
      username: normalized,
      success: true,
      counted: false,
      userAgent,
    });

    const { token, expiresAt } = await createSession(user.id, { ip, userAgent });
    setSessionCookie(reply, token, expiresAt);
    await recordAudit({ action: "login.success", actorUserId: user.id, ip, userAgent });

    return reply.send({
      user: { id: user.id, username: user.username, role: user.role },
    });
  });

  app.get("/auth/me", { preHandler: requireSession }, async (request, reply) => {
    return reply.send({ user: currentSession(request).user });
  });

  app.post("/auth/logout", async (request, reply) => {
    // Deliberately tolerant: signing out should succeed even if the session
    // has already expired, so the cookie still gets cleared.
    const token = request.cookies[config.sessionCookieName];
    const session = token ? await resolveSession(token) : null;

    if (session) {
      await revokeSession(session.sessionId);
      await recordAudit({
        action: "logout",
        actorUserId: session.user.id,
        ip: request.ip,
        userAgent: request.headers["user-agent"] ?? null,
      });
    }

    clearSessionCookie(reply);
    return reply.send({ ok: true });
  });

  app.post("/auth/change-password", { preHandler: requireSession }, async (request, reply) => {
    const session = currentSession(request);

    const parsed = changePasswordSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send({ error: "invalid_request" });

    const rows = await db
      .select()
      .from(users)
      .where(eq(users.id, session.user.id))
      .limit(1);
    const user = rows[0];
    if (!user) return reply.code(401).send({ error: "unauthorized" });

    const ip = request.ip;
    const userAgent = request.headers["user-agent"] ?? null;

    if (!(await verifyPassword(user.passwordHash, parsed.data.currentPassword))) {
      // Already authenticated, so this is not counted toward the IP block —
      // otherwise a user could lock their own network out from the inside.
      await recordAttempt({
        ip,
        username: user.username,
        success: false,
        counted: false,
        reason: "bad_current_password",
        userAgent,
      });
      return reply
        .code(401)
        .send({ error: "invalid", message: "Current password is incorrect." });
    }

    const strengthError = validatePasswordStrength(parsed.data.newPassword, user.username);
    if (strengthError) {
      return reply.code(400).send({ error: "weak_password", message: strengthError });
    }

    await db
      .update(users)
      .set({
        passwordHash: await hashPassword(parsed.data.newPassword),
        passwordChangedAt: new Date(),
        updatedAt: new Date(),
      })
      .where(eq(users.id, user.id));

    const revokedSessions = await revokeAllSessions(user.id, session.sessionId);

    await recordAudit({
      action: "password.changed",
      actorUserId: user.id,
      ip,
      userAgent,
      metadata: { revokedSessions },
    });

    return reply.send({ ok: true, revokedSessions });
  });
}
