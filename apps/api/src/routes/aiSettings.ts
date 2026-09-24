import type { FastifyInstance } from "fastify";
import { eq } from "drizzle-orm";
import { z } from "zod";

import { recordAudit } from "../auth/audit.js";
import { currentSession, requireSession } from "../auth/require-session.js";
import {
  DEFAULT_JEV_BASE_URL,
  DEFAULT_OPENAI_BASE_URL,
  normalizeBaseUrl,
  verifyAiConfig,
  type AiConfig,
} from "../ai/client.js";
import { decryptSecret, encryptSecret, secretHint } from "../crypto/secret-box.js";
import { db } from "../db/index.js";
import { aiConnections } from "../db/schema.js";

const saveSchema = z.object({
  openaiBaseUrl: z.string().min(1).max(500),
  openaiModel: z.string().min(1).max(200),
  // Omitted or blank means "keep the stored token".
  openaiToken: z.string().max(500).optional(),
  jevBaseUrl: z.string().min(1).max(500),
  jevToken: z.string().max(500).optional(),
  maxSteps: z.number().int().min(3).max(80).optional(),
});

const testSchema = z.object({
  openaiBaseUrl: z.string().max(500).optional(),
  openaiModel: z.string().max(200).optional(),
  openaiToken: z.string().max(500).optional(),
  jevBaseUrl: z.string().max(500).optional(),
  jevToken: z.string().max(500).optional(),
});

function looksLikeUrl(value: string): boolean {
  return /^https?:\/\/[^\s]+$/i.test(value);
}

export async function aiSettingsRoutes(app: FastifyInstance): Promise<void> {
  app.get("/settings/ai", { preHandler: requireSession }, async (_request, reply) => {
    const rows = await db.select().from(aiConnections).limit(1);
    const row = rows[0];

    if (!row) {
      return reply.send({
        configured: false,
        openaiBaseUrl: DEFAULT_OPENAI_BASE_URL,
        jevBaseUrl: DEFAULT_JEV_BASE_URL,
      });
    }

    // Tokens are never included in a response, only hints.
    return reply.send({
      configured: true,
      openaiBaseUrl: row.openaiBaseUrl,
      openaiModel: row.openaiModel,
      openaiTokenHint: row.openaiTokenHint,
      jevBaseUrl: row.jevBaseUrl,
      jevTokenHint: row.jevTokenHint,
      maxSteps: row.maxSteps,
      lastVerifiedAt: row.lastVerifiedAt,
      lastVerifyOk: row.lastVerifyOk,
      lastVerifyError: row.lastVerifyError,
    });
  });

  app.put("/settings/ai", { preHandler: requireSession }, async (request, reply) => {
    const parsed = saveSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const openaiBaseUrl = normalizeBaseUrl(parsed.data.openaiBaseUrl);
    const jevBaseUrl = normalizeBaseUrl(parsed.data.jevBaseUrl || DEFAULT_JEV_BASE_URL);

    if (!looksLikeUrl(openaiBaseUrl)) {
      return reply
        .code(400)
        .send({ error: "invalid_base_url", message: "Enter a valid OpenAI-compatible base URL." });
    }
    if (!looksLikeUrl(jevBaseUrl)) {
      return reply
        .code(400)
        .send({ error: "invalid_base_url", message: "Enter a valid jev base URL." });
    }

    const existing = await db.select().from(aiConnections).limit(1);
    const current = existing[0];

    const openaiToken = parsed.data.openaiToken?.trim();
    const jevToken = parsed.data.jevToken?.trim();

    if (!openaiToken && !current) {
      return reply.code(400).send({
        error: "token_required",
        message: "An API key is required when saving the AI connection for the first time.",
      });
    }
    if (!jevToken && !current) {
      return reply.code(400).send({
        error: "token_required",
        message: "A jev token is required when saving the AI connection for the first time.",
      });
    }

    const values = {
      openaiBaseUrl,
      openaiModel: parsed.data.openaiModel.trim(),
      openaiTokenCiphertext: openaiToken
        ? encryptSecret(openaiToken)
        : (current?.openaiTokenCiphertext ?? ""),
      openaiTokenHint: openaiToken ? secretHint(openaiToken) : (current?.openaiTokenHint ?? ""),
      jevBaseUrl,
      jevTokenCiphertext: jevToken
        ? encryptSecret(jevToken)
        : (current?.jevTokenCiphertext ?? ""),
      jevTokenHint: jevToken ? secretHint(jevToken) : (current?.jevTokenHint ?? ""),
      maxSteps: parsed.data.maxSteps ?? current?.maxSteps ?? 25,
      updatedAt: new Date(),
    };

    await db.transaction(async (tx) => {
      await tx.delete(aiConnections);
      await tx.insert(aiConnections).values(values);
    });

    const session = currentSession(request);
    await recordAudit({
      action: "ai.connection_saved",
      actorUserId: session.user.id,
      ip: request.ip,
      metadata: {
        openaiBaseUrl,
        openaiModel: values.openaiModel,
        openaiTokenChanged: Boolean(openaiToken),
        jevTokenChanged: Boolean(jevToken),
        maxSteps: values.maxSteps,
      },
    });

    return reply.send({
      configured: true,
      openaiBaseUrl: values.openaiBaseUrl,
      openaiModel: values.openaiModel,
      openaiTokenHint: values.openaiTokenHint,
      jevBaseUrl: values.jevBaseUrl,
      jevTokenHint: values.jevTokenHint,
      maxSteps: values.maxSteps,
    });
  });

  app.post("/settings/ai/test", { preHandler: requireSession }, async (request, reply) => {
    const parsed = testSchema.safeParse(request.body ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const rows = await db.select().from(aiConnections).limit(1);
    const row = rows[0];

    const stored: AiConfig | null = row
      ? {
          openaiBaseUrl: row.openaiBaseUrl,
          openaiModel: row.openaiModel,
          openaiToken: decryptSecret(row.openaiTokenCiphertext),
          jevBaseUrl: row.jevBaseUrl,
          jevToken: decryptSecret(row.jevTokenCiphertext),
          maxSteps: row.maxSteps,
        }
      : null;

    // Overrides let the form be tested before anything is saved.
    const config: AiConfig = {
      openaiBaseUrl: normalizeBaseUrl(
        parsed.data.openaiBaseUrl?.trim() || stored?.openaiBaseUrl || DEFAULT_OPENAI_BASE_URL,
      ),
      openaiModel: parsed.data.openaiModel?.trim() || stored?.openaiModel || "",
      openaiToken: parsed.data.openaiToken?.trim() || stored?.openaiToken || "",
      jevBaseUrl: normalizeBaseUrl(parsed.data.jevBaseUrl?.trim() || stored?.jevBaseUrl || DEFAULT_JEV_BASE_URL),
      jevToken: parsed.data.jevToken?.trim() || stored?.jevToken || "",
      maxSteps: stored?.maxSteps ?? 25,
    };

    if (!config.openaiModel || !config.openaiToken || !config.jevToken) {
      return reply.code(400).send({
        error: "not_configured",
        message: "Provide a model, an API key and a jev token, or save them first.",
      });
    }

    const result = await verifyAiConfig(config);

    // Only record the outcome when it describes the saved configuration.
    const testedStoredConfig =
      !parsed.data.openaiToken && !parsed.data.jevToken && !parsed.data.openaiBaseUrl;
    if (row && testedStoredConfig) {
      await db
        .update(aiConnections)
        .set({
          lastVerifiedAt: new Date(),
          lastVerifyOk: result.ok,
          lastVerifyError: result.ok
            ? null
            : [result.openai.ok ? null : `model: ${result.openai.detail}`, result.jev.ok ? null : `jev: ${result.jev.detail}`]
                .filter(Boolean)
                .join(" · "),
        })
        .where(eq(aiConnections.id, row.id));
    }

    const session = currentSession(request);
    await recordAudit({
      action: "ai.connection_tested",
      actorUserId: session.user.id,
      ip: request.ip,
      metadata: { ok: result.ok, model: result.openai.model },
    });

    return reply.send(result);
  });
}
