import type { FastifyInstance } from "fastify";
import { eq } from "drizzle-orm";
import { z } from "zod";

import { recordAudit } from "../auth/audit.js";
import { currentSession, requireSession } from "../auth/require-session.js";
import { decryptSecret, encryptSecret, secretHint } from "../crypto/secret-box.js";
import { db } from "../db/index.js";
import { gitlabConnections } from "../db/schema.js";
import {
  normalizeBaseUrl,
  verifyConnection,
  type GitlabConnection,
} from "../gitlab/client.js";

const saveSchema = z.object({
  baseUrl: z.string().min(1).max(500),
  // Omitted or blank means "keep the stored token".
  token: z.string().max(500).optional(),
  caCertificate: z.string().max(50_000).nullable().optional(),
});

const testSchema = z.object({
  baseUrl: z.string().max(500).optional(),
  token: z.string().max(500).optional(),
  caCertificate: z.string().max(50_000).nullable().optional(),
  testRepo: z.string().max(500).optional(),
});

export async function settingsRoutes(app: FastifyInstance): Promise<void> {
  app.get("/settings/gitlab", { preHandler: requireSession }, async (_request, reply) => {
    const rows = await db.select().from(gitlabConnections).limit(1);
    const row = rows[0];

    if (!row) {
      return reply.send({ configured: false });
    }

    // The token itself is never included in a response.
    return reply.send({
      configured: true,
      baseUrl: row.baseUrl,
      tokenHint: row.tokenHint,
      hasCaCertificate: Boolean(row.caCertificate),
      lastVerifiedAt: row.lastVerifiedAt,
      lastVerifyOk: row.lastVerifyOk,
      lastVerifyError: row.lastVerifyError,
    });
  });

  app.put("/settings/gitlab", { preHandler: requireSession }, async (request, reply) => {
    const parsed = saveSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const baseUrl = normalizeBaseUrl(parsed.data.baseUrl);
    if (!/^https?:\/\/[^/\s]+/i.test(baseUrl)) {
      return reply
        .code(400)
        .send({ error: "invalid_base_url", message: "Enter a valid GitLab URL." });
    }

    const existingRows = await db.select().from(gitlabConnections).limit(1);
    const current = existingRows[0];

    const providedToken = parsed.data.token?.trim();
    let tokenCiphertext: string;
    let tokenHint: string;

    if (providedToken) {
      tokenCiphertext = encryptSecret(providedToken);
      tokenHint = secretHint(providedToken);
    } else if (current) {
      tokenCiphertext = current.tokenCiphertext;
      tokenHint = current.tokenHint;
    } else {
      return reply.code(400).send({
        error: "token_required",
        message: "A token is required when saving the connection for the first time.",
      });
    }

    const caCertificate =
      parsed.data.caCertificate === undefined
        ? (current?.caCertificate ?? null)
        : parsed.data.caCertificate?.trim() || null;

    await db.transaction(async (tx) => {
      await tx.delete(gitlabConnections);
      await tx.insert(gitlabConnections).values({
        baseUrl,
        tokenCiphertext,
        tokenHint,
        caCertificate,
      });
    });

    const session = currentSession(request);
    await recordAudit({
      action: "gitlab.connection_saved",
      actorUserId: session.user.id,
      ip: request.ip,
      metadata: {
        baseUrl,
        tokenChanged: Boolean(providedToken),
        hasCaCertificate: Boolean(caCertificate),
      },
    });

    return reply.send({
      configured: true,
      baseUrl,
      tokenHint,
      hasCaCertificate: Boolean(caCertificate),
    });
  });

  app.post("/settings/gitlab/test", { preHandler: requireSession }, async (request, reply) => {
    const parsed = testSchema.safeParse(request.body ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const rows = await db.select().from(gitlabConnections).limit(1);
    const row = rows[0];

    const stored: GitlabConnection | null = row
      ? {
          baseUrl: row.baseUrl,
          token: decryptSecret(row.tokenCiphertext),
          caCertificate: row.caCertificate,
        }
      : null;

    const providedToken = parsed.data.token?.trim();
    const providedBaseUrl = parsed.data.baseUrl?.trim();

    // Overrides let the form be tested before anything is saved.
    const baseUrl = providedBaseUrl ? normalizeBaseUrl(providedBaseUrl) : stored?.baseUrl;
    const token = providedToken ? providedToken : stored?.token;
    const caCertificate =
      parsed.data.caCertificate === undefined
        ? (stored?.caCertificate ?? null)
        : parsed.data.caCertificate?.trim() || null;

    if (!baseUrl || !token) {
      return reply.code(400).send({
        error: "not_configured",
        message: "Provide a base URL and token, or save the connection first.",
      });
    }

    const connection: GitlabConnection = { baseUrl, token, caCertificate };

    let result;
    try {
      result = await verifyConnection(connection, parsed.data.testRepo);
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      return reply.code(502).send({ error: "unreachable", message });
    }

    // Only record the outcome when it describes the saved configuration.
    const testedStoredConfig = !providedToken && !providedBaseUrl;
    if (row && testedStoredConfig) {
      await db
        .update(gitlabConnections)
        .set({
          lastVerifiedAt: new Date(),
          lastVerifyOk: result.ok,
          lastVerifyError: result.ok
            ? null
            : (result.error ??
              (result.missingScopes.length > 0
                ? `Missing scope(s): ${result.missingScopes.join(", ")}`
                : (result.clone.message ?? "Verification failed"))),
        })
        .where(eq(gitlabConnections.id, row.id));
    }

    const session = currentSession(request);
    await recordAudit({
      action: "gitlab.connection_tested",
      actorUserId: session.user.id,
      ip: request.ip,
      metadata: { baseUrl, ok: result.ok, missingScopes: result.missingScopes },
    });

    return reply.send(result);
  });
}
