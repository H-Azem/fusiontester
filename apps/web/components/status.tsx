import type { ReactNode } from "react";

import { Icon } from "./icons";

/**
 * Status is carried by a dot, a word and a colour — never colour alone, so it
 * survives a colourblind reader and a grayscale print.
 */
export function statusTone(status: string): string {
  if (status === "passed" || status === "done") return "passed";
  if (status === "failed") return "failed";
  if (status === "running") return "running";
  if (status === "skipped") return "skipped";
  return "queued";
}

export function StatusChip({ status, label }: { status: string; label?: string }) {
  const tone = statusTone(status);

  return (
    <span className={`status ${tone}`}>
      <span className="dot" aria-hidden="true" />
      {label ?? status}
    </span>
  );
}

/** The stepper's marker glyph for a step state. */
export function stepGlyph(status: string): ReactNode {
  if (status === "done") return <Icon name="check" size={16} />;
  if (status === "failed") return <Icon name="error" size={16} />;
  if (status === "running") return <span className="spinner" style={{ width: 14, height: 14 }} />;
  if (status === "skipped") return <Icon name="close" size={14} />;
  return <span aria-hidden="true">·</span>;
}

export function relativeTime(value: string): string {
  const diff = Date.now() - new Date(value).getTime();
  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return "just now";
  if (minutes < 60) return `${minutes} min ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} h ago`;
  return new Date(value).toLocaleDateString();
}

export function shortDuration(from: string | null, to: string | null): string | null {
  if (!from || !to) return null;
  const seconds = Math.max(0, Math.round((new Date(to).getTime() - new Date(from).getTime()) / 1000));
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  return `${minutes}m ${seconds % 60}s`;
}
