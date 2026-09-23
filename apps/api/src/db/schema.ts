import {
  boolean,
  index,
  integer,
  jsonb,
  pgTable,
  text,
  timestamp,
  uniqueIndex,
  uuid,
} from "drizzle-orm/pg-core";

export const users = pgTable("users", {
  id: uuid("id").primaryKey().defaultRandom(),
  username: text("username").notNull().unique(),
  passwordHash: text("password_hash").notNull(),
  role: text("role").notNull().default("admin"),
  passwordChangedAt: timestamp("password_changed_at", { withTimezone: true }),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
});

export const sessions = pgTable(
  "sessions",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    userId: uuid("user_id")
      .notNull()
      .references(() => users.id, { onDelete: "cascade" }),
    tokenHash: text("token_hash").notNull().unique(),
    ip: text("ip"),
    userAgent: text("user_agent"),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
    lastSeenAt: timestamp("last_seen_at", { withTimezone: true }).notNull().defaultNow(),
    expiresAt: timestamp("expires_at", { withTimezone: true }).notNull(),
    revokedAt: timestamp("revoked_at", { withTimezone: true }),
  },
  (table) => [index("sessions_user_id_idx").on(table.userId)],
);

export const loginAttempts = pgTable(
  "login_attempts",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    ip: text("ip").notNull(),
    username: text("username"),
    success: boolean("success").notNull(),
    counted: boolean("counted").notNull().default(false),
    reason: text("reason"),
    userAgent: text("user_agent"),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => [index("login_attempts_ip_created_idx").on(table.ip, table.createdAt)],
);

export const ipBlocks = pgTable(
  "ip_blocks",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    ip: text("ip").notNull().unique(),
    failedCount: integer("failed_count").notNull().default(0),
    reason: text("reason"),
    blockedAt: timestamp("blocked_at", { withTimezone: true }).notNull().defaultNow(),
    expiresAt: timestamp("expires_at", { withTimezone: true }).notNull(),
    releasedAt: timestamp("released_at", { withTimezone: true }),
    releasedBy: text("released_by"),
  },
  (table) => [index("ip_blocks_expires_idx").on(table.expiresAt)],
);

export const captchaChallenges = pgTable(
  "captcha_challenges",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    answerHash: text("answer_hash").notNull(),
    ip: text("ip"),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
    expiresAt: timestamp("expires_at", { withTimezone: true }).notNull(),
    consumedAt: timestamp("consumed_at", { withTimezone: true }),
  },
  (table) => [index("captcha_challenges_expires_idx").on(table.expiresAt)],
);

export const auditLog = pgTable(
  "audit_log",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    actorUserId: uuid("actor_user_id").references(() => users.id, { onDelete: "set null" }),
    action: text("action").notNull(),
    ip: text("ip"),
    userAgent: text("user_agent"),
    metadata: jsonb("metadata").$type<Record<string, unknown>>(),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => [index("audit_log_created_idx").on(table.createdAt)],
);

/**
 * Single-row table holding the GitLab connection. The token is only ever held
 * as ciphertext; `tokenHint` exists so the UI can show which token is stored
 * without ever disclosing it.
 */
export const gitlabConnections = pgTable("gitlab_connections", {
  id: uuid("id").primaryKey().defaultRandom(),
  baseUrl: text("base_url").notNull(),
  tokenCiphertext: text("token_ciphertext").notNull(),
  tokenHint: text("token_hint").notNull(),
  caCertificate: text("ca_certificate"),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
  lastVerifiedAt: timestamp("last_verified_at", { withTimezone: true }),
  lastVerifyOk: boolean("last_verify_ok"),
  lastVerifyError: text("last_verify_error"),
});

/**
 * Pinned repositories and branches. Pins are application-wide rather than
 * per-user, which matches the single-admin setup.
 */
export const pins = pgTable(
  "pins",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    kind: text("kind").notNull(),
    projectId: integer("project_id").notNull(),
    projectPath: text("project_path").notNull(),
    // Empty string for a repository pin. Postgres treats NULLs as distinct in
    // a unique index, so a sentinel is needed for the constraint to hold.
    branch: text("branch").notNull().default(""),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  },
  (table) => [
    uniqueIndex("pins_target_idx").on(table.kind, table.projectId, table.branch),
  ],
);

/**
 * A queued/executed test run. Runs are executed by the server-side worker, so
 * they keep going after the browser that started them is closed.
 */
export const runs = pgTable(
  "runs",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    projectId: integer("project_id").notNull(),
    projectPath: text("project_path").notNull(),
    branch: text("branch").notNull(),
    tests: jsonb("tests").$type<string[]>().notNull().default([]),
    runKinds: jsonb("run_kinds").$type<string[]>().notNull().default([]),
    environments: jsonb("environments").$type<string[]>().notNull().default([]),
    status: text("status").notNull().default("queued"),
    currentStep: text("current_step").notNull().default("queued"),
    orientation: text("orientation").notNull().default("horizontal"),
    dartDefines: text("dart_defines").notNull().default(""),
    errorMessage: text("error_message"),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
    startedAt: timestamp("started_at", { withTimezone: true }),
    finishedAt: timestamp("finished_at", { withTimezone: true }),
  },
  (table) => [index("runs_status_created_idx").on(table.status, table.createdAt)],
);

/** Per-repository preferences that should survive between runs. */
export const projectSettings = pgTable("project_settings", {
  id: uuid("id").primaryKey().defaultRandom(),
  projectId: integer("project_id").notNull().unique(),
  orientation: text("orientation").notNull().default("horizontal"),
  dartDefines: text("dart_defines").notNull().default(""),
  createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
});

/** Per-stage progress for a run, so the dashboard can show where it is. */
export const runSteps = pgTable(
  "run_steps",
  {
    id: uuid("id").primaryKey().defaultRandom(),
    runId: uuid("run_id")
      .notNull()
      .references(() => runs.id, { onDelete: "cascade" }),
    key: text("key").notNull(),
    label: text("label").notNull(),
    status: text("status").notNull().default("pending"),
    output: text("output"),
    position: integer("position").notNull().default(0),
    startedAt: timestamp("started_at", { withTimezone: true }),
    finishedAt: timestamp("finished_at", { withTimezone: true }),
  },
  (table) => [index("run_steps_run_idx").on(table.runId, table.position)],
);
