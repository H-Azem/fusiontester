"use client";

import { useEffect, useState } from "react";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:4000";

type Health = {
  status: string;
  service: string;
  version: string;
  timestamp: string;
};

export default function Home() {
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const res = await fetch(`${API_URL}/health`);
        if (!res.ok) {
          throw new Error(`API responded with ${res.status}`);
        }
        const data = (await res.json()) as Health;
        if (!cancelled) setHealth(data);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : String(err));
        }
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <main>
      <h1>Fusion Tester</h1>
      <p className="subtitle">Phase 1 — dashboard talking to the API</p>

      <section>
        <h2>API health</h2>
        {error && <pre className="error">Unreachable: {error}</pre>}
        {!error && !health && <pre>Loading…</pre>}
        {health && <pre>{JSON.stringify(health, null, 2)}</pre>}
      </section>
    </main>
  );
}
