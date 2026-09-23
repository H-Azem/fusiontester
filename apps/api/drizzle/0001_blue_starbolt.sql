CREATE TABLE "gitlab_connections" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"base_url" text NOT NULL,
	"token_ciphertext" text NOT NULL,
	"token_hint" text NOT NULL,
	"ca_certificate" text,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	"last_verified_at" timestamp with time zone,
	"last_verify_ok" boolean,
	"last_verify_error" text
);
