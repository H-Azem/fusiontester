import { execFile } from "node:child_process";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

export const DEFAULT_COMMAND_TIMEOUT_MS = 10 * 60 * 1000;

/**
 * Windows cannot execFile a .bat/.cmd directly (flutter ships as flutter.bat),
 * so those go through cmd.exe. On Linux the command is invoked directly.
 */
function invocation(command: string, args: string[]): { file: string; args: string[] } {
  if (process.platform === "win32") {
    return { file: "cmd.exe", args: ["/d", "/s", "/c", command, ...args] };
  }
  return { file: command, args };
}

export type CommandResult = { ok: boolean; output: string };

export async function runCommand(
  command: string,
  args: string[],
  cwd: string,
  timeoutMs: number = DEFAULT_COMMAND_TIMEOUT_MS,
): Promise<CommandResult> {
  const { file, args: fileArgs } = invocation(command, args);

  try {
    const { stdout, stderr } = await execFileAsync(file, fileArgs, {
      cwd,
      timeout: timeoutMs,
      maxBuffer: 16 * 1024 * 1024,
      windowsHide: true,
    });
    return { ok: true, output: [stdout, stderr].filter(Boolean).join("\n") };
  } catch (error) {
    const failure = error as { stdout?: string; stderr?: string; message?: string };
    const output =
      [failure.stdout, failure.stderr].filter(Boolean).join("\n").trim() ||
      failure.message ||
      "Command failed";
    return { ok: false, output };
  }
}
