import {
  boolean,
  index,
  integer,
  jsonb,
  pgTable,
  text,
  timestamp,
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
