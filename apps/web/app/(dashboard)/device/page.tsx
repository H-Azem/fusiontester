"use client";

import { Icon } from "@/components/icons";

/**
 * The device, live. The frame is ws-scrcpy — streamed over WebSocket and driven with
 * real touch, scroll and keyboard events — proxied on this origin so it can sit
 * inside the panel.
 */
export default function DevicePage() {
  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Device</h2>
          <p className="panel-sub">
            The emulator, live. Touch, scroll and the keyboard go straight to the device.
          </p>
        </div>
        <a className="md-button text small" href="/device-live/" target="_blank" rel="noreferrer">
          <Icon name="external" size={16} />
          Open in a new tab
        </a>
      </div>

      <iframe
        src="/device-live/"
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
    </section>
  );
}
