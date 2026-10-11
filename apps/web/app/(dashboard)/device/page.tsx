"use client";

import { useState } from "react";

import { Icon } from "@/components/icons";

/**
 * The device, live. The frame is ws-scrcpy — streamed over WebSocket and driven with
 * real touch, scroll and keyboard events — proxied on this origin so it can sit
 * inside the panel.
 */
export default function DevicePage() {
  const [started, setStarted] = useState(false);
  const [run, setRun] = useState(0);

  /** Remounting the frame reloads the client, which re-runs its own auto-open. */
  function start() {
    setStarted(true);
    setRun((value) => value + 1);
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Device</h2>
          <p className="panel-sub">
            The emulator, live. Touch, scroll and the keyboard go straight to the device.
          </p>
        </div>
        <div className="row" style={{ gap: 8 }}>
          {started && (
            <button type="button" className="md-button tonal small" onClick={start}>
              <Icon name="refresh" size={16} />
              Restart
            </button>
          )}
          <button type="button" className="md-button filled" onClick={start}>
            <Icon name="device" size={18} />
            Start emulator
          </button>
        </div>
      </div>

      {started ? (
        <iframe
          key={run}
          src={`/device-live/?v=${run}`}
          title="Device"
          allow="fullscreen"
          style={{
            width: "100%",
            height: "min(78vh, 900px)",
            border: "1px solid color-mix(in srgb, currentColor 12%, transparent)",
            borderRadius: 16,
            background: "#000",
          }}
        />
      ) : (
        <p className="md-body muted">Press “Start emulator” to open the device here.</p>
      )}
    </section>
  );
}
