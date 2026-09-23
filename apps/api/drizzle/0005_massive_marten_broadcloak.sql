ALTER TABLE "project_settings" ADD COLUMN "dart_defines" text DEFAULT '' NOT NULL;--> statement-breakpoint
ALTER TABLE "runs" ADD COLUMN "dart_defines" text DEFAULT '' NOT NULL;