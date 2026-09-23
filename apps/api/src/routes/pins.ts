import type { FastifyInstance } from "fastify";
import { z } from "zod";

import { requireSession } from "../auth/require-session.js";
import { deletePin, listPins, upsertPin } from "../pins/store.js";

const pinBodySchema = z.object({
  kind: z.enum(["repository", "branch"]),
  projectId: z.number().int().positive(),
  projectPath: z.string().min(1).max(500),
  branch: z.string().max(300).optional(),
});

const deleteQuerySchema = z.object({
  kind: z.enum(["repository", "branch"]),
  projectId: z.coerce.number().int().positive(),
  branch: z.string().max(300).optional(),
});

export async function pinRoutes(app: FastifyInstance): Promise<void> {
  app.get("/pins", { preHandler: requireSession }, async (_request, reply) => {
    const rows = await listPins();

    return reply.send({
      pins: rows.map((pin) => ({
        kind: pin.kind,
        projectId: pin.projectId,
        projectPath: pin.projectPath,
        branch: pin.branch,
      })),
    });
  });

  app.put("/pins", { preHandler: requireSession }, async (request, reply) => {
    const parsed = pinBodySchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    if (parsed.data.kind === "branch" && !parsed.data.branch) {
      return reply.code(400).send({
        error: "branch_required",
        message: "A branch name is required for branch pins.",
      });
    }

    await upsertPin(parsed.data);
    return reply.send({ ok: true });
  });

  app.delete("/pins", { preHandler: requireSession }, async (request, reply) => {
    const parsed = deleteQuerySchema.safeParse(request.query ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const removed = await deletePin(parsed.data);
    return reply.send({ ok: true, removed });
  });
}
