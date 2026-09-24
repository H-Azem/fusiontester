import { join } from "node:path";
import { fileURLToPath } from "node:url";

function num(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined || raw === "") return fallback;
  const parsed = Number(raw);
  if (!Number.isFinite(parsed)) {
    throw new Error(`Environment variable ${name} must be a number, got "${raw}"`);
  }
  return parsed;
}

function bool(name: string, fallback: boolean): boolean {
  const raw = process.env[name];
  if (raw === undefined || raw === "") return fallback;
  return raw === "true" || raw === "1";
}

const dataRoot = fileURLToPath(new URL("../.data", import.meta.url));

export const config = {
  env: process.env.NODE_ENV ?? "development",
  isProduction: process.env.NODE_ENV === "production",
  host: process.env.HOST ?? "127.0.0.1",
  port: num("PORT", 4000),

  // Only enable when running behind a proxy you control. Trusting
  // X-Forwarded-For blindly lets anyone spoof their IP and bypass IP blocks.
  trustProxy: bool("TRUST_PROXY", false),

  webOrigin: process.env.WEB_ORIGIN ?? "http://localhost:1999",

  dataDir: dataRoot,
  pgliteDir: process.env.PGLITE_DIR ?? join(dataRoot, "pglite"),

  // Key for encrypting stored third-party credentials. Falls back to a
  // generated file under .data so development works without extra setup.
  appSecret: process.env.APP_SECRET ?? null,
  appSecretFile: process.env.APP_SECRET_FILE ?? join(dataRoot, "app-secret"),

  sessionCookieName: "ft_session",
  sessionTtlMs: num("SESSION_TTL_HOURS", 12) * 60 * 60 * 1000,

  maxFailedAttempts: num("AUTH_MAX_FAILED_ATTEMPTS", 3),
  failedAttemptWindowMs: num("AUTH_FAILED_WINDOW_MINUTES", 60) * 60 * 1000,
  blockDurationMs: num("AUTH_BLOCK_HOURS", 6) * 60 * 60 * 1000,

  captchaTtlMs: num("CAPTCHA_TTL_MINUTES", 10) * 60 * 1000,

  adminUsername: process.env.ADMIN_USERNAME ?? "admin",
  adminPassword: process.env.ADMIN_PASSWORD ?? "admin",
} as const;
