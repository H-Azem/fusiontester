import { buildApp } from "./app.js";
import { config } from "./config.js";
import { runMigrations } from "./db/index.js";
import { ensureDefaultAdmin } from "./seed.js";

const app = await buildApp({ logger: true });

await runMigrations();
await ensureDefaultAdmin();

if (config.adminPassword === "admin" && config.isProduction) {
  app.log.warn(
    "Default admin password is still active in production. Change it from the dashboard.",
  );
}

try {
  await app.listen({ port: config.port, host: config.host });
} catch (error) {
  app.log.error(error);
  process.exit(1);
}
