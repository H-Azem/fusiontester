import { readdir, readFile, writeFile } from "node:fs/promises";
import { join, relative } from "node:path";

import puppeteer, { type Page } from "puppeteer-core";

import { askJev, chat, type AiConfig, type JevQuestion } from "../ai/client.js";

/**
 * AI-driven testing.
 *
 * Instead of pixels, the agent reads the app's accessibility tree — the same
 * semantics Flutter publishes for Maestro — and asks a general model to choose
 * the next action from a numbered list of elements. Each action is then checked
 * with jev, which returns typed answers and a calibrated confidence instead of
 * prose, so a low-confidence judgment stops the run rather than guessing.
 *
 * Screenshots are only taken when something fails, and only then are they sent
 * to the model: normal operation never pays for vision.
 */

const STEP_TIMEOUT_MS = 30_000;
const SETTLE_MS = 1_200;
/** How long the pointer is held down for a long press. */
const LONG_PRESS_MS = 1_500;
/** Wrong guesses are expected; this many in a row means the agent is stuck. */
const MAX_EXPECTATION_MISSES = 3;

/** The subset of the DOM this module touches. The API package has no DOM lib. */
type DomNode = {
  nodeType: number;
  textContent: string | null;
};

type DomElement = {
  attributes: ArrayLike<{ name: string; value: string }>;
  childNodes: ArrayLike<DomNode>;
  getBoundingClientRect(): { x: number; y: number; width: number; height: number };
};

type DomDocument = {
  querySelectorAll(selector: string): ArrayLike<DomElement>;
};

export type ObservedElement = {
  /** Stable number the model uses to refer to this element. */
  n: number;
  id: string | null;
  role: string | null;
  label: string | null;
  disabled: boolean;
  /** Centre point, so actions are performed by coordinate like a real tap. */
  x: number;
  y: number;
  w: number;
  h: number;
};

export type Observation = {
  elements: ObservedElement[];
  /** Visible strings, deduped and trimmed — what the eye would read. */
  text: string[];
  /** Every semantics identifier on screen; the app's own vocabulary. */
  identifiers: string[];
  errors: string[];
};

/**
 * Reads the semantics tree into a compact, numbered observation.
 *
 * The callback below is serialised and executed in the page, so it must not
 * declare named helpers or close over outer values: the bundler rewrites named
 * functions to call a `__name` helper that does not exist in the browser, and
 * outer variables are not part of the serialised source. Everything it needs is
 * therefore inlined, including the role list.
 */
export async function observe(page: Page): Promise<Observation> {
  const raw = await page.evaluate(() => {
    const doc = (globalThis as unknown as { document: DomDocument }).document;
    const nodes = doc.querySelectorAll("flt-semantics");

    const actedOn = [
      "button",
      "link",
      "textbox",
      "checkbox",
      "radio",
      "switch",
      "tab",
      "menuitem",
      "combobox",
      "slider",
    ];

    const elements: Array<{
      n: number;
      id: string | null;
      role: string | null;
      label: string | null;
      disabled: boolean;
      x: number;
      y: number;
      w: number;
      h: number;
    }> = [];
    const strings: string[] = [];
    const identifiers: string[] = [];
    const seen: Record<string, true> = {};
    const duplicate: Record<string, true> = {};
    const win = globalThis as unknown as { innerWidth?: number; innerHeight?: number };
    const viewportArea = (win.innerWidth ?? 1280) * (win.innerHeight ?? 800);

    for (let i = 0; i < nodes.length; i += 1) {
      const el = nodes[i];
      if (!el) continue;

      const attrs: Record<string, string> = {};
      for (let a = 0; a < el.attributes.length; a += 1) {
        const attr = el.attributes[a];
        if (attr) attrs[attr.name] = attr.value;
      }

      // Own text only: a node's textContent includes every descendant, which
      // would make each label the whole screen.
      let own = "";
      for (let c = 0; c < el.childNodes.length; c += 1) {
        const child = el.childNodes[c];
        if (child && child.nodeType === 3) own += child.textContent ?? "";
      }
      own = own.trim();

      const identifier = attrs["flt-semantics-identifier"] ?? null;
      if (identifier) identifiers.push(identifier);

      if (own.length > 1 && !seen[own] && strings.length < 60) {
        seen[own] = true;
        strings.push(own.slice(0, 160));
      }

      const tappable = "flt-tappable" in attrs;
      const role = attrs["role"] ?? null;
      const label = attrs["aria-label"] ?? (own !== "" ? own : null);

      // A semantics identifier is the app's own stable hook, so anything
      // carrying one is offered as a target even when Flutter does not mark it
      // tappable — Flutter text fields, for example, are reached by tapping
      // their wrapper, which is exactly what Maestro does.
      const actionable =
        identifier !== null ||
        tappable ||
        attrs["flt-textfield"] !== undefined ||
        (role !== null && actedOn.indexOf(role) !== -1);
      if (!actionable) continue;

      const rect = el.getBoundingClientRect();
      if (rect.width < 2 || rect.height < 2) continue;

      // The anonymous full-window wrapper behind every Flutter screen is noise.
      if (identifier === null && rect.width * rect.height >= viewportArea * 0.9) continue;

      const key = `${role ?? ""}|${label ?? ""}|${Math.round(rect.x)}|${Math.round(rect.y)}`;
      if (duplicate[key]) continue;
      duplicate[key] = true;

      elements.push({
        n: elements.length + 1,
        id: identifier,
        role,
        label,
        disabled: attrs["aria-disabled"] === "true",
        x: Math.round(rect.x + rect.width / 2),
        y: Math.round(rect.y + rect.height / 2),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
      });
    }

    return {
      elements: elements.slice(0, 140),
      text: strings,
      identifiers: identifiers.filter((value, index) => identifiers.indexOf(value) === index),
    };
  });

  return { ...raw, errors: [] };
}

/* ------------------------------------------------------------ actions ---- */

/**
 * Waits until the screen stops changing.
 *
 * A Flutter screen can sit on a splash for many seconds after an action while
 * it waits on the backend, so verifying immediately would report a failure for
 * a transition that is simply still in flight.
 */
export async function waitForSettle(page: Page, timeoutMs = 25_000): Promise<void> {
  const startedAt = Date.now();
  let previous = "";
  let stableFor = 0;

  while (Date.now() - startedAt < timeoutMs) {
    await new Promise((r) => setTimeout(r, 1_000));

    const state = await page.evaluate(() => {
      const doc = (globalThis as unknown as { document: DomDocument }).document;
      const nodes = doc.querySelectorAll("flt-semantics");
      let signature = "";
      let loading = false;

      for (let i = 0; i < nodes.length; i += 1) {
        const el = nodes[i];
        if (!el) continue;
        for (let a = 0; a < el.attributes.length; a += 1) {
          const attr = el.attributes[a];
          if (!attr) continue;
          if (attr.name === "flt-semantics-identifier") {
            signature += `${attr.value},`;
            // A splash is stable for many seconds while the app waits on the
            // backend, so a stable screen is not necessarily a finished one.
            if (/splash|loading|progress/i.test(attr.value)) loading = true;
          }
          if (attr.name === "role" && attr.value === "progressbar") loading = true;
        }
      }

      return { signature, loading };
    });

    if (!state.loading && state.signature !== "" && state.signature === previous) {
      stableFor += 1;
      if (stableFor >= 2) return;
    } else {
      stableFor = 0;
    }
    previous = state.signature;
  }
}

export type AgentAction = {
  thought?: string;
  action: "click" | "longPress" | "type" | "press" | "wait" | "scroll" | "finish";
  element?: number;
  text?: string;
  key?: string;
  pixels?: number;
  expect?: string;
  verdict?: "pass" | "fail";
  summary?: string;
};

/** Exported so a probe can drive a single action without a model attached. */
export async function performAction(
  page: Page,
  action: AgentAction,
  elements: ObservedElement[],
): Promise<string> {
  switch (action.action) {
    case "click": {
      const target = elements.find((el) => el.n === action.element);
      if (!target) throw new Error(`No element numbered ${String(action.element)} on screen.`);
      await page.mouse.click(target.x, target.y);
      await waitForSettle(page);
      return `Clicked [${target.n}] ${target.id ?? target.label ?? target.role ?? "element"}.`;
    }

    case "longPress": {
      // Flutter's dev-only gestures (the test login, the hidden admin entry) are
      // bound to a press and hold, so a plain click can never reach them.
      const target = elements.find((el) => el.n === action.element);
      if (!target) throw new Error(`No element numbered ${String(action.element)} on screen.`);
      await page.mouse.move(target.x, target.y);
      await page.mouse.down();
      // Flutter's own long-press threshold is 500ms, but the semantics layer
      // needs a longer hold before it reliably fires the gesture.
      await new Promise((r) => setTimeout(r, LONG_PRESS_MS));
      await page.mouse.up();
      await waitForSettle(page);
      return `Long-pressed [${target.n}] ${
        target.id ?? target.label ?? target.role ?? "element"
      }.`;
    }

    case "type": {
      // Flutter focuses its field on tap and then reads real key events, so the
      // field is tapped first and the text typed into the page.
      const target = elements.find((el) => el.n === action.element);
      if (target) {
        await page.mouse.click(target.x, target.y);
        await new Promise((r) => setTimeout(r, 400));
      }
      await page.keyboard.type(action.text ?? "", { delay: 20 });
      await waitForSettle(page);
      return `Typed "${action.text ?? ""}"${
        target ? ` into [${target.n}] ${target.id ?? target.label ?? "field"}` : ""
      }.`;
    }

    case "press": {
      const key = (action.key ?? "Enter") as Parameters<Page["keyboard"]["press"]>[0];
      await page.keyboard.press(key);
      await waitForSettle(page);
      return `Pressed ${action.key ?? "Enter"}.`;
    }

    case "scroll": {
      await page.mouse.wheel({ deltaY: action.pixels ?? 600 });
      await new Promise((r) => setTimeout(r, SETTLE_MS));
      return `Scrolled ${String(action.pixels ?? 600)}px.`;
    }

    case "wait": {
      await new Promise((r) => setTimeout(r, Math.min(action.pixels ?? 2000, 10_000)));
      return "Waited.";
    }

    default:
      return "Finished.";
  }
}

/* ------------------------------------------------------------- prompt ---- */

const SYSTEM_PROMPT = `You are a QA engineer driving a web app through its accessibility tree.

You get a numbered list of interactive elements and the visible text. Choose ONE next action
that moves the test forward. Never invent element numbers. Prefer elements whose id matches
the goal; ids ending in "_screen" are screens, not buttons.

Reply with JSON only:
{"thought":"why","action":"click|longPress|type|press|wait|scroll|finish","element":N,"text":"...","key":"Enter","expect":"what must be true afterwards","verdict":"pass|fail","summary":"..."}

Rules:
- "expect" is required for click/longPress/type/press: one sentence describing the state change you predict.
- Tap a text field with "click" before typing into it, then use "type" with "element" and "text".
- "longPress" presses and holds for about a second. Use it wherever the mission's flow says
  longPressOn, and whenever clicking a control visibly does nothing. Flutter apps commonly hide
  the test login and the admin entry behind a long press.
- If a control did nothing after a click, retry it with "longPress" rather than finishing.
- If a previous step did not achieve its "expect", do not repeat the same action — change the
  action or the element, and finish with verdict "fail" only when nothing else can work.
- Finish as soon as the mission is satisfied (verdict "pass") or clearly impossible (verdict "fail").`;

function describeObservation(observation: Observation, step: number): string {
  const elements = observation.elements
    .map(
      (el) =>
        `[${el.n}] id=${el.id ?? "-"} role=${el.role ?? "-"} label=${JSON.stringify(
          el.label ?? "",
        )}${el.disabled ? " DISABLED" : ""} at(${el.x},${el.y}) size=${el.w}x${el.h}`,
    )
    .join("\n");

  return [
    `Step ${step}.`,
    `Semantics identifiers on screen: ${observation.identifiers.join(", ") || "(none)"}`,
    "",
    "Interactive elements:",
    elements || "(none)",
    "",
    `Visible text: ${observation.text.join(" | ") || "(none)"}`,
    observation.errors.length > 0 ? `\nConsole errors:\n${observation.errors.join("\n")}` : "",
  ]
    .filter(Boolean)
    .join("\n");
}

/* ---------------------------------------------------------- the loop ----- */

export type AiStepRecord = {
  step: number;
  action: string;
  detail: string;
  expect: string;
  check: string;
  confidence: number | null;
};

export type AiTestResult = {
  ok: boolean;
  summary: string;
  steps: AiStepRecord[];
  screenshot: Buffer | null;
  diagnosis: string | null;
};

/** Candidates for the "which screen is showing" question, from the app itself. */
function screenCandidates(observation: Observation): Record<string, string> {
  const screens = observation.identifiers.filter((id) => /_screen$/.test(id));
  const options: Record<string, string> = {};
  for (const screen of screens) options[screen] = "This screen is the one on display.";
  options.none_of_them = "None of the listed screens is on display.";
  return options;
}

/**
 * Verifies the state after an action. Three questions, one request: whether the
 * model's expectation held, whether the app is showing an error, and which
 * screen is on display.
 */
async function verify(options: {
  config: AiConfig;
  expect: string;
  observation: Observation;
}): Promise<{ met: number; error: number; screen: string | null; confidence: number | null }> {
  const { config, expect, observation } = options;

  const questions: Record<string, JevQuestion> = {
    expectation_met: {
      type: "noul",
      instructions: {
        question: "Does `after.elements` and `after.text` satisfy `expected`?",
        expected: expect,
        focus: "Judge only whether the predicted change actually happened.",
      },
    },
    blocked_or_error: {
      type: "noul",
      instructions: {
        question:
          "Is the app showing an error, a failure message, or stuck on a spinner that never resolves?",
        after: { text: observation.text, identifiers: observation.identifiers },
      },
    },
    screen: {
      type: "choice",
      instructions: {
        question: "Which screen is currently displayed?",
        focus: "Match the identifier list, not the wording of any single label.",
      },
      criteria: screenCandidates(observation),
    },
  };

  const answers = await askJev({
    config,
    state: {
      expected: expect,
      after: {
        identifiers: observation.identifiers,
        elements: observation.elements.map((el) => ({
          id: el.id,
          role: el.role,
          label: el.label,
          disabled: el.disabled,
        })),
        text: observation.text,
      },
    },
    questions,
  });

  return {
    met: answers.expectation_met?.noul ?? 0,
    error: answers.blocked_or_error?.noul ?? 0,
    screen: answers.screen?.choice ?? null,
    confidence: answers.screen?.confidence ?? null,
  };
}

/** On failure only: look at the frame and explain what broke. */
async function diagnose(options: {
  config: AiConfig;
  mission: string;
  steps: AiStepRecord[];
  observation: Observation;
  screenshot: Buffer;
}): Promise<string> {
  const { config, mission, steps, observation, screenshot } = options;

  const trace = steps
    .map((s) => `${s.step}. ${s.action} — ${s.detail} (expected: ${s.expect}; observed: ${s.check})`)
    .join("\n");

  try {
    const reply = await chat({
      config,
      json: false,
      maxTokens: 700,
      messages: [
        {
          role: "system",
          content:
            "You are a senior QA engineer. A UI test just failed. Explain the most likely cause " +
            "and name the specific element or step responsible. Be concise and concrete.",
        },
        {
          role: "user",
          content: [
            {
              type: "text",
              text: [
                `Mission: ${mission}`,
                "",
                "Steps so far:",
                trace || "(none)",
                "",
                `Elements on screen at failure: ${observation.elements
                  .map((el) => `${el.id ?? el.label ?? el.role}`)
                  .slice(0, 40)
                  .join(", ")}`,
                `Visible text at failure: ${observation.text.slice(0, 40).join(" | ")}`,
                "",
                "The screenshot shows the app at the moment of failure.",
              ].join("\n"),
            },
            {
              type: "image_url",
              image_url: { url: `data:image/png;base64,${screenshot.toString("base64")}` },
            },
          ],
        },
      ],
    });
    return reply.text.trim();
  } catch (error) {
    // A vision-capable model is a bonus, not a requirement.
    return `Automatic analysis was unavailable (${
      error instanceof Error ? error.message : String(error)
    }).`;
  }
}

export async function runAiTest(options: {
  runDir: string;
  appUrl: string;
  executablePath: string;
  config: AiConfig;
  mission: string;
  maxSteps?: number;
}): Promise<AiTestResult> {
  const { runDir, appUrl, executablePath, config, mission } = options;
  const maxSteps = options.maxSteps ?? config.maxSteps;

  const browser = await puppeteer.launch({
    executablePath,
    headless: true,
    args: ["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
  });

  const steps: AiStepRecord[] = [];
  let errors: string[] = [];

  try {
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    page.on("pageerror", (error: unknown) => {
      errors.push(`Page error: ${error instanceof Error ? error.message : String(error)}`);
    });
    page.on("console", (message: { type: () => string; text: () => string }) => {
      if (message.type() === "error") errors.push(message.text().trim());
    });

    await page.goto(appUrl, { waitUntil: "domcontentloaded", timeout: 60_000 });
    await new Promise((r) => setTimeout(r, 4_000));

    const history: string[] = [];
    let misses = 0;

    for (let step = 1; step <= maxSteps; step += 1) {
      const observed = await observe(page);
      observed.errors = [...new Set(errors)].slice(-10);

      const decision = await chat({
        config,
        maxTokens: 700,
        messages: [
          { role: "system", content: SYSTEM_PROMPT },
          {
            role: "user",
            content: [
              `Mission: ${mission}`,
              "",
              history.length > 0 ? `What has happened so far:\n${history.join("\n")}` : "",
              "",
              describeObservation(observed, step),
            ]
              .filter(Boolean)
              .join("\n"),
          },
        ],
        timeoutMs: STEP_TIMEOUT_MS * 2,
      });

      const action = (decision.json ?? {}) as AgentAction;
      if (!action.action) {
        return {
          ok: false,
          summary: `The model did not return an action: ${decision.text.slice(0, 200)}`,
          steps,
          screenshot: Buffer.from(await page.screenshot({ type: "png" })),
          diagnosis: null,
        };
      }

      if (action.action === "finish") {
        const passed = action.verdict !== "fail";
        const summary = action.summary ?? action.thought ?? "Finished.";
        return {
          ok: passed,
          summary,
          steps,
          screenshot: passed ? null : Buffer.from(await page.screenshot({ type: "png" })),
          diagnosis: null,
        };
      }

      let detail: string;
      try {
        detail = await performAction(page, action, observed.elements);
      } catch (error) {
        const message = error instanceof Error ? error.message : String(error);
        steps.push({
          step,
          action: action.action,
          detail: message,
          expect: action.expect ?? "",
          check: "the action could not be performed",
          confidence: null,
        });
        const shot = Buffer.from(await page.screenshot({ type: "png" }));
        const after = await observe(page);
        return {
          ok: false,
          summary: `Step ${step} failed: ${message}`,
          steps,
          screenshot: shot,
          diagnosis: await diagnose({
            config,
            mission,
            steps,
            observation: after,
            screenshot: shot,
          }),
        };
      }

      const after = await observe(page);
      after.errors = [...new Set(errors)].slice(-10);

      let verdict = { met: 1, error: 0, screen: null as string | null, confidence: null as number | null };
      let check = "not verified";
      try {
        verdict = await verify({ config, expect: action.expect ?? "", observation: after });
        check = `expectation ${verdict.met.toFixed(2)}, error ${verdict.error.toFixed(2)}${
          verdict.screen ? `, screen ${verdict.screen}` : ""
        }`;
      } catch (error) {
        check = `verification unavailable (${
          error instanceof Error ? error.message : String(error)
        })`;
      }

      steps.push({
        step,
        action: action.action,
        detail,
        expect: action.expect ?? "",
        check,
        confidence: verdict.confidence,
      });
      history.push(`${detail} Expected: ${action.expect ?? "(none)"}. Observed: ${check}.`);

      // A visible error is a hard stop: the screen is already wrong, and further
      // steps would only pile up on top of it.
      if (verdict.error >= 0.7) {
        const shot = Buffer.from(await page.screenshot({ type: "png" }));
        return {
          ok: false,
          summary: `Step ${step} left the app in an error state.`,
          steps,
          screenshot: shot,
          diagnosis: await diagnose({
            config,
            mission,
            steps,
            observation: after,
            screenshot: shot,
          }),
        };
      }

      // A missed expectation is different: a wrong guess is normal for an agent,
      // so tell it what happened and let it try something else. Only repeated
      // misses mean the run is genuinely stuck.
      const missed = Boolean(action.expect) && verdict.met <= 0.5;
      if (missed) {
        misses += 1;
        history.push(
          `That did NOT happen (expectation ${verdict.met.toFixed(2)}). ` +
            "Do not repeat it — use a different action, for example longPress, or a different element.",
        );

        if (misses >= MAX_EXPECTATION_MISSES) {
          const shot = Buffer.from(await page.screenshot({ type: "png" }));
          return {
            ok: false,
            summary: `Step ${step} did not achieve its expectation, and ${misses} attempts in a row made no progress.`,
            steps,
            screenshot: shot,
            diagnosis: await diagnose({
              config,
              mission,
              steps,
              observation: after,
              screenshot: shot,
            }),
          };
        }
        continue;
      }

      misses = 0;
    }

    return {
      ok: false,
      summary: `Ran out of steps (${maxSteps}) before the mission was met.`,
      steps,
      screenshot: Buffer.from(await page.screenshot({ type: "png" })),
      diagnosis: null,
    };
  } finally {
    await browser.close();
  }
}

/** Persists the failure frame next to the run, like the Maestro screenshots. */
export async function saveAiScreenshot(runDir: string, image: Buffer): Promise<string> {
  const path = join(runDir, "ai-failure.png");
  await writeFile(path, image);
  return path;
}

/* ------------------------------------------------------------ mission ---- */

const MISSION_CHAR_BUDGET = 6_000;

async function readYamlText(path: string): Promise<string | null> {
  try {
    return await readFile(path, "utf8");
  } catch {
    return null;
  }
}

async function yamlFilesIn(dir: string, depth = 0): Promise<string[]> {
  if (depth > 3) return [];
  try {
    const entries = await readdir(dir, { withFileTypes: true });
    const files: string[] = [];
    for (const entry of entries) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) files.push(...(await yamlFilesIn(full, depth + 1)));
      else if (/\.ya?ml$/i.test(entry.name)) files.push(full);
    }
    return files.sort();
  } catch {
    return [];
  }
}

/**
 * Builds the agent's mission from the repository's own Maestro flows. The YAML
 * already describes the intended journey in terms of stable element ids, so it
 * is the best available specification of "what this test should do" — the AI
 * decides *how* to perform each step on the screens it actually encounters.
 */
export async function buildAiMission(repoDir: string, tests: string[]): Promise<string> {
  const root = join(repoDir, ".maestro", "flows");

  const selected = tests.length > 0 ? tests : ["smoke"];
  const parts: string[] = [];

  for (const test of selected) {
    const files = await yamlFilesIn(join(root, test));
    for (const file of files) {
      const text = await readYamlText(file);
      if (text) parts.push(`--- ${relative(root, file)}\n${text}`);
    }
  }

  // Shared steps carry the ids the flows actually rely on.
  for (const file of await yamlFilesIn(join(root, "shared"))) {
    const text = await readYamlText(file);
    if (text) parts.push(`--- ${relative(root, file)}\n${text}`);
  }

  for (const file of await yamlFilesIn(join(root, "full_app"))) {
    const text = await readYamlText(file);
    if (text) parts.push(`--- ${relative(root, file)}\n${text}`);
  }

  let spec = parts.join("\n\n");
  if (spec.length > MISSION_CHAR_BUDGET) spec = `${spec.slice(0, MISSION_CHAR_BUDGET)}\n…(truncated)`;

  const identifiers = [...new Set(spec.match(/id:\s*"?([A-Za-z0-9_|.-]+)"?/g) ?? [])]
    .map((match) => match.replace(/id:\s*"?/, "").replace(/"$/, ""))
    .slice(0, 40);

  return [
    `Exercise this application the way the Maestro suite "${selected.join(", ")}" intends.`,
    "Work through the journey below in order, adapting to the elements you actually find:",
    "if an expected element is missing, look for an equivalent one before giving up.",
    "",
    `Elements the suite depends on: ${identifiers.join(", ") || "(ids are listed in the flows)"}`,
    "",
    "Maestro flows describing the journey:",
    spec || "(no flow files were found; explore the app and verify its main journey works)",
    "",
    "Finish with verdict \"pass\" once the journey completes, or \"fail\" if a step is impossible.",
  ].join("\n");
}

