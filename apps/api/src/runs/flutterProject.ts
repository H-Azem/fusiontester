import { readFile } from "node:fs/promises";
import { join } from "node:path";

export type LaunchTarget = {
  name: string;
  /** Entry point relative to the repository root, e.g. lib/mains/main_general_app.dart */
  program: string;
  args: string[];
};

/**
 * VS Code allows comments and trailing commas in launch.json, so it is not
 * strictly valid JSON. Strip both while leaving string literals untouched.
 */
function stripJsonComments(input: string): string {
  let out = "";
  let inString = false;
  let inLineComment = false;
  let inBlockComment = false;

  for (let i = 0; i < input.length; i++) {
    const char = input[i] ?? "";
    const next = input[i + 1] ?? "";

    if (inLineComment) {
      if (char === "\n") {
        inLineComment = false;
        out += char;
      }
      continue;
    }

    if (inBlockComment) {
      if (char === "*" && next === "/") {
        inBlockComment = false;
        i++;
      }
      continue;
    }

    if (inString) {
      out += char;
      if (char === "\\") {
        out += next;
        i++;
        continue;
      }
      if (char === '"') inString = false;
      continue;
    }

    if (char === '"') {
      inString = true;
      out += char;
      continue;
    }

    if (char === "/" && next === "/") {
      inLineComment = true;
      i++;
      continue;
    }

    if (char === "/" && next === "*") {
      inBlockComment = true;
      i++;
      continue;
    }

    out += char;
  }

  return out.replace(/,(\s*[}\]])/g, "$1");
}

type RawConfiguration = {
  name?: unknown;
  program?: unknown;
  args?: unknown;
};

/**
 * Finds the entry point to run, using the first launch configuration whose name
 * mentions "develop" (case-insensitive) — the same rule used for local runs.
 */
export async function readLaunchTarget(repoDir: string): Promise<LaunchTarget> {
  const file = join(repoDir, ".vscode", "launch.json");

  let raw: string;
  try {
    raw = await readFile(file, "utf8");
  } catch {
    throw new Error(
      "No .vscode/launch.json in the repository, so the app entry point is unknown.",
    );
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(stripJsonComments(raw));
  } catch {
    throw new Error(".vscode/launch.json could not be parsed as JSON.");
  }

  const configurations = (parsed as { configurations?: unknown } | null)?.configurations;
  if (!Array.isArray(configurations)) {
    throw new Error(".vscode/launch.json has no configurations array.");
  }

  const match = (configurations as RawConfiguration[]).find(
    (entry) => typeof entry?.name === "string" && entry.name.toLowerCase().includes("develop"),
  );

  if (!match) {
    throw new Error(
      'No launch configuration whose name contains "develop" was found in .vscode/launch.json.',
    );
  }

  if (typeof match.program !== "string" || match.program.trim() === "") {
    throw new Error(
      `Launch configuration "${String(match.name)}" does not define a program entry point.`,
    );
  }

  return {
    name: String(match.name),
    program: match.program.trim(),
    args: Array.isArray(match.args)
      ? match.args.filter((arg): arg is string => typeof arg === "string")
      : [],
  };
}
