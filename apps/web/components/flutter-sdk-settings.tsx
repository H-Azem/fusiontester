"use client";

import { useCallback, useEffect, useRef, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type SdkStatus = {
  available?: boolean;
  path?: string | null;
  /** "volume" once the SDK has been moved off the image, otherwise "baked". */
  source?: string | null;
  version?: string | null;
  revision?: string | null;
  previousRevision?: string | null;
  busy?: boolean;
  state?: { status?: string; message?: string; at?: string } | null;
};

const POLL_MS = 5000;

/**
 * The Flutter SDK every build uses. Updating it is the one settings action that
 * can change how an app builds, so it says that plainly, refuses while a test is
 * running, and keeps the revision it replaced so it can be put back.
 */
export function FlutterSdkSettings() {
  const [sdk, setSdk] = useState<SdkStatus | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"update" | "restore" | null>(null);
  const timer = useRef<number | null>(null);

  const load = useCallback(async () => {
    const response = await fetch("/api/settings/flutter", { cache: "no-store" });

    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }

    const data = (await response.json()) as SdkStatus;
    setSdk(data);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  // While a download is running the page follows it instead of guessing.
  useEffect(() => {
    const running = sdk?.busy === true || sdk?.state?.status === "updating";

    if (!running) {
      if (timer.current !== null) {
        window.clearInterval(timer.current);
        timer.current = null;
      }
      return;
    }

    timer.current = window.setInterval(() => void load(), POLL_MS);

    return () => {
      if (timer.current !== null) {
        window.clearInterval(timer.current);
        timer.current = null;
      }
    };
  }, [sdk?.busy, sdk?.state?.status, load]);

  async function act(action: "update" | "restore") {
    if (
      action === "update" &&
      !window.confirm(
        "Update Flutter to the latest stable? An SDK jump can change how an app resolves its packages, so a build that passed before may fail. The current version is kept and can be restored.",
      )
    ) {
      return;
    }

    setBusy(action);
    setError(null);
    setNotice(null);

    try {
      const response = await fetch(`/api/settings/flutter/${action}`, { method: "POST" });
      const data = (await response.json()) as { message?: string; error?: string };

      if (!response.ok) {
        setError(data.message ?? "The SDK could not be changed.");
        return;
      }

      setNotice(
        action === "update"
          ? "Update started. This takes a few minutes; the page follows it."
          : "Returning to the previous SDK…",
      );
      await load();
    } finally {
      setBusy(null);
    }
  }

  const updating = sdk?.busy === true || sdk?.state?.status === "updating";
  const failed = sdk?.state?.status === "failed";

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Flutter SDK</h2>
          <p className="panel-sub">
            What every build compiles with. Updating it can change how an app resolves its
            packages.
          </p>
        </div>
        <StatusChip
          status={updating ? "running" : failed ? "failed" : "passed"}
          label={updating ? "updating" : failed ? "last change failed" : (sdk?.version ?? "unknown")}
        />
      </div>

      <dl className="meta-grid">
        <div>
          <dt>Version</dt>
          <dd className="mono">{sdk?.version ?? "—"}</dd>
        </div>
        <div>
          <dt>Source</dt>
          <dd>
            {sdk?.source === "volume"
              ? "volume (survives redeploys)"
              : sdk?.available === false
                ? "helper missing in this image"
                : "from the image"}
          </dd>
        </div>
        <div>
          <dt>Path</dt>
          <dd className="mono faint">{sdk?.path ?? "—"}</dd>
        </div>
        <div>
          <dt>Last change</dt>
          <dd>
            {sdk?.state?.message
              ? `${sdk.state.message}${sdk.state.at ? ` · ${relativeTime(sdk.state.at)}` : ""}`
              : "—"}
          </dd>
        </div>
      </dl>

      <p className="md-body-sm muted">
        An update copies the SDK onto a volume the first time, then fetches the new version —
        a few minutes, and only when no test is running. If a build starts failing afterwards,
        restore the previous one.
      </p>

      {error && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {error}
        </p>
      )}

      {notice && (
        <p className="snackbar success" role="status">
          <Icon name="check" size={18} />
          {notice}
        </p>
      )}

      <div className="row">
        <button
          type="button"
          className="md-button filled"
          onClick={() => void act("update")}
          disabled={busy !== null || updating}
        >
          {busy === "update" || updating ? <span className="spinner" /> : <Icon name="live" size={18} />}
          {updating ? "Updating…" : "Update to latest stable"}
        </button>

        <button
          type="button"
          className="md-button tonal"
          onClick={() => void act("restore")}
          disabled={busy !== null || updating || !sdk?.previousRevision}
          title={sdk?.previousRevision ? `Back to ${sdk.previousRevision.slice(0, 8)}` : "Nothing to restore yet"}
        >
          <Icon name="refresh" size={18} />
          Restore previous
        </button>
      </div>
    </section>
  );
}
