import { decryptSecret } from "../crypto/secret-box.js";
import { db } from "../db/index.js";
import { aiConnections } from "../db/schema.js";

export const DEFAULT_OPENAI_BASE_URL = "https://api.openai.com/v1";
export const DEFAULT_JEV_BASE_URL = "https://api.typesafe.ai";

export type AiConfig = {
  openaiBaseUrl: string;
  openaiModel: string;
  openaiToken: string;
  jevBaseUrl: string;
  jevToken: string;
  maxSteps: number;
};

/** Strips trailing slashes so callers can append a known path. */
export function normalizeBaseUrl(value: string): string {
  return value.trim().replace(/\/+$/, "");
}

/**
 * Reads the stored AI credentials. Returns null when the connection has never
 * been saved, so callers can report that instead of failing on a fetch.
 */
export async function getStoredAiConfig(): Promise<AiConfig | null> {
  const rows = await db.select().from(aiConnections).limit(1);
  const row = rows[0];
  if (!row) return null;

  return {
    openaiBaseUrl: row.openaiBaseUrl,
    openaiModel: row.openaiModel,
    openaiToken: decryptSecret(row.openaiTokenCiphertext),
    jevBaseUrl: row.jevBaseUrl,
    jevToken: decryptSecret(row.jevTokenCiphertext),
    maxSteps: row.maxSteps,
  };
}

const REQUEST_TIMEOUT_MS = 120_000;

async function postJson(
  url: string,
  token: string,
  body: unknown,
  timeoutMs = REQUEST_TIMEOUT_MS,
): Promise<{ ok: boolean; status: number; text: string }> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      method: "POST",
      headers: {
        authorization: `Bearer ${token}`,
        "content-type": "application/json",
      },
      body: JSON.stringify(body),
      signal: controller.signal,
    });

    return { ok: response.ok, status: response.status, text: await response.text() };
  } finally {
    clearTimeout(timer);
  }
}

export type ChatMessage = {
  role: "system" | "user" | "assistant";
  /** Plain text, or text plus images when the model is asked to look at a frame. */
  content: string | Array<
    | { type: "text"; text: string }
    | { type: "image_url"; image_url: { url: string } }
  >;
};

export type ChatResult = {
  text: string;
  /** The reply parsed as JSON when it looks like JSON, else null. */
  json: unknown;
  model: string;
  usage: { input: number; output: number };
};

/**
 * Calls an OpenAI-compatible chat completions endpoint. Requests JSON output
 * because every caller here wants a decision, not prose.
 */
export async function chat(options: {
  config: AiConfig;
  messages: ChatMessage[];
  maxTokens?: number;
  json?: boolean;
  timeoutMs?: number;
}): Promise<ChatResult> {
  const { config, messages, maxTokens = 1200, json = true, timeoutMs } = options;

  const body: Record<string, unknown> = {
    model: config.openaiModel,
    messages,
    max_tokens: maxTokens,
  };
  if (json) body.response_format = { type: "json_object" };

  const result = await postJson(
    `${config.openaiBaseUrl}/chat/completions`,
    config.openaiToken,
    body,
    timeoutMs,
  );

  if (!result.ok) {
    throw new Error(
      `Chat request failed (${result.status}): ${result.text.slice(0, 400)}`,
    );
  }

  let parsed: {
    choices?: Array<{ message?: { content?: string } }>;
    model?: string;
    usage?: { prompt_tokens?: number; completion_tokens?: number };
  };

  try {
    parsed = JSON.parse(result.text);
  } catch {
    throw new Error(`Chat endpoint returned non-JSON: ${result.text.slice(0, 200)}`);
  }

  const text = parsed.choices?.[0]?.message?.content ?? "";
  let jsonValue: unknown = null;
  try {
    jsonValue = JSON.parse(text);
  } catch {
    jsonValue = null;
  }

  return {
    text,
    json: jsonValue,
    model: parsed.model ?? config.openaiModel,
    usage: {
      input: parsed.usage?.prompt_tokens ?? 0,
      output: parsed.usage?.completion_tokens ?? 0,
    },
  };
}

/* ---------------------------------------------------------------- jev ---- */

export type JevQuestion =
  | { type: "choice"; instructions: unknown; criteria: Record<string, unknown> }
  | { type: "score"; instructions: unknown; criteria: unknown[] }
  | { type: "noul"; instructions: unknown; criteria?: unknown };

export type JevAnswer = {
  type: string;
  choice?: string;
  score?: number;
  noul?: number;
  confidence?: number;
  probabilities?: Record<string, number>;
};

/**
 * Sends many independent questions about one state to jev in a single request.
 * They are evaluated in parallel, so asking extra questions is nearly free.
 */
export async function askJev(options: {
  config: AiConfig;
  state: unknown;
  questions: Record<string, JevQuestion>;
}): Promise<Record<string, JevAnswer>> {
  const { config, state, questions } = options;

  const result = await postJson(
    `${config.jevBaseUrl}/v1/systemone`,
    config.jevToken,
    { state, model: "jev-latest", questions },
    60_000,
  );

  if (!result.ok) {
    throw new Error(`jev request failed (${result.status}): ${result.text.slice(0, 400)}`);
  }

  const parsed = JSON.parse(result.text) as { answers?: Record<string, JevAnswer> };
  return parsed.answers ?? {};
}

/* ------------------------------------------------------- verification ---- */

export type AiVerifyResult = {
  openai: { ok: boolean; detail: string; model: string | null };
  jev: { ok: boolean; detail: string; noul: number | null };
  ok: boolean;
};

/**
 * Checks both halves of the configuration with the smallest possible calls, so
 * the settings form can prove the credentials work before a run depends on them.
 */
export async function verifyAiConfig(config: AiConfig): Promise<AiVerifyResult> {
  const result: AiVerifyResult = {
    openai: { ok: false, detail: "", model: null },
    jev: { ok: false, detail: "", noul: null },
    ok: false,
  };

  try {
    const reply = await chat({
      config,
      messages: [
        { role: "system", content: 'Reply with the JSON {"ok":true}.' },
        { role: "user", content: "Ping." },
      ],
      maxTokens: 20,
      timeoutMs: 45_000,
    });
    result.openai = {
      ok: true,
      detail: `Replied with ${reply.text.trim().slice(0, 60) || "(empty)"}.`,
      model: reply.model,
    };
  } catch (error) {
    result.openai = {
      ok: false,
      detail: error instanceof Error ? error.message : String(error),
      model: null,
    };
  }

  try {
    const answers = await askJev({
      config,
      state: "The sky is blue.",
      questions: {
        ping: { type: "noul", instructions: "Is the sky blue?" },
      },
    });
    const noul = answers.ping?.noul ?? null;
    result.jev = {
      ok: noul !== null && noul > 0.5,
      detail: noul === null ? "No answer returned." : `Answered "is the sky blue" with ${noul}.`,
      noul,
    };
  } catch (error) {
    result.jev = {
      ok: false,
      detail: error instanceof Error ? error.message : String(error),
      noul: null,
    };
  }

  result.ok = result.openai.ok && result.jev.ok;
  return result;
}
