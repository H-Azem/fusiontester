import { and, asc, eq } from "drizzle-orm";

import { db } from "../db/index.js";
import { runs } from "../db/schema.js";
import { executeRun } from "./pipeline.js";

const POLL_INTERVAL_MS = 1000;

let interval: NodeJS.Timeout | null = null;

export type RunWorkerHandle = {
  /** Stops polling. Used by tests so the loop cannot outlive the database. */
  stop: () => void;
};

/**
 * Claims the oldest queued run. The conditional update makes the claim atomic,
 * so a run can never be picked up twice.
 */
async function claimNextRun(): Promise<string | null> {
  const queued = await db
    .select({ id: runs.id })
    .from(runs)
    .where(eq(runs.status, "queued"))
    .orderBy(asc(runs.createdAt))
    .limit(1);

  const candidate = queued[0];
  if (!candidate) return null;

  const claimed = await db
    .update(runs)
    .set({ status: "running", startedAt: new Date() })
    .where(and(eq(runs.id, candidate.id), eq(runs.status, "queued")))
    .returning({ id: runs.id });

  return claimed[0]?.id ?? null;
}

/** Runs left mid-flight by a previous process are marked failed, not silently stuck. */
async function recoverInterruptedRuns(): Promise<void> {
  const interrupted = await db
    .update(runs)
    .set({
      status: "failed",
      errorMessage: "Interrupted by a server restart.",
      finishedAt: new Date(),
    })
    .where(eq(runs.status, "running"))
    .returning({ id: runs.id });

  if (interrupted.length > 0) {
    console.log(`marked ${interrupted.length} interrupted run(s) as failed`);
  }
}

/**
 * Single-flight queue: one run at a time, oldest first. Runs live in the
 * database, so they continue regardless of whether a browser is connected.
 */
export function startRunWorker(): RunWorkerHandle {
  if (interval) {
    return {
      stop: () => {
        if (interval) clearInterval(interval);
        interval = null;
      },
    };
  }

  let busy = false;

  async function drain(): Promise<void> {
    let next = await claimNextRun();
    while (next !== null) {
      await executeRun(next);
      next = await claimNextRun();
    }
  }

  async function tick(): Promise<void> {
    if (busy) return;
    busy = true;
    try {
      await drain();
    } catch (error) {
      // A failure here must never kill the loop.
      console.error("run worker error:", error);
    } finally {
      busy = false;
    }
  }

  void recoverInterruptedRuns()
    .then(() => {
      console.log("run worker started");
      interval = setInterval(() => {
        void tick();
      }, POLL_INTERVAL_MS);
      void tick();
    })
    .catch((error: unknown) => {
      console.error("failed to start run worker:", error);
    });

  return {
    stop: () => {
      if (interval) clearInterval(interval);
      interval = null;
    },
  };
}
