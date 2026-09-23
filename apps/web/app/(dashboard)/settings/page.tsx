"use client";

import { useCallback, useEffect, useState } from "react";

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

export default function GitlabSettingsPage() {
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

  const overrides = {
    baseUrl: baseUrl || undefined,
    token: token || undefined,
    caCertificate: caCertificate.startsWith("••••") ? undefined : caCertificate,
  };

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
      setNotice("Saved. The token is encrypted at rest and will not be shown again.");
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
        body: JSON.stringify({ ...overrides, testRepo: testRepo || undefined }),
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
        <h2>GitLab connection</h2>
        <span className="muted">Source of the repositories under test</span>
      </div>

      {config?.configured && (
        <p className="muted">
          Stored token {config.tokenHint}
          {config.hasCaCertificate ? " · custom CA certificate stored" : ""}
          {config.lastVerifiedAt
            ? ` · last checked ${new Date(config.lastVerifiedAt).toLocaleString()} (${config.lastVerifyOk ? "ok" : "failed"})`
            : ""}
        </p>
      )}

      <form onSubmit={handleSave}>
        <label htmlFor="baseUrl">GitLab base URL</label>
        <input
          id="baseUrl"
          placeholder="https://gitlab.example.com"
          value={baseUrl}
          onChange={(event) => setBaseUrl(event.target.value)}
          required
        />

        <label htmlFor="token">
          Personal access token{" "}
          <span className="muted">
            needs {REQUIRED_SCOPES.join(" + ")}
            {config?.configured ? " · leave blank to keep the stored token" : ""}
          </span>
        </label>
        <input
          id="token"
          type="password"
          autoComplete="off"
          placeholder={config?.configured ? "unchanged" : "glpat-…"}
          value={token}
          onChange={(event) => setToken(event.target.value)}
        />

        <label htmlFor="testRepo">
          Repository to verify cloning <span className="muted">group/project, optional</span>
        </label>
        <input
          id="testRepo"
          placeholder="my-group/my-project"
          value={testRepo}
          onChange={(event) => setTestRepo(event.target.value)}
        />

        <label htmlFor="caCertificate">
          Custom CA certificate <span className="muted">PEM, optional</span>
        </label>
        <textarea
          id="caCertificate"
          placeholder="-----BEGIN CERTIFICATE-----"
          value={caCertificate}
          onChange={(event) => setCaCertificate(event.target.value)}
        />

        {error && <p className="error">{error}</p>}
        {notice && <p className="success">{notice}</p>}

        <div className="actions">
          <button type="submit" disabled={busy !== null}>
            {busy === "save" ? "Saving…" : "Save"}
          </button>
          <button type="button" onClick={() => void handleTest()} disabled={busy !== null}>
            {busy === "test" ? "Testing…" : "Test connection"}
          </button>
        </div>
      </form>

      {result && (
        <section>
          <h2>Test result</h2>

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

          <pre>
            {[
              `base url      ${result.baseUrl}`,
              `identity      ${result.identity ? `${result.identity.username} (${result.identity.name})` : "unknown"}`,
              `token name    ${result.tokenName ?? "unknown"}`,
              result.scopesReadable
                ? `scopes        ${(result.scopes ?? []).join(", ") || "none"}`
                : "scopes        could not be read from this token",
              result.expiresAt
                ? `expires       ${result.expiresAt}${result.daysUntilExpiry !== null ? ` (${result.daysUntilExpiry} days)` : ""}`
                : "expires       never",
              result.clone.attempted
                ? `clone check   ${result.clone.ok ? "ok" : "failed"} — ${result.clone.repo}`
                : "clone check   not run (no repository given)",
              `overall       ${result.ok ? "ready" : "not ready"}`,
            ].join("\n")}
          </pre>

          {result.clone.attempted && result.clone.message && (
            <pre className={result.clone.ok ? "" : "error"}>{result.clone.message}</pre>
          )}

          {result.error && <pre className="error">{result.error}</pre>}

          {result.warnings.map((warning) => (
            <pre key={warning} className="error">
              {warning}
            </pre>
          ))}
        </section>
      )}
    </section>
  );
}
