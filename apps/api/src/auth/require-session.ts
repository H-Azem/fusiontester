import type { FastifyReply, FastifyRequest } from "fastify";

import { config } from "../config.js";
import { resolveSession, touchSession, type ResolvedSession, type SessionUser } from "./session.js";

declare module "fastify" {
  interface FastifyRequest {
    session?: ResolvedSession;
  }
}

/**
 * Fastify preHandler that rejects unauthenticated requests. On success the
 * resolved session is attached to the request.
 */
export async function requireSession(
  request: FastifyRequest,
  reply: FastifyReply,
): Promise<void> {
  const token = request.cookies[config.sessionCookieName];
  if (!token) {
    await reply.code(401).send({ error: "unauthorized" });
    return;
  }

  const session = await resolveSession(token);
  if (!session) {
    await reply.code(401).send({ error: "unauthorized" });
    return;
  }

  request.session = session;
  await touchSession(session.sessionId);
}

/** Reads the session attached by requireSession, failing loudly if it is absent. */
export function currentSession(request: FastifyRequest): ResolvedSession {
  const session = request.session;
  if (!session) {
    throw new Error("currentSession() called on a route without the requireSession preHandler");
  }
  return session;
}

export function sessionUser(request: FastifyRequest): SessionUser {
  return currentSession(request).user;
}
