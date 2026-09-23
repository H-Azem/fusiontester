import { PGlite } from "@electric-sql/pglite";
import { drizzle } from "drizzle-orm/pglite";
import { migrate } from "drizzle-orm/pglite/migrator";
import { mkdirSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { config } from "../config.js";
import * as schema from "./schema.js";

// PGlite creates its data directory non-recursively, so a missing parent
// (apps/api/.data) fails with ENOENT. Create it up front instead.
if (!config.pgliteDir.startsWith("memory://")) {
  mkdirSync(config.pgliteDir, { recursive: true });
}

export const client = new PGlite(config.pgliteDir);

export const db = drizzle(client, { schema });

export type Db = typeof db;

export async function runMigrations(): Promise<void> {
  const migrationsFolder = fileURLToPath(new URL("../../drizzle", import.meta.url));
  await migrate(db, { migrationsFolder });
}

export { schema };
