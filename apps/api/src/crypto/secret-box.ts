import { createCipheriv, createDecipheriv, randomBytes, scryptSync } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";

import { config } from "../config.js";

const ALGORITHM = "aes-256-gcm";
const FORMAT_VERSION = "v1";
const IV_BYTES = 12;
const KEY_BYTES = 32;
const KDF_SALT = "fusion-tester/secret-box/v1";

let cachedKey: Buffer | null = null;

/**
 * Resolves the encryption key. Prefers APP_SECRET; otherwise generates a key
 * file under .data so local development works without extra setup. Losing this
 * value makes previously stored credentials undecryptable.
 */
function resolveKey(): Buffer {
  if (cachedKey) return cachedKey;

  let secret = config.appSecret;

  if (!secret) {
    if (existsSync(config.appSecretFile)) {
      secret = readFileSync(config.appSecretFile, "utf8").trim();
    } else {
      mkdirSync(config.dataDir, { recursive: true });
      secret = randomBytes(KEY_BYTES).toString("hex");
      writeFileSync(config.appSecretFile, secret, { mode: 0o600 });
    }
  }

  cachedKey = scryptSync(secret, KDF_SALT, KEY_BYTES);
  return cachedKey;
}

export function isUsingGeneratedAppSecret(): boolean {
  return config.appSecret === null;
}

export function encryptSecret(plaintext: string): string {
  const iv = randomBytes(IV_BYTES);
  const cipher = createCipheriv(ALGORITHM, resolveKey(), iv);
  const ciphertext = Buffer.concat([cipher.update(plaintext, "utf8"), cipher.final()]);
  const tag = cipher.getAuthTag();

  return [
    FORMAT_VERSION,
    iv.toString("base64url"),
    tag.toString("base64url"),
    ciphertext.toString("base64url"),
  ].join(".");
}

export function decryptSecret(payload: string): string {
  const parts = payload.split(".");
  if (parts.length !== 4 || parts[0] !== FORMAT_VERSION) {
    throw new Error("Stored secret is not in a recognised format");
  }

  const iv = parts[1];
  const tag = parts[2];
  const ciphertext = parts[3];
  if (!iv || !tag || !ciphertext) {
    throw new Error("Stored secret is malformed");
  }

  const decipher = createDecipheriv(
    ALGORITHM,
    resolveKey(),
    Buffer.from(iv, "base64url"),
  );
  decipher.setAuthTag(Buffer.from(tag, "base64url"));

  const plaintext = Buffer.concat([
    decipher.update(Buffer.from(ciphertext, "base64url")),
    decipher.final(),
  ]);

  return plaintext.toString("utf8");
}

/** Never-disclosing display hint for a stored secret. */
export function secretHint(secret: string): string {
  if (secret.length <= 4) return "••••";
  return `••••${secret.slice(-4)}`;
}
