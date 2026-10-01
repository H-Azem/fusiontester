"use client";

import { useCallback, useEffect, useState } from "react";

import { Bird } from "./bird";
import { Icon } from "./icons";

/**
 * Settings hold credentials that reach the machine under test, so the section opens
 * with a second password. The server decides: this only asks it whether the current
 * session has already unlocked, and shows the lock until it has.
 */
export function SettingsGate({ children }: { children: React.ReactNode }) {
  const [unlocked, setUnlocked] = useState<boolean | null>(null);
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const readStatus = useCallback(async () => {
    try {
      const response = await fetch("/api/auth/settings-status", { cache: "no-store" });
      if (response.status === 401) {
        window.location.href = "/login";
        return;
      }
      const data = (await response.json()) as { unlocked?: boolean };
      setUnlocked(Boolean(data.unlocked));
    } catch {
      setUnlocked(false);
    }
  }, []);

  useEffect(() => {
    void readStatus();
  }, [readStatus]);

  async function unlock(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const response = await fetch("/api/auth/settings-unlock", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ password }),
      });

      const data = (await response.json()) as { message?: string };

      if (response.status === 429) {
        setError(data.message ?? "Too many attempts. Sign in again.");
        return;
      }

      if (!response.ok) {
        setError(data.message ?? "That settings password is not correct.");
        setPassword("");
        return;
      }

      setPassword("");
      await readStatus();
    } finally {
      setBusy(false);
    }
  }

  if (unlocked === null) {
    return (
      <div className="stack" aria-busy="true">
        <span className="skeleton" style={{ height: 180, borderRadius: 24 }} />
      </div>
    );
  }

  if (!unlocked) {
    return (
      <section className="panel">
        <div className="empty-state">
          <Bird state="locked" size={112} float />
          <h3 className="md-title">Settings are locked</h3>
          <p className="md-body">
            These pages hold the GitLab token and the model keys. Enter the settings password to
            open them for this sign-in.
          </p>

          <form className="stack" style={{ width: "min(360px, 100%)" }} onSubmit={unlock}>
            <label className="field">
              <span className="field-label">Settings password</span>
              <input
                type="password"
                autoComplete="off"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoFocus
                required
              />
              <span className="hint">The placeholder until you change it is 12345.</span>
            </label>

            {error && (
              <p className="snackbar error" role="alert">
                <Icon name="warning" size={18} />
                {error}
              </p>
            )}

            <button type="submit" className="md-button filled" disabled={busy || password === ""}>
              {busy ? <span className="spinner" /> : <Icon name="lock" size={18} />}
              {busy ? "Checking…" : "Unlock settings"}
            </button>
          </form>
        </div>
      </section>
    );
  }

  return <>{children}</>;
}

/**
 * Replacing the settings password. It lives inside the unlocked area because it
 * needs the current one anyway.
 */
export function SettingsPasswordCard() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const response = await fetch("/api/auth/settings-password", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ currentPassword, newPassword }),
      });

      const data = (await response.json()) as { message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not change it.");
        if (response.status === 422) setError("The new password needs at least 5 characters.");
        return;
      }

      setCurrentPassword("");
      setNewPassword("");
      setNotice("Settings password changed.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Settings password</h2>
          <p className="panel-sub">Asked for once per sign-in, before this section opens.</p>
        </div>
      </div>

      <form className="stack" onSubmit={submit}>
        <div className="split">
          <label className="field">
            <span className="field-label">Current settings password</span>
            <input
              type="password"
              autoComplete="off"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              required
            />
          </label>

          <label className="field">
            <span className="field-label">New settings password</span>
            <input
              type="password"
              autoComplete="new-password"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              minLength={5}
              required
            />
            <span className="hint">At least 5 characters. It is stored as a hash.</span>
          </label>
        </div>

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
          <button type="submit" className="md-button filled" disabled={busy}>
            {busy ? <span className="spinner" /> : <Icon name="check" size={18} />}
            {busy ? "Saving…" : "Change settings password"}
          </button>
        </div>
      </form>
    </section>
  );
}
