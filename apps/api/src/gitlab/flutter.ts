import { parse as parseYaml } from "yaml";

import { getFileRaw, getTree, type GitlabConnection } from "./client.js";

export const MAESTRO_FLOWS_PATH = ".maestro/flows";

/**
 * Folders under `.maestro/flows` that hold reusable subflows rather than
 * tests. `shared` is the Maestro convention for `steps_*` helpers, which would
 * otherwise show up as a selectable test.
 */
export const NON_TEST_FLOW_FOLDERS = new Set(["shared"]);

export function isTestFolder(folderName: string): boolean {
  return !NON_TEST_FLOW_FOLDERS.has(folderName);
}

/**
 * Folders that exercise the whole app. Choosing one makes picking individual
 * tests meaningless, so the dashboard disables the others while it is selected.
 */
export const EXCLUSIVE_TEST_FOLDERS = new Set(["full_app"]);

export function isExclusiveTestFolder(folderName: string): boolean {
  return EXCLUSIVE_TEST_FOLDERS.has(folderName);
}

/** Presence of any of these marks a runnable application rather than a library. */
const PLATFORM_DIRECTORIES = ["android", "ios", "web", "linux", "macos", "windows"];

export type BranchCheck = {
  ref: string;
  isFlutterApp: boolean;
  isFlutterAppReason: string;
  hasMaestroFlows: boolean;
  hasMaestroFlowsReason: string;
  canContinue: boolean;
};

export type MaestroTest = {
  name: string;
  displayName: string;
  path: string;
  /** Whole-suite test: selecting it rules out selecting others. */
  exclusive: boolean;
};

/**
 * Turns a folder name into a readable label: `orders_list` -> `Orders list`.
 * Only the first character is upper-cased, so multi-word names stay natural.
 */
export function humanizeTestName(folder: string): string {
  const spaced = folder.replace(/_/g, " ").trim();
  if (spaced === "") return folder;
  return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

/**
 * Decides whether a branch is testable: it must be a Flutter *application*
 * (not a package, not a plugin) and must carry a `.maestro/flows` directory.
 *
 * Distinguishing them from pubspec.yaml:
 *   - no `flutter:` section          -> plain Dart package
 *   - `flutter.plugin` present       -> a Flutter plugin
 *   - no platform directories        -> a Flutter package, nothing to launch
 */
export async function checkBranch(
  connection: GitlabConnection,
  projectId: number,
  ref: string,
): Promise<BranchCheck> {
  const rootTree = await getTree(connection, projectId, ref);
  const rootEntries = rootTree ?? [];

  const hasPubspec = rootEntries.some(
    (entry) => entry.name === "pubspec.yaml" && entry.type === "blob",
  );
  const platformDirectories = PLATFORM_DIRECTORIES.filter((directory) =>
    rootEntries.some((entry) => entry.name === directory && entry.type === "tree"),
  );

  let isFlutterApp = false;
  let isFlutterAppReason = "";

  if (!hasPubspec) {
    isFlutterAppReason =
      "No pubspec.yaml at the repository root, so this is not a Dart or Flutter project.";
  } else {
    const contents = await getFileRaw(connection, projectId, "pubspec.yaml", ref);

    if (contents === null) {
      isFlutterAppReason = "pubspec.yaml could not be read at this branch.";
    } else {
      let parsed: unknown;
      let parseFailed = false;

      try {
        parsed = parseYaml(contents);
      } catch {
        parseFailed = true;
      }

      if (parseFailed) {
        isFlutterAppReason = "pubspec.yaml could not be parsed as YAML.";
      } else if (parsed === null || typeof parsed !== "object") {
        isFlutterAppReason = "pubspec.yaml is empty or malformed.";
      } else {
        const flutterSection = (parsed as Record<string, unknown>).flutter;

        if (flutterSection === null || typeof flutterSection !== "object") {
          isFlutterAppReason =
            "This is a plain Dart package — pubspec.yaml has no flutter section.";
        } else if ("plugin" in (flutterSection as Record<string, unknown>)) {
          isFlutterAppReason =
            "This is a Flutter plugin (pubspec.yaml declares flutter.plugin), not an application.";
        } else if (platformDirectories.length === 0) {
          isFlutterAppReason =
            "This is a Flutter package — it has no platform directories, so there is nothing to launch.";
        } else {
          isFlutterApp = true;
          isFlutterAppReason = `Flutter application (${platformDirectories.join(", ")}).`;
        }
      }
    }
  }

  const flowsTree = await getTree(connection, projectId, ref, MAESTRO_FLOWS_PATH);
  const allFlowFolders = (flowsTree ?? []).filter((entry) => entry.type === "tree");
  const testFolders = allFlowFolders.filter((entry) => isTestFolder(entry.name));

  let hasMaestroFlows = false;
  let hasMaestroFlowsReason: string;

  if (flowsTree === null) {
    hasMaestroFlowsReason = `No ${MAESTRO_FLOWS_PATH} folder at the project root.`;
  } else if (testFolders.length === 0) {
    hasMaestroFlowsReason =
      allFlowFolders.length > 0
        ? `${MAESTRO_FLOWS_PATH} contains only shared subflow folders (${allFlowFolders
            .map((folder) => folder.name)
            .join(", ")}), with no tests.`
        : `${MAESTRO_FLOWS_PATH} exists but contains no test folders.`;
  } else {
    hasMaestroFlows = true;
    hasMaestroFlowsReason = `Found ${testFolders.length} test folder${
      testFolders.length === 1 ? "" : "s"
    } in ${MAESTRO_FLOWS_PATH}.`;
  }

  return {
    ref,
    isFlutterApp,
    isFlutterAppReason,
    hasMaestroFlows,
    hasMaestroFlowsReason,
    canContinue: isFlutterApp && hasMaestroFlows,
  };
}

/** Each folder directly under `.maestro/flows` is one test. */
export async function listMaestroTests(
  connection: GitlabConnection,
  projectId: number,
  ref: string,
): Promise<MaestroTest[]> {
  const tree = await getTree(connection, projectId, ref, MAESTRO_FLOWS_PATH);
  if (tree === null) return [];

  return tree
    .filter((entry) => entry.type === "tree" && isTestFolder(entry.name))
    .map((entry) => ({
      name: entry.name,
      displayName: humanizeTestName(entry.name),
      path: entry.path,
      exclusive: isExclusiveTestFolder(entry.name),
    }))
    .sort((a, b) => {
      // Whole-suite tests lead the list; everything else is alphabetical.
      const exclusiveFirst = Number(b.exclusive) - Number(a.exclusive);
      if (exclusiveFirst !== 0) return exclusiveFirst;
      return a.displayName.localeCompare(b.displayName);
    });
}
