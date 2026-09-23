"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

type Captcha = { id: string; svg: string };

function svgToDataUri(svg: string): string {
  return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

export default function LoginPage() {
  const router = useRouter();
  const [captcha, setCaptcha] = useState<Captcha | null>(null);
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [captchaText, setCaptchaText] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [blockedUntil, setBlockedUntil] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const loadCaptcha = useCallback(async () => {
    const response = await fetch("/api/auth/captcha", { cache: "no-store" });
    const data = (await response.json()) as Captcha;
    setCaptcha(data);
    setCaptchaText("");
  }, []);

  useEffect(() => {
    void loadCaptcha();
  }, [loadCaptcha]);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    if (!captcha) return;

    setSubmitting(true);
    setError(null);

    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          username,
          password,
          captchaId: captcha.id,
          captchaText,
        }),
      });

      const data = (await response.json()) as {
        error?: string;
        message?: string;
        blockedUntil?: string;
      };

      if (response.ok) {
        router.replace("/");
        router.refresh();
        return;
      }

      if (response.status === 429) {
        setBlockedUntil(data.blockedUntil ?? null);
        setError(data.message ?? "Too many failed attempts.");
      } else {
        setError(data.message ?? "Login failed.");
      }

      // A challenge is single-use, so always fetch a fresh one.
      await loadCaptcha();
    } finally {
      setSubmitting(false);
    }
  }

  if (blockedUntil) {
    return (
      <main>
        <h1>Fusion Tester</h1>
        <section>
          <h2>Access blocked</h2>
          <p className="error">
            Too many failed attempts from your IP address. Try again after{" "}
            {new Date(blockedUntil).toLocaleString()}.
          </p>
        </section>
      </main>
    );
  }

  return (
    <main>
      <h1>Fusion Tester</h1>
      <p className="subtitle">Sign in to continue</p>

      <form onSubmit={handleSubmit}>
        <label htmlFor="username">Username</label>
        <input
          id="username"
          name="username"
          autoComplete="username"
          value={username}
          onChange={(event) => setUsername(event.target.value)}
          required
        />

        <label htmlFor="password">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          required
        />

        <label htmlFor="captcha">Verification code</label>
        <div className="captcha-row">
          {captcha ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={svgToDataUri(captcha.svg)}
              alt="Verification code"
              width={180}
              height={60}
            />
          ) : (
            <div className="captcha-placeholder">Loading…</div>
          )}
          <button type="button" onClick={() => void loadCaptcha()}>
            New code
          </button>
        </div>
        <input
          id="captcha"
          name="captcha"
          autoComplete="off"
          value={captchaText}
          onChange={(event) => setCaptchaText(event.target.value)}
          required
        />

        {error && <p className="error">{error}</p>}

        <button type="submit" disabled={submitting || !captcha}>
          {submitting ? "Signing in…" : "Sign in"}
        </button>
      </form>
    </main>
  );
}
