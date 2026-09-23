import { hash, verify, type Algorithm } from "@node-rs/argon2";

// The package declares Algorithm as an ambient const enum, which isolatedModules
// forbids reading at runtime. Argon2id === 2 in that enum.
const ARGON2ID = 2 as Algorithm;

const options = {
  algorithm: ARGON2ID,
  memoryCost: 19456,
  timeCost: 2,
  parallelism: 1,
};

export function hashPassword(password: string): Promise<string> {
  return hash(password, options);
}

export async function verifyPassword(
  passwordHash: string,
  password: string,
): Promise<boolean> {
  try {
    return await verify(passwordHash, password, options);
  } catch {
    return false;
  }
}

const MIN_LENGTH = 8;

export function validatePasswordStrength(
  password: string,
  username: string,
): string | null {
  if (password.length < MIN_LENGTH) {
    return `Password must be at least ${MIN_LENGTH} characters.`;
  }
  if (password.length > 200) {
    return "Password must be at most 200 characters.";
  }
  if (password.toLowerCase() === username.toLowerCase()) {
    return "Password must not be the same as the username.";
  }
  return null;
}
