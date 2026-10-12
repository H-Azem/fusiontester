"use client";

import { useCallback, useEffect, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type ManualApp = {
  id: number;
  title: string;
  branch: string;
  builtAt?: string | null;
  ready: boolean;
  buildError?: string | null;
};

const STATE_POLL_MS = 5000;

/**
 * The device, live, with the apps that can be installed on it.
 *
 * The frame is ws-scrcpy — streamed over WebSocket and driven with real touch, scroll
 * and keyboard. A build puts the device to sleep to free memory, so the panel says
 * that instead of leaving a bare "Disconnected" behind.
 */
export default function DevicePage() {
  const [started, setStarted] = useState(false);
  const [run, setRun] = useState(0);
  const [apps, setApps] = useState<ManualApp[]>([]);
  const [reachable, setReachable] = useState(true);
  const [installingId, setInstallingId] = useState<number | null>(null);
  const [note, setNote] = useState<string | null>(null);

  const load = useCallback(async () => {
    const [appsResponse, stateResponse] = await Promise.all([
      fetch("/api/device/apps", { cache: "no-store" }),
      fetch("/api/device/state", { cache: "no-store" }),
    ]);

    if (appsResponse.ok) {
      const data = (await appsResponse.json()) as { apps?: ManualApp[] };
      setApps(data.apps ?? []);
    }

    if (stateResponse.ok) {
      const data = (await stateResponse.json()) as { reachable?: boolean };
      setReachable(data.reachable === true);
    }
  }, []);

  useEffect(() => {
    if (!started) return;

    void load();
    const timer = setInterval(() => void load(), STATE_POLL_MS);
    return () => clearInterval(timer);
  }, [started, load]);

  /** Remounting the frame reloads the client, which re-runs its own auto-open. */
  function start() {
    setStarted(true);
    setRun((value) => value + 1);
  }

  async function install(app: ManualApp) {
    setInstallingId(app.id);
    setNote(null);

    try {
      const response = await fetch("/api/device/install", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ appId: app.id }),
      });

      const data = (await response.json()) as { message?: string; detail?: string };
      setNote(response.ok ? `${app.title} installed.` : (data.message ?? "The install failed."));
    } finally {
      setInstallingId(null);
    }
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Manual test</h2>
          <p className="panel-sub">
            The emulator, live. Touch, scroll and the keyboard go straight to the device.
          </p>
        </div>
        <div className="row" style={{ gap: 8 }}>
          {started ? (
            <button type="button" className="md-button tonal" onClick={start}>
              <Icon name="refresh" size={18} />
              Restart emulator
            </button>
          ) : (
            <button type="button" className="md-button filled" onClick={start}>
              <Icon name="device" size={18} />
              Start emulator
            </button>
          )}
        </div>
      </div>

      {!started ? (
        <p className="md-body muted">Press “Start emulator” to open the device here.</p>
      ) : (
        <div className="stack">
          {!reachable && (
            <p className="snackbar error" role="alert">
              <Icon name="warning" size={18} />
              The device is offline — a build puts it to sleep for a few minutes and then wakes it.
            </p>
          )}

          <iframe
            key={run}
            src={`/device-live/?v=${run}`}
            title="Device"
            allow="fullscreen"
            style={{
              width: "100%",
              height: "min(70vh, 820px)",
              border: "1px solid color-mix(in srgb, currentColor 12%, transparent)",
              borderRadius: 16,
              background: "#000",
            }}
          />

          {note && (
            <p className="snackbar success" role="status">
              <Icon name="check" size={18} />
              {note}
            </p>
          )}

          <div className="panel-head" style={{ marginTop: 8 }}>
            <div>
              <h3 className="md-title">Install an app</h3>
              <p className="panel-sub">The newest build of each app, installed at a click.</p>
            </div>
          </div>

          {apps.length === 0 ? (
            <p className="md-body-sm muted">
              No apps yet — add them under Settings → Manual test apps.
            </p>
          ) : (
            <div className="stack">
              {apps.map((app) => (
                <article key={app.id} className="card">
                  <div className="row" style={{ justifyContent: "space-between", gap: 12, alignItems: "center" }}>
                    <span className="stack" style={{ gap: 2, flex: 1 }}>
                      <span className="md-title-sm">{app.title}</span>
                      <span className="md-body-sm muted">
                        <span className="mono">{app.branch}</span> ·{" "}
                        {app.builtAt ? `built ${relativeTime(app.builtAt)}` : "no build yet"}
                      </span>
                    </span>
                    <StatusChip
                      status={app.buildError ? "failed" : app.ready ? "passed" : "queued"}
                      label={app.buildError ? "build failed" : app.ready ? "ready" : "no build"}
                    />
                    <button
                      type="button"
                      className="md-button filled"
                      onClick={() => void install(app)}
                      disabled={!app.ready || !reachable || installingId === app.id}
                    >
                      {installingId === app.id ? <span className="spinner" /> : <Icon name="device" size={18} />}
                      {installingId === app.id ? "Installing…" : "Install"}
                    </button>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>
      )}
    </section>
  );
}
