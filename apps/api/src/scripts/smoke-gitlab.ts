/**
 * In-process smoke test for the GitLab connection settings.
 *
 *   $env:PGLITE_DIR="<scratch>/pglite-gitlab"; pnpm --filter @fusion-tester/api exec tsx src/scripts/smoke-gitlab.ts
 *
 * The last two checks reach the network (gitlab.com and a reserved
 * non-resolving domain) to exercise the real HTTP and clone code paths.
 */
import { createHash, randomUUID } from "node:crypto";
import { eq } from "drizzle-orm";

import { buildApp } from "../app.js";
import { hashPassword } from "../auth/password.js";
import { config } from "../config.js";
import { decryptSecret, encryptSecret } from "../crypto/secret-box.js";
import { client, db, runMigrations } from "../db/index.js";
import {
  captchaChallenges,
  gitlabConnections,
  ipBlocks,
  loginAttempts,
  sessions,
  users,
} from "../db/schema.js";
import { normalizeBaseUrl } from "../gitlab/client.js";
import { ensureDefaultAdmin } from "../seed.js";

let failures = 0;

function check(label: string, condition: boolean, detail?: unknown): void {
  if (condition) {
    process.stdout.write(`  PASS  ${label}\n`);
    return;
  }
  failures += 1;
  process.stdout.write(
    `  FAIL  ${label}${detail === undefined ? "" : ` -> ${JSON.stringify(detail).slice(0, 300)}`}\n`,
  );
}

const TOKEN = "glpat-FAKETOKEN1234567890";

async function main(): Promise<void> {
  await runMigrations();
  await ensureDefaultAdmin();

  await db.delete(loginAttempts);
  await db.delete(ipBlocks);
  await db.delete(captchaChallenges);
  await db.delete(sessions);
  await db.delete(gitlabConnections);
  await db.update(users).set({ passwordHash: await hashPassword("admin") });

  const app = await buildApp({ logger: false });
  await app.ready();

  process.stdout.write("\n1. Secret box\n");
  const sealed = encryptSecret(TOKEN);
  check("ciphertext differs from plaintext", !sealed.includes(TOKEN), sealed.slice(0, 24));
  check("round trip returns the original", decryptSecret(sealed) === TOKEN);
  check("two encryptions of the same value differ", encryptSecret(TOKEN) !== sealed);
  let tamperRejected = false;
  try {
    const parts = sealed.split(".");
    parts[3] = `${parts[3]}AA`;
    decryptSecret(parts.join("."));
  } catch {
    tamperRejected = true;
  }
  check("tampered ciphertext is rejected by GCM", tamperRejected);

  process.stdout.write("\n2. Settings endpoints require a session\n");
  const unauthGet = await app.inject({ method: "GET", url: "/settings/gitlab" });
  check("GET /settings/gitlab -> 401", unauthGet.statusCode === 401, unauthGet.statusCode);
  const unauthPut = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    payload: { baseUrl: "https://gitlab.example.com", token: TOKEN },
  });
  check("PUT /settings/gitlab -> 401", unauthPut.statusCode === 401, unauthPut.statusCode);
  const unauthTest = await app.inject({ method: "POST", url: "/settings/gitlab/test", payload: {} });
  check("POST /settings/gitlab/test -> 401", unauthTest.statusCode === 401, unauthTest.statusCode);

  // Sign in to get a session.
  const captchaId = randomUUID();
  const answer = "SMOKECODE";
  await db.insert(captchaChallenges).values({
    id: captchaId,
    answerHash: createHash("sha256").update(answer.toLowerCase()).digest("hex"),
    expiresAt: new Date(Date.now() + 600_000),
  });
  const loginResponse = await app.inject({
    method: "POST",
    url: "/auth/login",
    payload: { username: "admin", password: "admin", captchaId, captchaText: answer },
  });
  const cookie = loginResponse.cookies.find((c) => c.name === config.sessionCookieName);
  const auth = { cookie: `${config.sessionCookieName}=${cookie?.value ?? ""}` };
  check("signed in for the rest of the test", loginResponse.statusCode === 200, loginResponse.statusCode);

  process.stdout.write("\n3. Base URL normalisation\n");
  check("adds https when missing", normalizeBaseUrl("gitlab.example.com") === "https://gitlab.example.com");
  check("strips trailing slashes", normalizeBaseUrl("https://gitlab.example.com///") === "https://gitlab.example.com");
  check("keeps an explicit http scheme", normalizeBaseUrl("http://gitlab.local") === "http://gitlab.local");

  process.stdout.write("\n4. Save and read back\n");
  const save = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    headers: auth,
    payload: { baseUrl: "https://gitlab.example.com/", token: TOKEN, caCertificate: "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----" },
  });
  check("PUT saves -> 200", save.statusCode === 200, save.statusCode);
  check("save response omits the token", !save.body.includes(TOKEN));
  check("save response shows a hint", save.body.includes("7890"), save.body);
  check("base URL normalised on save", (save.json() as { baseUrl?: string }).baseUrl === "https://gitlab.example.com");

  const get = await app.inject({ method: "GET", url: "/settings/gitlab", headers: auth });
  check("GET returns 200", get.statusCode === 200, get.statusCode);
  check("GET never returns the token", !get.body.includes(TOKEN), get.body);
  check("GET reports configured", (get.json() as { configured?: boolean }).configured === true);
  check("GET reports the CA certificate presence", (get.json() as { hasCaCertificate?: boolean }).hasCaCertificate === true);

  const stored = await db.select().from(gitlabConnections).limit(1);
  check("token is stored as ciphertext, not plaintext", stored[0] !== undefined && !stored[0].tokenCiphertext.includes(TOKEN));
  check("stored token decrypts back", stored[0] !== undefined && decryptSecret(stored[0].tokenCiphertext) === TOKEN);

  process.stdout.write("\n5. Saving without a token keeps the stored one\n");
  const resave = await app.inject({
    method: "PUT",
    url: "/settings/gitlab",
    headers: auth,
    payload: { baseUrl: "https://gitlab.example.com" },
  });
  check("PUT without token -> 200", resave.statusCode === 200, resave.statusCode);
  const afterResave = await db.select().from(gitlabConnections).limit(1);
  check(
    "stored token survives a token-less save",
    afterResave[0] !== undefined && decryptSecret(afterResave[0].tokenCiphertext) === TOKEN,
  );
  check(
    "CA certificate survives when omitted",
    afterResave[0]?.caCertificate !== null && afterResave[0]?.caCertificate !== undefined,
  );

  process.stdout.write("\n6. Unreachable host fails cleanly\n");
  const unreachable = await app.inject({
    method: "POST",
    url: "/settings/gitlab/test",
    headers: auth,
    payload: { baseUrl: "https://gitlab.invalid", token: TOKEN, testRepo: "group/project", caCertificate: "" },
  });
  check("unreachable host -> 200 with a result body", unreachable.statusCode === 200, unreachable.statusCode);
  const unreachableBody = unreachable.json() as { ok?: boolean; error?: string | null };
  check("result is not ok", unreachableBody.ok === false);
  check("an error message is present", typeof unreachableBody.error === "string" && unreachableBody.error.length > 0);
  check("unreachable response omits the token", !unreachable.body.includes(TOKEN));

  process.stdout.write("\n7. Real GitLab host with an invalid token\n");
  const badToken = await app.inject({
    method: "POST",
    url: "/settings/gitlab/test",
    headers: auth,
    payload: {
      baseUrl: "https://gitlab.com",
      token: TOKEN,
      testRepo: "gitlab-org/cli",
      caCertificate: "",
    },
  });
  const badBody = badToken.json() as {
    ok?: boolean;
    error?: string | null;
    clone?: { attempted?: boolean; ok?: boolean; message?: string | null };
    warnings?: string[];
  };
  check("invalid token -> result returned", badToken.statusCode === 200, badToken.statusCode);
  check("invalid token is reported as not ok", badBody.ok === false);
  check("401 is explained", (badBody.error ?? "").includes("401"), badBody.error);
  check("clone check actually ran", badBody.clone?.attempted === true);
  check("clone succeeded against the public repo", badBody.clone?.ok === true, badBody.clone?.message);
  check(
    "public-repo caveat is surfaced",
    (badBody.warnings ?? []).some((w) => w.includes("anonymously")),
    badBody.warnings,
  );
  check("response body never contains the token", !badToken.body.includes(TOKEN));

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
