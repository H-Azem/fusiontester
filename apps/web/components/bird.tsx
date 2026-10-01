/**
 * The mascot: a bluebird whose pose names its state.
 *
 * It is used where a human would otherwise read a wall of the same grey text —
 * empty surfaces, a run's verdict, a failure — and never as decoration. Sizes
 * stay small (28-96px) so the interface, not the bird, carries the information.
 */
export type BirdState =
  | "completed"
  | "empty"
  | "failed"
  | "info"
  | "loading"
  | "locked"
  | "maintenance"
  | "not-found"
  | "processing"
  | "saved"
  | "success"
  | "unknown"
  | "updated"
  | "warning";

/** Which pose belongs to which run status, so status reads the same everywhere. */
export const BIRD_FOR_STATUS: Record<string, BirdState> = {
  passed: "success",
  done: "completed",
  failed: "failed",
  running: "processing",
  queued: "loading",
  pending: "loading",
  skipped: "unknown",
};

export function Bird({
  state,
  size = 96,
  className = "",
  float = false,
}: {
  state: BirdState;
  size?: number;
  className?: string;
  float?: boolean;
}) {
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      className={`bird${float ? " float" : ""}${className ? ` ${className}` : ""}`}
      src={`/bird/${state}.png`}
      width={size}
      height={size}
      alt=""
      aria-hidden="true"
      decoding="async"
      draggable={false}
    />
  );
}
