"use client";

import { useCallback, useEffect, useState } from "react";

import { AiSettings } from "@/components/ai-settings";
import { SettingsGate, SettingsPasswordCard } from "@/components/settings-gate";
import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type Config = {
  configured: boolean;
  baseUrl?: string;
  tokenHint?: string;
  hasCaCertificate?: boolean;
  lastVerifiedAt?: string | null;
  lastVerifyOk?: boolean | null;
  lastVerifyError?: string | null;
};

type CloneCheck = {
  attempted: boolean;
  ok: boolean;
  repo: string | null;
  message: string | null;
};

type VerifyResult = {
  ok: boolean;
  baseUrl: string;
  identity: { id: number; username: string; name: string } | null;
  tokenName: string | null;
  scopes: string[] | null;
  scopesReadable: boolean;
  expiresAt: string | null;
  daysUntilExpiry: number | null;
  missingScopes: string[];
  warnings: string[];
  clone: CloneCheck;
  error: string | null;
};

const REQUIRED_SCOPES = ["read_api", "read_repository"];

function GitlabSettings() {
  const [config, setConfig] = useState<Config | null>(null);
  const [baseUrl, setBaseUrl] = useState("");
  const [token, setToken] = useState("");
  const [caCertificate, setCaCertificate] = useState("");
  const [testRepo, setTestRepo] = useState("");
  const [result, setResult] = useState<VerifyResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"save" | "test" | null>(null);

  const load = useCallback(async () => {
    const response = await fetch("/api/settings/gitlab", { cache: "no-store" });
    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }
    const data = (await response.json()) as Config;
    setConfig(data);
    setBaseUrl(data.baseUrl ?? "");
    setCaCertificate(data.hasCaCertificate ? "•••••••• (stored)" : "");
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleSave(event: React.FormEvent) {
    event.preventDefault();
    setBusy("save");
    setError(null);
    setNotice(null);

    try {
      const response = await fetch("/api/settings/gitlab", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          baseUrl,
          token: token || undefined,
          caCertificate: caCertificate.startsWith("••••") ? undefined : caCertificate,
        }),
      });

      const data = (await response.json()) as { message?: string };
      if (!response.ok) {
        setError(data.message ?? "Could not save the connection.");
        return;
      }

      setToken("");
      setNotice("Saved. The token is encrypted at rest and never shown again.");
      await load();
    } finally {
      setBusy(null);
    }
  }

  async function handleTest() {
    setBusy("test");
    setError(null);
    setNotice(null);
    setResult(null);

    try {
      const response = await fetch("/api/settings/gitlab/test", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          baseUrl: baseUrl || undefined,
          token: token || undefined,
          caCertificate: caCertificate.startsWith("••••") ? undefined : caCertificate,
          testRepo: testRepo || undefined,
        }),
      });

      const data = (await response.json()) as VerifyResult & { message?: string };
      if (!response.ok) {
        setError(data.message ?? "Could not reach GitLab.");
        return;
      }

      setResult(data);
    } finally {
      setBusy(null);
    }
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>GitLab connection</h2>
          <p className="panel-sub">Where the repositories under test are cloned from.</p>
        </div>
        <StatusChip
          status={config?.configured ? (config.lastVerifyOk === false ? "failed" : "passed") : "queued"}
          label={config?.configured ? (config.lastVerifyOk === false ? "check failed" : "connected") : "not set"}
        />
      </div>

      {config?.configured && (
        <p className="md-body-sm muted">
          Token {config.tokenHint}
          {config.hasCaCertificate ? " · custom CA certificate stored" : ""}
          {config.lastVerifiedAt ? ` · checked ${relativeTime(config.lastVerifiedAt)}` : ""}
        </p>
      )}

      <form className="stack" onSubmit={handleSave}>
        <label className="field">
          <span className="field-label">GitLab base URL</span>
          <input
            placeholder="https://gitlab.example.com"
            value={baseUrl}
            onChange={(event) => setBaseUrl(event.target.value)}
            required
          />
        </label>

        <label className="field">
          <span className="field-label">Personal access token</span>
          <input
            type="password"
            autoComplete="off"
            placeholder={config?.configured ? "unchanged" : "glpat-…"}
            value={token}
            onChange={(event) => setToken(event.target.value)}
          />
          <span className="hint">
            Needs {REQUIRED_SCOPES.join(" + ")}
            {config?.configured ? " · leave blank to keep the stored token" : ""}. The same
            token authenticates the private packages a build fetches.
          </span>
        </label>

        <label className="field">
          <span className="field-label">Repository to verify cloning</span>
          <input
            placeholder="my-group/my-project"
            value={testRepo}
            onChange={(event) => setTestRepo(event.target.value)}
          />
          <span className="hint">Optional. Runs a real clone as part of the test.</span>
        </label>

        <label className="field">
          <span className="field-label">Custom CA certificate</span>
          <textarea
            placeholder="-----BEGIN CERTIFICATE-----"
            value={caCertificate}
            onChange={(event) => setCaCertificate(event.target.value)}
          />
          <span className="hint">PEM, optional. Only needed for a self-signed GitLab.</span>
        </label>

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
          <button type="submit" className="md-button filled" disabled={busy !== null}>
            {busy === "save" ? <span className="spinner" /> : <Icon name="check" size={18} />}
            {busy === "save" ? "Saving…" : "Save connection"}
          </button>
          <button
            type="button"
            className="md-button tonal"
            onClick={() => void handleTest()}
            disabled={busy !== null}
          >
            {busy === "test" ? <span className="spinner" /> : <Icon name="live" size={18} />}
            {busy === "test" ? "Testing…" : "Test connection"}
          </button>
        </div>
      </form>

      {result && (
        <div className="card filled stack">
          <div className="row">
            <StatusChip status={result.ok ? "passed" : "failed"} label={result.ok ? "ready" : "not ready"} />
            {result.expiresAt && result.daysUntilExpiry !== null && (
              <span className="status queued">
                <Icon name="clock" size={14} />
                token expires in {result.daysUntilExpiry} days
              </span>
            )}
          </div>

          <ul className="scopes">
            {(result.scopes ?? []).map((scope) => (
              <li
                key={scope}
                className={REQUIRED_SCOPES.includes(scope) ? "scope" : "scope muted"}
              >
                {scope}
              </li>
            ))}
            {result.missingScopes.map((scope) => (
              <li key={`missing-${scope}`} className="scope missing">
                {scope} missing
              </li>
            ))}
          </ul>

          <dl className="meta-grid">
            <div>
              <dt>Identity</dt>
              <dd>
                {result.identity ? `${result.identity.username} (${result.identity.name})` : "unknown"}
              </dd>
            </div>
            <div>
              <dt>Token name</dt>
              <dd>{result.tokenName ?? "unknown"}</dd>
            </div>
            <div>
              <dt>Clone check</dt>
              <dd>
                {result.clone.attempted
                  ? `${result.clone.ok ? "cloned" : "failed"} — ${result.clone.repo}`
                  : "not run"}
              </dd>
            </div>
          </dl>

          {result.clone.attempted && result.clone.message && (
            <pre className={result.clone.ok ? "output" : "output error"}>{result.clone.message}</pre>
          )}

          {result.error && <pre className="output error">{result.error}</pre>}

          {result.warnings.map((warning) => (
            <pre key={warning} className="output error">
              {warning}
            </pre>
          ))}
        </div>
      )}
    </section>
  );
}

export default function SettingsPage() {
  return (
    <SettingsGate>
      <div className="stack">
        <GitlabSettings />
        <AiSettings />
        <SettingsPasswordCard />
      </div>
    </SettingsGate>
  );
}
