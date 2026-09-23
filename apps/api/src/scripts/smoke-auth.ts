/**
 * In-process smoke test for the auth system.
 *
 * Drives the real Fastify app via app.inject() against an isolated PGlite
 * directory, so it exercises the actual routes, lockout and session code
 * without racing the dev server for the database.
 *
 *   $env:PGLITE_DIR="<scratch>/pglite-test"; pnpm --filter @fusion-tester/api exec tsx src/scripts/smoke-auth.ts
 */
import { createHash, randomUUID } from "node:crypto";
import { eq } from "drizzle-orm";

import { buildApp } from "../app.js";
import { hashPassword } from "../auth/password.js";
import { releaseBlock } from "../auth/lockout.js";
import { config } from "../config.js";
import { client, db, runMigrations } from "../db/index.js";
import { captchaChallenges, ipBlocks, loginAttempts, sessions, users } from "../db/schema.js";
import { ensureDefaultAdmin } from "../seed.js";

let failures = 0;

function check(label: string, condition: boolean, detail?: unknown): void {
  if (condition) {
    process.stdout.write(`  PASS  ${label}\n`);
    return;
  }
  failures += 1;
  process.stdout.write(`  FAIL  ${label}${detail === undefined ? "" : ` -> ${JSON.stringify(detail)}`}\n`);
}

function answerHash(answer: string): string {
  return createHash("sha256").update(answer.trim().toLowerCase()).digest("hex");
}

/** Creates a captcha challenge whose answer we know, bypassing the image. */
async function issueCaptcha(answer: string): Promise<string> {
  const id = randomUUID();
  await db.insert(captchaChallenges).values({
    id,
    answerHash: answerHash(answer),
    expiresAt: new Date(Date.now() + 10 * 60 * 1000),
  });
  return id;
}

async function main(): Promise<void> {
  await runMigrations();
  await ensureDefaultAdmin();

  // Deterministic starting state.
  await db.delete(loginAttempts);
  await db.delete(ipBlocks);
  await db.delete(captchaChallenges);
  await db.delete(sessions);
  await db.update(users).set({ passwordHash: await hashPassword("admin") });

  const app = await buildApp({ logger: false });
  await app.ready();

  const admin = { username: "admin", password: "admin" };

  async function login(
    username: string,
    password: string,
    captchaAnswer: string,
    sendCaptchaText?: string,
  ) {
    const captchaId = await issueCaptcha(captchaAnswer);
    return app.inject({
      method: "POST",
      url: "/auth/login",
      payload: { username, password, captchaId, captchaText: sendCaptchaText ?? captchaAnswer },
    });
  }

  process.stdout.write("\n1. Captcha\n");
  const captchaResponse = await app.inject({ method: "GET", url: "/auth/captcha" });
  const captchaBody = captchaResponse.json() as { id?: string; svg?: string };
  check("GET /auth/captcha returns 200", captchaResponse.statusCode === 200);
  check("captcha response contains an id", typeof captchaBody.id === "string");
  check("captcha response contains SVG", (captchaBody.svg ?? "").includes("<svg"));

  process.stdout.write("\n2. Wrong captcha does not count toward the block\n");
  const wrongCaptcha = await login("admin", "admin", "AAAAA", "WRONG");
  check("wrong captcha rejected with 401", wrongCaptcha.statusCode === 401, wrongCaptcha.statusCode);
  const countedAfterCaptcha = await db
    .select({ counted: loginAttempts.counted })
    .from(loginAttempts)
    .where(eq(loginAttempts.counted, true));
  check("no counted failures recorded", countedAfterCaptcha.length === 0, countedAfterCaptcha);

  process.stdout.write("\n3. Three bad passwords block the IP for 6 hours\n");
  const first = await login(admin.username, "wrong-password", "BBBBB");
  check("1st bad password -> 401", first.statusCode === 401, first.statusCode);
  const second = await login(admin.username, "wrong-password", "CCCCC");
  check("2nd bad password -> 401", second.statusCode === 401, second.statusCode);
  const third = await login(admin.username, "wrong-password", "DDDDD");
  check("3rd bad password -> 429 blocked", third.statusCode === 429, third.statusCode);

  const blockRows = await db.select().from(ipBlocks);
  check("one IP block recorded", blockRows.length === 1, blockRows.length);
  const block = blockRows[0];
  if (block) {
    const hours = (block.expiresAt.getTime() - block.blockedAt.getTime()) / 3_600_000;
    check("block lasts ~6 hours", Math.abs(hours - 6) < 0.01, hours);
    check("failed count recorded as 3", block.failedCount === 3, block.failedCount);
  }

  process.stdout.write("\n4. Blocked IP is refused even with correct credentials\n");
  const whileBlocked = await login(admin.username, admin.password, "EEEEE");
  check("correct password while blocked -> 429", whileBlocked.statusCode === 429, whileBlocked.statusCode);

  process.stdout.write("\n5. CLI unblock restores access\n");
  const released = await releaseBlock("127.0.0.1", "smoke-test");
  check("releaseBlock reports a released row", released === true);
  const afterRelease = await login(admin.username, admin.password, "FFFFF");
  check("login succeeds after unblock -> 200", afterRelease.statusCode === 200, afterRelease.statusCode);

  const sessionCookie = afterRelease.cookies.find((c) => c.name === config.sessionCookieName);
  check("session cookie set", sessionCookie !== undefined);
  check("cookie is httpOnly", sessionCookie?.httpOnly === true);
  check("cookie is SameSite=Lax", sessionCookie?.sameSite?.toLowerCase() === "lax", sessionCookie?.sameSite);

  const cookieHeader = `${config.sessionCookieName}=${sessionCookie?.value ?? ""}`;

  process.stdout.write("\n6. Session resolution\n");
  const me = await app.inject({ method: "GET", url: "/auth/me", headers: { cookie: cookieHeader } });
  check("GET /auth/me -> 200", me.statusCode === 200, me.statusCode);
  check("me returns admin user", (me.json() as { user?: { username?: string } }).user?.username === "admin");

  const noCookie = await app.inject({ method: "GET", url: "/auth/me" });
  check("GET /auth/me without cookie -> 401", noCookie.statusCode === 401, noCookie.statusCode);

  process.stdout.write("\n7. Change password\n");
  const badCurrent = await app.inject({
    method: "POST",
    url: "/auth/change-password",
    headers: { cookie: cookieHeader },
    payload: { currentPassword: "not-the-password", newPassword: "brand-new-password" },
  });
  check("wrong current password -> 401", badCurrent.statusCode === 401, badCurrent.statusCode);

  const weak = await app.inject({
    method: "POST",
    url: "/auth/change-password",
    headers: { cookie: cookieHeader },
    payload: { currentPassword: "admin", newPassword: "short" },
  });
  check("too-short new password -> 400", weak.statusCode === 400, weak.statusCode);

  // A second session, to prove the change revokes other sessions.
  const secondLogin = await login(admin.username, admin.password, "GGGGG");
  check("second login succeeds -> 200", secondLogin.statusCode === 200, secondLogin.statusCode);
  const secondCookie = secondLogin.cookies.find((c) => c.name === config.sessionCookieName);
  const secondCookieHeader = `${config.sessionCookieName}=${secondCookie?.value ?? ""}`;

  const changed = await app.inject({
    method: "POST",
    url: "/auth/change-password",
    headers: { cookie: cookieHeader },
    payload: { currentPassword: "admin", newPassword: "brand-new-password" },
  });
  check("password change -> 200", changed.statusCode === 200, changed.statusCode);
  check(
    "other session revoked (1)",
    (changed.json() as { revokedSessions?: number }).revokedSessions === 1,
    changed.json(),
  );

  const revokedMe = await app.inject({
    method: "GET",
    url: "/auth/me",
    headers: { cookie: secondCookieHeader },
  });
  check("revoked session -> 401", revokedMe.statusCode === 401, revokedMe.statusCode);

  const stillValid = await app.inject({
    method: "GET",
    url: "/auth/me",
    headers: { cookie: cookieHeader },
  });
  check("changing session stays valid -> 200", stillValid.statusCode === 200, stillValid.statusCode);

  process.stdout.write("\n8. New password takes effect\n");
  const oldPassword = await login(admin.username, "admin", "HHHHH");
  check("old password rejected -> 401", oldPassword.statusCode === 401, oldPassword.statusCode);

  // That failure was the 1st counted one after the release, so we are not blocked yet.
  const newPassword = await login(admin.username, "brand-new-password", "IIIII");
  check("new password accepted -> 200", newPassword.statusCode === 200, newPassword.statusCode);

  process.stdout.write("\n9. Logout\n");
  const finalCookie = newPassword.cookies.find((c) => c.name === config.sessionCookieName);
  const finalCookieHeader = `${config.sessionCookieName}=${finalCookie?.value ?? ""}`;
  const logout = await app.inject({
    method: "POST",
    url: "/auth/logout",
    headers: { cookie: finalCookieHeader },
  });
  check("logout -> 200", logout.statusCode === 200, logout.statusCode);
  const afterLogout = await app.inject({
    method: "GET",
    url: "/auth/me",
    headers: { cookie: finalCookieHeader },
  });
  check("session invalid after logout -> 401", afterLogout.statusCode === 401, afterLogout.statusCode);

  process.stdout.write("\n10. Origin guard\n");
  const badOrigin = await app.inject({
    method: "POST",
    url: "/auth/login",
    headers: { origin: "http://evil.example" },
    payload: { username: "admin", password: "x", captchaId: "x", captchaText: "x" },
  });
  check("cross-origin POST -> 403", badOrigin.statusCode === 403, badOrigin.statusCode);

  const goodOrigin = await app.inject({
    method: "POST",
    url: "/auth/logout",
    headers: { origin: config.webOrigin },
  });
  check("trusted-origin POST not rejected by guard", goodOrigin.statusCode !== 403, goodOrigin.statusCode);

  await app.close();

  process.stdout.write(
    failures === 0 ? "\nAll checks passed.\n" : `\n${failures} check(s) FAILED.\n`,
  );
  process.exitCode = failures === 0 ? 0 : 1;
}

main()
  .then(async () => {
    await client.close();
  })
  .catch(async (error: unknown) => {
    process.stderr.write(`${String(error)}\n`);
    await client.close();
    process.exit(1);
  });
