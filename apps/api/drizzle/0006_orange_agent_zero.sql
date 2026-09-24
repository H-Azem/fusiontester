CREATE TABLE "ai_connections" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"openai_base_url" text NOT NULL,
	"openai_model" text NOT NULL,
	"openai_token_ciphertext" text NOT NULL,
	"openai_token_hint" text NOT NULL,
	"jev_base_url" text NOT NULL,
	"jev_token_ciphertext" text NOT NULL,
	"jev_token_hint" text NOT NULL,
	"max_steps" integer DEFAULT 25 NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	"last_verified_at" timestamp with time zone,
	"last_verify_ok" boolean,
	"last_verify_error" text
);
