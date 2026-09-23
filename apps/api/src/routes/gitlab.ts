import type { FastifyInstance, FastifyReply } from "fastify";
import { z } from "zod";

import { requireSession } from "../auth/require-session.js";
import {
  GitlabApiError,
  fetchProjectsByIds,
  getStoredConnection,
  listBranches,
  listProjects,
  redact,
  type GitlabConnection,
} from "../gitlab/client.js";
import { listPins } from "../pins/store.js";
import { checkBranch, listMaestroTests } from "../gitlab/flutter.js";

const listQuerySchema = z.object({
  search: z.string().max(200).optional(),
});

const branchParamsSchema = z.object({
  projectId: z.coerce.number().int().positive(),
});

const refQuerySchema = z.object({
  ref: z.string().min(1).max(300),
});

const NOT_CONFIGURED_MESSAGE = "Connect GitLab on the settings page before loading repositories.";

function sendGitlabError(
  reply: FastifyReply,
  error: unknown,
  connection: GitlabConnection,
): FastifyReply {
  if (error instanceof GitlabApiError) {
    return reply.code(502).send({ error: "gitlab_error", message: error.message });
  }

  const message = error instanceof Error ? error.message : String(error);
  return reply.code(502).send({
    error: "unreachable",
    message: redact(message, connection.token),
  });
}

/**
 * Projects are returned in the gateway's natural order with a `pinned` flag.
 * Ordering is applied by the dashboard, so that unpinning an item re-sorts it
 * back to its natural position immediately instead of leaving it stranded at
 * the top until the next fetch.
 */
export async function gitlabRoutes(app: FastifyInstance): Promise<void> {
  app.get("/gitlab/projects", { preHandler: requireSession }, async (request, reply) => {
    const parsed = listQuerySchema.safeParse(request.query ?? {});
    if (!parsed.success) {
      return reply.code(400).send({ error: "invalid_request" });
    }

    const connection = await getStoredConnection();
    if (!connection) {
      return reply.code(409).send({ error: "not_configured", message: NOT_CONFIGURED_MESSAGE });
    }

    try {
      const { projects, truncated } = await listProjects(connection, {
        search: parsed.data.search,
      });

      const allPins = await listPins();
      const pinnedIds = new Set(
        allPins.filter((pin) => pin.kind === "repository").map((pin) => pin.projectId),
      );

      // A pinned project has to appear even when it falls outside the recency
      // window that the listing fetched.
      const missingPinnedIds = [...pinnedIds].filter(
        (id) => !projects.some((project) => project.id === id),
      );
      if (missingPinnedIds.length > 0) {
        projects.unshift(...(await fetchProjectsByIds(connection, missingPinnedIds)));
      }

      return reply.send({
        truncated,
        projects: projects.map((project) => ({
          id: project.id,
          name: project.name,
          pathWithNamespace: project.path_with_namespace,
          defaultBranch: project.default_branch,
          visibility: project.visibility,
          lastActivityAt: project.last_activity_at,
          webUrl: project.web_url,
          pinned: pinnedIds.has(project.id),
        })),
      });
    } catch (error) {
      return sendGitlabError(reply, error, connection);
    }
  });

  app.get(
    "/gitlab/projects/:projectId/branches",
    { preHandler: requireSession },
    async (request, reply) => {
      const parsed = branchParamsSchema.safeParse(request.params ?? {});
      if (!parsed.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const connection = await getStoredConnection();
      if (!connection) {
        return reply.code(409).send({ error: "not_configured", message: NOT_CONFIGURED_MESSAGE });
      }

      const projectId = parsed.data.projectId;

      try {
        const { branches, truncated } = await listBranches(connection, projectId);

        const allPins = await listPins();
        const pinnedBranches = new Set(
          allPins
            .filter((pin) => pin.kind === "branch" && pin.projectId === projectId)
            .map((pin) => pin.branch),
        );

        return reply.send({
          truncated,
          branches: branches.map((branch) => ({
            name: branch.name,
            default: branch.default,
            protected: branch.protected,
            lastCommitAt: branch.commit?.created_at ?? null,
            pinned: pinnedBranches.has(branch.name),
          })),
        });
      } catch (error) {
        return sendGitlabError(reply, error, connection);
      }
    },
  );

  app.get(
    "/gitlab/projects/:projectId/branch-check",
    { preHandler: requireSession },
    async (request, reply) => {
      const params = branchParamsSchema.safeParse(request.params ?? {});
      const query = refQuerySchema.safeParse(request.query ?? {});
      if (!params.success || !query.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const connection = await getStoredConnection();
      if (!connection) {
        return reply.code(409).send({ error: "not_configured", message: NOT_CONFIGURED_MESSAGE });
      }

      try {
        return reply.send(
          await checkBranch(connection, params.data.projectId, query.data.ref),
        );
      } catch (error) {
        return sendGitlabError(reply, error, connection);
      }
    },
  );

  app.get(
    "/gitlab/projects/:projectId/tests",
    { preHandler: requireSession },
    async (request, reply) => {
      const params = branchParamsSchema.safeParse(request.params ?? {});
      const query = refQuerySchema.safeParse(request.query ?? {});
      if (!params.success || !query.success) {
        return reply.code(400).send({ error: "invalid_request" });
      }

      const connection = await getStoredConnection();
      if (!connection) {
        return reply.code(409).send({ error: "not_configured", message: NOT_CONFIGURED_MESSAGE });
      }

      try {
        const tests = await listMaestroTests(connection, params.data.projectId, query.data.ref);
        return reply.send({ ref: query.data.ref, tests });
      } catch (error) {
        return sendGitlabError(reply, error, connection);
      }
    },
  );
}
