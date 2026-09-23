import type { FastifyReply, FastifyRequest } from "fastify";

import { config } from "../config.js";

const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

/**
 * Defence in depth behind the SameSite=Lax cookie: rejects state-changing
 * requests whose Origin does not match the dashboard.
 *
 * A missing Origin is allowed because server-to-server calls (the Next.js
 * proxy, CLI scripts) do not send one. Browsers always send it on cross-site
 * form posts and fetches, which is the case we care about blocking.
 */
export async function enforceTrustedOrigin(
  request: FastifyRequest,
  reply: FastifyReply,
): Promise<void> {
  if (SAFE_METHODS.has(request.method)) return;

  const origin = request.headers.origin;
  if (origin === undefined) return;

  if (origin !== config.webOrigin) {
    request.log.warn({ origin, method: request.method }, "rejected untrusted origin");
    await reply.code(403).send({ error: "Origin not allowed" });
  }
}
