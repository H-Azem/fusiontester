import type { FastifyInstance } from "fastify";
import { createReadStream, existsSync } from "node:fs";
import { desc, eq } from "drizzle-orm";
import { z } from "zod";

import { currentSession, requireSession } from "../auth/require-session.js";
import { recordAudit } from "../auth/audit.js";
import { db } from "../db/index.js";
import { runs, projectSettings } from "../db/schema.js";
import {
  aiScreenshotFor,
  enqueueRun,
  hasAiScreenshot,
  hasMaestroScreenshot,
  hasScreenshot,
  maestroScreenshotFor,
  screenshotFor,
  stepsFor,
} from "../runs/pipeline.js";

const createRunSchema = z.object({
  projectId: z.number().int().positive(),
  projectPath: z.string().min(1).max(500),
  branch: z.string().min(1).max(300),
  tests: z.array(z.string().max(300)).max(200).default([]),
  runKinds: z.array(z.enum(["maestro", "ai"])).min(1).max(2),
  environments: z.array(z.enum(["development", "production"])).min(1).max(2),
  orientation: z.enum(["horizontal", "vertical"]).default("horizontal"),
  dartDefines: z.string().max(2000).default(""),
});

const idParamsSchema = z.object({ id: z.string().uuid() });

function serialise(run: typeof runs.$inferSelect, steps: Awaited<ReturnType<typeof stepsFor>>) {
  return {
    id: run.id,
    projectId: run.projectId,
    projectPath: run.projectPath,
    branch: run.branch,
    tests: run.tests,
    runKinds: run.runKinds,
    environments: run.environments,
    orientation: run.orientation,
    dartDefines: run.dartDefines,
    status: run.status,
    currentStep: run.currentStep,
    errorMessage: run.errorMessage,
    createdAt: run.createdAt,
    startedAt: run.startedAt,
    finishedAt: run.finishedAt,
    hasScreenshot: hasScreenshot(run.id),
    hasMaestroScreenshot: hasMaestroScreenshot(run.id),
    hasAiScreenshot: hasAiScreenshot(run.id),
    steps: steps
      .filter((step) => step.runId === run.id)
      .map((step) => ({
        key: step.key,
        label: step.label,
        status: step.status,
        output: step.output,
        startedAt: step.startedAt,
        finishedAt: step.finishedAt,
      })),
  };
}

export async function runRoutes(app: FastifyInstance): Promise<void> {
  app.post("/runs", { preHandler: requireSession }, async (request, reply) => {
    const parsed = createRunSchema.safeParse(request.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const runId = await enqueueRun(parsed.data);

    // Starting a run is also how a project remembers how it was configured, so
    // the next run for this repository opens with the same choices selected.
    await db
      .insert(projectSettings)
      .values({
        projectId: parsed.data.projectId,
        orientation: parsed.data.orientation,
        dartDefines: parsed.data.dartDefines,
      })
      .onConflictDoUpdate({
        target: projectSettings.projectId,
        set: {
          orientation: parsed.data.orientation,
          dartDefines: parsed.data.dartDefines,
          updatedAt: new Date(),
        },
      });

    const session = currentSession(request);
    await recordAudit({
      action: "run.queued",
      actorUserId: session.user.id,
      ip: request.ip,
      metadata: {
        runId,
        projectPath: parsed.data.projectPath,
        branch: parsed.data.branch,
        tests: parsed.data.tests.length,
        runKinds: parsed.data.runKinds,
        environments: parsed.data.environments,
      },
    });

    const rows = await db.select().from(runs).where(eq(runs.id, runId)).limit(1);
    const steps = await stepsFor([runId]);
    const run = rows[0];

    return reply.code(201).send(run ? serialise(run, steps) : { id: runId });
  });

  app.get("/runs", { preHandler: requireSession }, async (_request, reply) => {
    const rows = await db.select().from(runs).orderBy(desc(runs.createdAt)).limit(50);
    const steps = await stepsFor(rows.map((run) => run.id));

    return reply.send({ runs: rows.map((run) => serialise(run, steps)) });
  });

  app.get("/runs/:id/screenshot", { preHandler: requireSession }, async (request, reply) => {
    const parsed = idParamsSchema.safeParse(request.params ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const file = screenshotFor(parsed.data.id);
    if (!existsSync(file)) {
      return reply.code(404).send({ error: "not_found" });
    }

    return reply.type("image/png").send(createReadStream(file));
  });

  app.get(
    "/runs/:id/maestro-screenshot",
    { preHandler: requireSession },
    async (request, reply) => {
      const parsed = idParamsSchema.safeParse(request.params ?? {});
      if (!parsed.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const file = maestroScreenshotFor(parsed.data.id);
      if (!existsSync(file)) {
        return reply.code(404).send({ error: "not_found" });
      }

      return reply.type("image/png").send(createReadStream(file));
    },
  );

  app.get(
    "/runs/:id/ai-screenshot",
    { preHandler: requireSession },
    async (request, reply) => {
      const parsed = idParamsSchema.safeParse(request.params ?? {});
      if (!parsed.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const file = aiScreenshotFor(parsed.data.id);
      if (!existsSync(file)) {
        return reply.code(404).send({ error: "not_found" });
      }

      return reply.type("image/png").send(createReadStream(file));
    },
  );

  app.get("/runs/:id", { preHandler: requireSession }, async (request, reply) => {
    const parsed = idParamsSchema.safeParse(request.params ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const rows = await db.select().from(runs).where(eq(runs.id, parsed.data.id)).limit(1);
    const run = rows[0];
    if (!run) {
      return reply.code(404).send({ error: "not_found" });
    }

    return reply.send(serialise(run, await stepsFor([run.id])));
  });
}
