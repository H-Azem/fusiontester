"use client";

import { useCallback, useEffect, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

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
 * Credentials for the AI lane: an OpenAI-compatible endpoint chooses actions from
 * the accessibility tree, and a jev token verifies each step with typed answers.
 * No screenshot leaves the machine while a test runs.
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
      setNotice("Saved. Both tokens are encrypted at rest.");
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
        <div>
          <h2>AI test lane</h2>
          <p className="panel-sub">
            The model picks the next action, jev checks each step. Screenshots are only
            captured when a step fails, and analysed then.
          </p>
        </div>
        <StatusChip
          status={config?.configured ? (config.lastVerifyOk === false ? "failed" : "passed") : "queued"}
          label={config?.configured ? (config.lastVerifyOk === false ? "check failed" : "configured") : "not set"}
        />
      </div>

      {config?.configured && (
        <p className="md-body-sm muted">
          Model key {config.openaiTokenHint} · jev token {config.jevTokenHint}
          {config.lastVerifiedAt ? ` · checked ${relativeTime(config.lastVerifiedAt)}` : ""}
        </p>
      )}

      <form className="stack" onSubmit={handleSave}>
        <div className="split">
          <div className="stack">
            <h3 className="md-title-sm">Model endpoint</h3>

            <label className="field">
              <span className="field-label">Base URL</span>
              <input
                placeholder="https://api.openai.com/v1"
                value={openaiBaseUrl}
                onChange={(event) => setOpenaiBaseUrl(event.target.value)}
                required
              />
              <span className="hint">Any OpenAI-compatible provider works.</span>
            </label>

            <label className="field">
              <span className="field-label">Model</span>
              <input
                placeholder="gpt-4o-mini"
                value={openaiModel}
                onChange={(event) => setOpenaiModel(event.target.value)}
                spellCheck={false}
                required
              />
            </label>

            <label className="field">
              <span className="field-label">API key</span>
              <input
                type="password"
                autoComplete="off"
                placeholder={config?.configured ? "unchanged" : "sk-…"}
                value={openaiToken}
                onChange={(event) => setOpenaiToken(event.target.value)}
              />
            </label>
          </div>

          <div className="stack">
            <h3 className="md-title-sm">jev verification</h3>

            <label className="field">
              <span className="field-label">Base URL</span>
              <input
                placeholder="https://api.typesafe.ai"
                value={jevBaseUrl}
                onChange={(event) => setJevBaseUrl(event.target.value)}
                required
              />
            </label>

            <label className="field">
              <span className="field-label">jev token</span>
              <input
                type="password"
                autoComplete="off"
                placeholder={config?.configured ? "unchanged" : "ts-…"}
                value={jevToken}
                onChange={(event) => setJevToken(event.target.value)}
              />
              <span className="hint">TypeSafe API key used for the typed checks.</span>
            </label>

            <label className="field">
              <span className="field-label">Maximum steps per test</span>
              <input
                type="number"
                min={3}
                max={80}
                value={maxSteps}
                onChange={(event) => setMaxSteps(event.target.value)}
              />
            </label>
          </div>
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
          <button type="submit" className="md-button filled" disabled={busy !== null}>
            {busy === "save" ? <span className="spinner" /> : <Icon name="check" size={18} />}
            {busy === "save" ? "Saving…" : "Save AI settings"}
          </button>
          <button
            type="button"
            className="md-button tonal"
            onClick={() => void handleTest()}
            disabled={busy !== null}
          >
            {busy === "test" ? <span className="spinner" /> : <Icon name="live" size={18} />}
            {busy === "test" ? "Testing…" : "Test both"}
          </button>
        </div>
      </form>

      {result && (
        <div className="card filled stack">
          <div className="row">
            <StatusChip
              status={result.openai.ok ? "passed" : "failed"}
              label={`model ${result.openai.ok ? "ready" : "failed"}`}
            />
            <StatusChip
              status={result.jev.ok ? "passed" : "failed"}
              label={`jev ${result.jev.ok ? "ready" : "failed"}`}
            />
            <StatusChip status={result.ok ? "passed" : "failed"} label={result.ok ? "ready" : "not ready"} />
          </div>

          <dl className="meta-grid">
            <div>
              <dt>Model</dt>
              <dd>{result.openai.model ?? (openaiModel || "—")}</dd>
            </div>
            <div>
              <dt>Model check</dt>
              <dd>{result.openai.detail}</dd>
            </div>
            <div>
              <dt>jev check</dt>
              <dd>{result.jev.detail}</dd>
            </div>
          </dl>
        </div>
      )}
    </section>
  );
}
