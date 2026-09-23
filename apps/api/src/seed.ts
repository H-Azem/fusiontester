import { eq } from "drizzle-orm";

import { hashPassword } from "./auth/password.js";
import { config } from "./config.js";
import { db } from "./db/index.js";
import { users } from "./db/schema.js";

export async function ensureDefaultAdmin(): Promise<void> {
  const username = config.adminUsername.trim().toLowerCase();

  const existing = await db
    .select({ id: users.id })
    .from(users)
    .where(eq(users.username, username))
    .limit(1);

  if (existing.length > 0) return;

  await db.insert(users).values({
    username,
    passwordHash: await hashPassword(config.adminPassword),
    role: "admin",
  });
}
