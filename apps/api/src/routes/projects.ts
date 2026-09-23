import type { FastifyInstance } from "fastify";
import { eq } from "drizzle-orm";
import { z } from "zod";

import { requireSession } from "../auth/require-session.js";
import { db } from "../db/index.js";
import { projectSettings } from "../db/schema.js";

const paramsSchema = z.object({
  projectId: z.coerce.number().int().positive(),
});

/** Orientation used until a project records its own choice. */
export const DEFAULT_ORIENTATION = "horizontal";

export async function projectRoutes(app: FastifyInstance): Promise<void> {
  app.get(
    "/projects/:projectId/settings",
    { preHandler: requireSession },
    async (request, reply) => {
      const parsed = paramsSchema.safeParse(request.params ?? {});
      if (!parsed.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const rows = await db
        .select()
        .from(projectSettings)
        .where(eq(projectSettings.projectId, parsed.data.projectId))
        .limit(1);

      return reply.send({
        projectId: parsed.data.projectId,
        orientation: rows[0]?.orientation ?? DEFAULT_ORIENTATION,
        dartDefines: rows[0]?.dartDefines ?? "",
      });
    },
  );
}
