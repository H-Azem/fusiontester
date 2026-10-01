"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import { Bird } from "@/components/bird";
import { Icon } from "@/components/icons";

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
        body: JSON.stringify({ username, password, captchaId: captcha.id, captchaText }),
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
      <main className="login-shell">
        <div className="login-card">
          <div className="login-brand">
            <Bird state="locked" size={104} />
            <h1>Access blocked</h1>
            <p>
              Too many failed attempts came from this address. Try again after{" "}
              {new Date(blockedUntil).toLocaleString()}.
            </p>
          </div>
          <button type="button" className="md-button tonal" onClick={() => setBlockedUntil(null)}>
            Back to sign in
          </button>
        </div>
      </main>
    );
  }

  return (
    <main className="login-shell">
      <div className="login-card">
        <div className="login-brand">
          <Bird state="success" size={96} float />
          <h1>Fusion Tester</h1>
          <p>Sign in to run Maestro and AI tests against your Flutter apps</p>
        </div>

        <form className="stack" onSubmit={handleSubmit}>
          <label className="field">
            <span className="field-label">Username</span>
            <input
              name="username"
              autoComplete="username"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              required
            />
          </label>

          <label className="field">
            <span className="field-label">Password</span>
            <input
              name="password"
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
          </label>

          <div className="field">
            <span className="field-label">Verification code</span>
            <div className="captcha-row">
              {captcha ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={svgToDataUri(captcha.svg)} alt="Verification code" width={180} height={60} />
              ) : (
                <div className="captcha-placeholder">Loading code…</div>
              )}
              <button
                type="button"
                className="md-button text small"
                onClick={() => void loadCaptcha()}
              >
                <Icon name="refresh" size={16} />
                New code
              </button>
            </div>
            <input
              name="captcha"
              autoComplete="off"
              value={captchaText}
              onChange={(event) => setCaptchaText(event.target.value)}
              aria-label="Verification code"
              required
            />
          </div>

          {error && (
            <p className="snackbar error" role="alert">
              <Icon name="warning" size={18} />
              {error}
            </p>
          )}

          <button type="submit" className="md-button filled" disabled={submitting || !captcha}>
            {submitting ? <span className="spinner" /> : <Icon name="logout" size={18} />}
            {submitting ? "Signing in…" : "Sign in"}
          </button>
        </form>
      </div>
    </main>
  );
}
