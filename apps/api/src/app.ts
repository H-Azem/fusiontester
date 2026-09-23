import cookie from "@fastify/cookie";
import cors from "@fastify/cors";
import Fastify, { type FastifyInstance } from "fastify";

import { enforceTrustedOrigin } from "./auth/security.js";
import { config } from "./config.js";
import { authRoutes } from "./routes/auth.js";
import { gitlabRoutes } from "./routes/gitlab.js";
import { pinRoutes } from "./routes/pins.js";
import { projectRoutes } from "./routes/projects.js";
import { runRoutes } from "./routes/runs.js";
import { settingsRoutes } from "./routes/settings.js";

export type BuildAppOptions = {
  logger?: boolean;
};

/**
 * Builds the Fastify instance without listening or running migrations, so
 * scripts and tests can drive it in-process.
 */
export async function buildApp(options: BuildAppOptions = {}): Promise<FastifyInstance> {
  const app = Fastify({
    logger: options.logger ?? true,
    trustProxy: config.trustProxy,
  });

  await app.register(cookie);
  await app.register(cors, {
    origin: config.webOrigin,
    credentials: true,
  });

  app.addHook("onRequest", enforceTrustedOrigin);

  app.get("/health", async () => ({
    status: "ok",
    service: "fusion-tester-api",
    version: "0.0.0",
    timestamp: new Date().toISOString(),
  }));

  await app.register(authRoutes);
  await app.register(settingsRoutes);
  await app.register(gitlabRoutes);
  await app.register(pinRoutes);
  await app.register(projectRoutes);
  await app.register(runRoutes);

  return app;
}
