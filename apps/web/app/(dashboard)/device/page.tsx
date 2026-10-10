"use client";

import { useEffect, useRef, useState } from "react";

import { Icon } from "@/components/icons";

const REFRESH_MS = 1200;

/**
 * The device, by hand. The frame is a screenshot, so it is only as quick as adb —
 * this is here to answer one question: is the device usable interactively at all?
 */
export default function DevicePage() {
  const [tick, setTick] = useState(0);
  const [streaming, setStreaming] = useState(true);
  const [text, setText] = useState("");
  const [note, setNote] = useState<string | null>(null);
  const imgRef = useRef<HTMLImageElement>(null);

  useEffect(() => {
    if (!streaming) return;
    const timer = setInterval(() => setTick((value) => value + 1), REFRESH_MS);
    return () => clearInterval(timer);
  }, [streaming]);

  async function send(path: string, body: Record<string, unknown>) {
    try {
      const response = await fetch(`/api/device/${path}`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(body),
      });

      setNote(response.ok ? null : "The device did not accept that.");
    } catch {
      setNote("Could not reach the device.");
    }

    setTick((value) => value + 1);
  }

  /** Screen coordinates, not page ones: the frame is scaled to fit. */
  function tapAt(event: React.MouseEvent<HTMLImageElement>) {
    const image = imgRef.current;
    if (!image) return;

    const rect = image.getBoundingClientRect();

    void send("tap", {
      x: Math.round(((event.clientX - rect.left) / rect.width) * image.naturalWidth),
      y: Math.round(((event.clientY - rect.top) / rect.height) * image.naturalHeight),
    });
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Device</h2>
          <p className="panel-sub">
            Click the screen to tap it, type below to send text. It refreshes every {REFRESH_MS / 1000}s —
            a screenshot loop, so it is as fast as adb is.
          </p>
        </div>
        <button
          type="button"
          className={streaming ? "md-button tonal small" : "md-button filled small"}
          onClick={() => setStreaming((value) => !value)}
        >
          {streaming ? "Pause" : "Resume"}
        </button>
      </div>

      <div className="frame-box">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          ref={imgRef}
          className="live-frame"
          style={{ cursor: "crosshair" }}
          src={`/api/device/frame?t=${tick}`}
          alt="Device screen"
          onClick={tapAt}
          onError={() => setNote("The device did not answer. Is it running?")}
        />
      </div>

      <div className="row" style={{ flexWrap: "wrap", gap: 8, marginTop: 12 }}>
        <button type="button" className="md-button outlined" onClick={() => void send("key", { key: "BACK" })}>
          Back
        </button>
        <button type="button" className="md-button outlined" onClick={() => void send("key", { key: "HOME" })}>
          Home
        </button>
        <button type="button" className="md-button outlined" onClick={() => void send("key", { key: "APP_SWITCH" })}>
          Recent
        </button>
        <button type="button" className="md-button outlined" onClick={() => void send("key", { key: "ENTER" })}>
          Enter
        </button>
        <button type="button" className="md-button outlined" onClick={() => void send("swipe", { x1: 960, y1: 800, x2: 960, y2: 200 })}>
          Scroll up
        </button>
        <button type="button" className="md-button outlined" onClick={() => void send("swipe", { x1: 960, y1: 200, x2: 960, y2: 800 })}>
          Scroll down
        </button>
      </div>

      <form
        className="row"
        style={{ gap: 8, marginTop: 12 }}
        onSubmit={(event) => {
          event.preventDefault();
          if (text.trim() === "") return;
          void send("text", { text });
          setText("");
        }}
      >
        <input
          value={text}
          onChange={(event) => setText(event.target.value)}
          placeholder="Type into the focused field"
          spellCheck={false}
          style={{ flex: 1 }}
        />
        <button type="submit" className="md-button filled">
          <Icon name="chevronRight" size={18} />
          Send
        </button>
      </form>

      {note && (
        <p className="snackbar error" role="alert">
          <Icon name="warning" size={18} />
          {note}
        </p>
      )}
    </section>
  );
}
