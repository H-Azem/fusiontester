"use client";

import { useCallback, useEffect, useState } from "react";

import { Icon } from "@/components/icons";
import { StatusChip, relativeTime } from "@/components/status";

type TelegramConfig = {
  configured: boolean;
  chatId?: string;
  botTokenHint?: string;
  notifyOnPass?: boolean;
  messageThreadId?: string | null;
  enabled?: boolean;
  lastVerifiedAt?: string | null;
  lastVerifyOk?: boolean | null;
  lastVerifyError?: string | null;
};

type TestResult = { ok: boolean; bot: string | null; message: string };

/**
 * Every finished run is posted to a Telegram channel: the bot token and the channel
 * live here, behind the settings password like the other credentials.
 */
export function TelegramSettings() {
  const [config, setConfig] = useState<TelegramConfig | null>(null);
  const [botToken, setBotToken] = useState("");
  const [chatId, setChatId] = useState("");
  const [notifyOnPass, setNotifyOnPass] = useState(true);
  const [topicId, setTopicId] = useState("");
  const [enabled, setEnabled] = useState(true);
  const [result, setResult] = useState<TestResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"save" | "test" | null>(null);

  const load = useCallback(async () => {
    const response = await fetch("/api/settings/telegram", { cache: "no-store" });

    if (response.status === 401) {
      window.location.href = "/login";
      return;
    }

    const data = (await response.json()) as TelegramConfig;
    setConfig(data);
    setChatId(data.chatId ?? "");
    setNotifyOnPass(data.notifyOnPass ?? true);
    setTopicId(data.messageThreadId ?? "");
    setEnabled(data.enabled ?? true);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function save(event: React.FormEvent) {
    event.preventDefault();
    setBusy("save");
    setError(null);
    setNotice(null);

    try {
      const response = await fetch("/api/settings/telegram", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ botToken: botToken || undefined, chatId, notifyOnPass, messageThreadId: topicId, enabled }),
      });

      const data = (await response.json()) as { message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not save the Telegram settings.");
        return;
      }

      setBotToken("");
      setNotice("Saved. The bot token is encrypted at rest.");
      await load();
    } finally {
      setBusy(null);
    }
  }

  async function test() {
    setBusy("test");
    setError(null);
    setNotice(null);
    setResult(null);

    try {
      const response = await fetch("/api/settings/telegram/test", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ botToken: botToken || undefined, chatId: chatId || undefined }),
      });

      const data = (await response.json()) as TestResult & { message?: string };

      if (!response.ok) {
        setError(data.message ?? "Could not reach Telegram.");
        return;
      }

      setResult(data);
      await load();
    } finally {
      setBusy(null);
    }
  }

  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h2>Telegram reports</h2>
          <p className="panel-sub">
            The channel below receives one message per finished run: what ran, how it ended and
            where to look. A run that passes can be left out.
          </p>
        </div>
        <StatusChip
          status={config?.configured ? (config.lastVerifyOk === false ? "failed" : "passed") : "queued"}
          label={config?.configured ? (config.lastVerifyOk === false ? "check failed" : "connected") : "not set"}
        />
      </div>

      {config?.configured && (
        <p className="md-body-sm muted">
          Bot token {config.botTokenHint} · channel {config.chatId}
          {config.lastVerifiedAt ? ` · last sent ${relativeTime(config.lastVerifiedAt)}` : ""}
        </p>
      )}

      <form className="stack" onSubmit={save}>
        <label className="switch">
          <input type="checkbox" checked={enabled} onChange={() => setEnabled((current) => !current)} />
          <span>
            <span className="md-title-sm">Send reports</span>
            <br />
            <span className="md-body-sm muted">Off sends nothing at all</span>
          </span>
        </label>

        <div className="split">
          <label className="field">
            <span className="field-label">Bot token</span>
            <input
              type="password"
              autoComplete="off"
              placeholder={config?.configured ? "unchanged" : "123456:ABC-DEF…"}
              value={botToken}
              onChange={(event) => setBotToken(event.target.value)}
            />
            <span className="hint">
              From @BotFather. Leave blank to keep the stored token.
            </span>
          </label>

          <div className="stack">
            <label className="field">
              <span className="field-label">Channel</span>
              <input
                placeholder="@my_reports or -1001234567890"
                value={chatId}
                onChange={(event) => setChatId(event.target.value)}
                required
              />
              <span className="hint">
                The channel the bot was added to, as @name or its numeric id.
              </span>
            </label>

            <label className={enabled ? "field" : "field muted"}>
              <span className="field-label">Topic id</span>
              <input
                placeholder="leave blank for the group itself"
                value={topicId}
                onChange={(event) => setTopicId(event.target.value)}
                disabled={!enabled}
              />
              <span className="hint">
                For a forum group, the number at the end of the topic's link — reports go there
                instead of the group.
              </span>
            </label>

            <label className="switch">
              <input
                type="checkbox"
                disabled={!enabled}
                checked={notifyOnPass}
                onChange={() => setNotifyOnPass((current) => !current)}
              />
              <span>
                <span className="md-title-sm">Report passing runs too</span>
                <br />
                <span className="md-body-sm muted">Failures are always reported</span>
              </span>
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

        {result && (
          <p className={result.ok ? "snackbar success" : "snackbar error"} role="status">
            <Icon name={result.ok ? "check" : "warning"} size={18} />
            {result.ok ? `Connected${result.bot ? ` as @${result.bot}` : ""}. ${result.message}` : result.message}
          </p>
        )}

        <div className="row">
          <button type="submit" className="md-button filled" disabled={busy !== null}>
            {busy === "save" ? <span className="spinner" /> : <Icon name="check" size={18} />}
            {busy === "save" ? "Saving…" : "Save Telegram settings"}
          </button>
          <button type="button" className="md-button tonal" onClick={() => void test()} disabled={busy !== null || !enabled}>
            {busy === "test" ? <span className="spinner" /> : <Icon name="live" size={18} />}
            {busy === "test" ? "Sending…" : "Send a test message"}
          </button>
        </div>
      </form>
    </section>
  );
}
