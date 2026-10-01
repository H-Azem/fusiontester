"use client";

import { useState } from "react";

import { Icon } from "@/components/icons";

/**
 * The login account: its password and the rules that guard it. It lives in Settings
 * now, so the panel has one place for everything that is set rather than run.
 */
export function AccountSection() {
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setSuccess(null);

    if (newPassword !== confirmPassword) {
      setError("The new passwords do not match.");
      return;
    }

    setSubmitting(true);

    try {
      const response = await fetch("/api/auth/change-password", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ currentPassword, newPassword }),
      });

      const data = (await response.json()) as { message?: string; revokedSessions?: number };

      if (!response.ok) {
        setError(data.message ?? "Could not change the password.");
        return;
      }

      setCurrentPassword("");
      setNewPassword("");
      setConfirmPassword("");

      const others = data.revokedSessions ?? 0;
      setSuccess(
        others > 0
          ? `Password changed. Signed out ${others} other session${others === 1 ? "" : "s"}.`
          : "Password changed.",
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <section className="panel">
        <div className="panel-head">
          <div>
            <h2>Login password</h2>
            <p className="panel-sub">
              The password you sign in with. Changing it signs your other sessions out.
            </p>
          </div>
        </div>

        <form className="stack" onSubmit={handleSubmit}>
          <div className="split">
            <label className="field">
              <span className="field-label">Current password</span>
              <input
                type="password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
                required
              />
            </label>

            <label className="field">
              <span className="field-label">New password</span>
              <input
                type="password"
                autoComplete="new-password"
                value={newPassword}
                onChange={(event) => setNewPassword(event.target.value)}
                required
              />
            </label>
          </div>

          <label className="field">
            <span className="field-label">Confirm new password</span>
            <input
              type="password"
              autoComplete="new-password"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              required
            />
          </label>

          {error && (
            <p className="snackbar error" role="alert">
              <Icon name="warning" size={18} />
              {error}
            </p>
          )}

          {success && (
            <p className="snackbar success" role="status">
              <Icon name="check" size={18} />
              {success}
            </p>
          )}

          <div className="row">
            <button type="submit" className="md-button filled" disabled={submitting}>
              {submitting ? <span className="spinner" /> : <Icon name="check" size={18} />}
              {submitting ? "Saving…" : "Change login password"}
            </button>
          </div>
        </form>
      </section>

      <section className="panel">
        <div className="panel-head">
          <h2>Session rules</h2>
        </div>
        <dl className="meta-grid">
          <div>
            <dt>Failed attempts</dt>
            <dd>Three from one address and it is blocked for six hours.</dd>
          </div>
          <div>
            <dt>Captcha</dt>
            <dd>Wrong codes are not counted, so a misread digit cannot lock you out.</dd>
          </div>
          <div>
            <dt>Other sessions</dt>
            <dd>Changing the password revokes every session except this one.</dd>
          </div>
        </dl>
      </section>
    </>
  );
}
