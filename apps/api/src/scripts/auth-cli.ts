import { client, runMigrations } from "../db/index.js";
import { listActiveBlocks, releaseBlock } from "../auth/lockout.js";
import { recordAudit } from "../auth/audit.js";

function usage(): never {
  process.stdout.write(
    [
      "Usage:",
      "  pnpm --filter @fusion-tester/api auth list-blocks",
      "  pnpm --filter @fusion-tester/api auth unblock <ip>",
      "",
    ].join("\n"),
  );
  process.exit(1);
}

async function main(): Promise<void> {
  const [command, argument] = process.argv.slice(2);
  await runMigrations();

  if (command === "list-blocks") {
    const blocks = await listActiveBlocks();
    if (blocks.length === 0) {
      process.stdout.write("No active IP blocks.\n");
      return;
    }
    for (const block of blocks) {
      process.stdout.write(
        `${block.ip}  failures=${block.failedCount}  until=${block.expiresAt.toISOString()}\n`,
      );
    }
    return;
  }

  if (command === "unblock") {
    if (!argument) usage();
    const released = await releaseBlock(argument, "cli");
    await recordAudit({
      action: "ip.released",
      ip: argument,
      metadata: { releasedBy: "cli", found: released },
    });
    process.stdout.write(
      released ? `Released block for ${argument}.\n` : `No active block for ${argument}.\n`,
    );
    return;
  }

  usage();
}

main()
  .then(async () => {
    await client.close();
  })
  .catch(async (error: unknown) => {
    process.stderr.write(`${String(error)}\n`);
    await client.close();
    process.exit(1);
  });
