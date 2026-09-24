"use client";

import { useCallback, useEffect, useState } from "react";

type AiConfig = {
  configured: boolean;
  openaiBaseUrl?: string;
  openaiModel?: string;
  openaiTokenHint?: string;
  jevBaseUrl?: string;
  jevTokenHint?: string;
  maxSteps?: number;
  lastVerifiedAt?: string | null;
  lastVerifyOk?: boolean | null;
  lastVerifyError?: string | null;
};

type TestResult = {
  ok: boolean;
  openai: { ok: boolean; detail: string; model: string | null };
  jev: { ok: boolean; detail: string; noul: number | null };
};

/**
 * Credentials for the AI test lane: an OpenAI-compatible endpoint that chooses
 * actions from the semantics tree, and a jev token that verifies each step with
 * typed answers.
 */
export function AiSettings() {
  const [config, setConfig] = useState<AiConfig | null>(null);
  const [openaiBaseUrl, setOpenaiBaseUrl] = useState("");
  const [openaiModel, setOpenaiModel] = useState("");
  const [openaiToken, setOpenaiToken] = useState("");
  const [jevBaseUrl, setJevBaseUrl] = useState("");
  const [jevToken, setJevToken] = useState("");
  const [maxSteps, setMaxSteps] = useState("25");
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"save" | "test" | null>(null);

  const load = useCallback(async () => {
    const response = await fetch("/api/settings/ai", { cache: "no-store" });
    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }

    const data = (await response.json()) as AiConfig;
    setConfig(data);
    setOpenaiBaseUrl(data.openaiBaseUrl ?? "");
    setOpenaiModel(data.openaiModel ?? "");
    setJevBaseUrl(data.jevBaseUrl ?? "");
    setMaxSteps(String(data.maxSteps ?? 25));
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
      const response = await fetch("/api/settings/ai", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          openaiBaseUrl,
          openaiModel,
          openaiToken: openaiToken || undefined,
          jevBaseUrl,
          jevToken: jevToken || undefined,
          maxSteps: Number(maxSteps),
        }),
      });

      const data = (await response.json()) as { message?: string };
      if (!response.ok) {
        setError(data.message ?? "Could not save the AI connection.");
        return;
      }

      setOpenaiToken("");
      setJevToken("");
      setNotice("Saved. Both tokens are encrypted at rest and will not be shown again.");
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
      const response = await fetch("/api/settings/ai/test", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          openaiBaseUrl: openaiBaseUrl || undefined,
          openaiModel: openaiModel || undefined,
          openaiToken: openaiToken || undefined,
          jevBaseUrl: jevBaseUrl || undefined,
          jevToken: jevToken || undefined,
        }),
      });

      const data = (await response.json()) as TestResult & { message?: string };
      if (!response.ok) {
        setError(data.message ?? "Could not reach the model or jev.");
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
        <h2>AI test</h2>
        <span className="muted">
          Model chooses the actions, jev verifies each step
        </span>
      </div>

      {config?.configured && (
        <p className="muted">
          Model key {config.openaiTokenHint} · jev token {config.jevTokenHint}
          {config.lastVerifiedAt
            ? ` · last checked ${new Date(config.lastVerifiedAt).toLocaleString()} (${
                config.lastVerifyOk ? "ok" : "failed"
              })`
            : ""}
        </p>
      )}

      <p className="muted">
        Actions are chosen from the app&apos;s accessibility tree, so no screenshots are
        sent while a test runs. A screenshot is captured only when a step fails, and is
        analysed then.
      </p>

      <form onSubmit={handleSave}>
        <label htmlFor="openaiBaseUrl">Model base URL</label>
        <input
          id="openaiBaseUrl"
          placeholder="https://api.openai.com/v1"
          value={openaiBaseUrl}
          onChange={(event) => setOpenaiBaseUrl(event.target.value)}
          required
        />

        <label htmlFor="openaiModel">Model</label>
        <input
          id="openaiModel"
          placeholder="gpt-4o-mini"
          value={openaiModel}
          onChange={(event) => setOpenaiModel(event.target.value)}
          spellCheck={false}
          required
        />

        <label htmlFor="openaiToken">
          API key{" "}
          <span className="muted">
            any OpenAI-compatible provider
            {config?.configured ? " · leave blank to keep the stored key" : ""}
          </span>
        </label>
        <input
          id="openaiToken"
          type="password"
          autoComplete="off"
          placeholder={config?.configured ? "unchanged" : "sk-…"}
          value={openaiToken}
          onChange={(event) => setOpenaiToken(event.target.value)}
        />

        <label htmlFor="jevBaseUrl">jev base URL</label>
        <input
          id="jevBaseUrl"
          placeholder="https://api.typesafe.ai"
          value={jevBaseUrl}
          onChange={(event) => setJevBaseUrl(event.target.value)}
          required
        />

        <label htmlFor="jevToken">
          jev token{" "}
          <span className="muted">
            TypeSafe API key, used for the typed checks
            {config?.configured ? " · leave blank to keep the stored token" : ""}
          </span>
        </label>
        <input
          id="jevToken"
          type="password"
          autoComplete="off"
          placeholder={config?.configured ? "unchanged" : "ts-…"}
          value={jevToken}
          onChange={(event) => setJevToken(event.target.value)}
        />

        <label htmlFor="maxSteps">
          Maximum steps <span className="muted">per AI test</span>
        </label>
        <input
          id="maxSteps"
          type="number"
          min={3}
          max={80}
          value={maxSteps}
          onChange={(event) => setMaxSteps(event.target.value)}
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
            <li className={result.openai.ok ? "scope" : "scope missing"}>
              model {result.openai.ok ? "ready" : "failed"}
            </li>
            <li className={result.jev.ok ? "scope" : "scope missing"}>
              jev {result.jev.ok ? "ready" : "failed"}
            </li>
          </ul>

          <pre>
            {[
              `model base    ${openaiBaseUrl || config?.openaiBaseUrl || "—"}`,
              `model         ${result.openai.model ?? (openaiModel || "—")}`,
              `model check   ${result.openai.ok ? "ok" : "failed"} — ${result.openai.detail}`,
              `jev base      ${jevBaseUrl || config?.jevBaseUrl || "—"}`,
              `jev check     ${result.jev.ok ? "ok" : "failed"} — ${result.jev.detail}`,
              `overall       ${result.ok ? "ready" : "not ready"}`,
            ].join("\n")}
          </pre>
        </section>
      )}
    </section>
  );
}
