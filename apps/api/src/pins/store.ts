import { and, eq } from "drizzle-orm";

import { db } from "../db/index.js";
import { pins } from "../db/schema.js";

export type PinKind = "repository" | "branch";

export type Pin = {
  id: string;
  kind: PinKind;
  projectId: number;
  projectPath: string;
  /** Empty string for a repository pin. */
  branch: string;
  createdAt: Date;
};

export async function listPins(): Promise<Pin[]> {
  const rows = await db.select().from(pins);

  return rows.map((row) => ({
    id: row.id,
    kind: row.kind as PinKind,
    projectId: row.projectId,
    projectPath: row.projectPath,
    branch: row.branch,
    createdAt: row.createdAt,
  }));
}

export async function upsertPin(input: {
  kind: PinKind;
  projectId: number;
  projectPath: string;
  branch?: string;
}): Promise<void> {
  await db
    .insert(pins)
    .values({
      kind: input.kind,
      projectId: input.projectId,
      projectPath: input.projectPath,
      branch: input.branch ?? "",
    })
    .onConflictDoUpdate({
      target: [pins.kind, pins.projectId, pins.branch],
      // Keeps a renamed project's display path fresh on re-pin.
      set: { projectPath: input.projectPath },
    });
}

export async function deletePin(input: {
  kind: PinKind;
  projectId: number;
  branch?: string;
}): Promise<boolean> {
  const removed = await db
    .delete(pins)
    .where(
      and(
        eq(pins.kind, input.kind),
        eq(pins.projectId, input.projectId),
        eq(pins.branch, input.branch ?? ""),
      ),
    )
    .returning({ id: pins.id });

  return removed.length > 0;
}
