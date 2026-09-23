"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

type User = { id: string; username: string; role: string };
type Health = { status: string; service: string; version: string; timestamp: string };

export default function Home() {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [health, setHealth] = useState<Health | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [meResponse, healthResponse] = await Promise.all([
          fetch("/api/auth/me", { cache: "no-store" }),
          fetch("/api/health", { cache: "no-store" }),
        ]);

        if (meResponse.status === 401) {
          router.replace("/login");
          return;
        }

        if (!cancelled) {
          setUser((await meResponse.json()).user as User);
          setHealth((await healthResponse.json()) as Health);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : String(err));
        }
      }
    }

    void load();

    return () => {
      cancelled = true;
    };
  }, [router]);

  async function handleLogout() {
    await fetch("/api/auth/logout", { method: "POST" });
    router.replace("/login");
  }

  return (
    <main>
      <h1>Fusion Tester</h1>
      <p className="subtitle">
        {user ? (
          <>
            Signed in as <strong>{user.username}</strong> ({user.role})
          </>
        ) : (
          "Loading…"
        )}
      </p>

      <div className="actions">
        <a href="/settings/gitlab">GitLab connection</a>
        <a href="/change-password">Change password</a>
        <button type="button" onClick={() => void handleLogout()}>
          Sign out
        </button>
      </div>

      {error && <p className="error">{error}</p>}

      <section>
        <h2>API health</h2>
        <pre>{health ? JSON.stringify(health, null, 2) : "Loading…"}</pre>
      </section>
    </main>
  );
}
