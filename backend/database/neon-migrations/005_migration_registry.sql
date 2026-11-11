-- Private ledger for the Neon-specific migration sequence.
-- Baseline migrations 001-003 are adopted only after schema reconciliation;
-- the execution controller records their Git object IDs and adoption status.
-- This table is not accessible to the LFMS runtime role.

CREATE TABLE private.neon_schema_migrations (
  version integer PRIMARY KEY,
  filename text NOT NULL UNIQUE,
  git_blob_sha1 char(40) NOT NULL
    CHECK (git_blob_sha1 ~ '^[0-9a-f]{40}$'),
  source_migrations text[] NOT NULL DEFAULT ARRAY[]::text[],
  record_kind text NOT NULL
    CHECK (record_kind IN ('adopted', 'executed', 'pending_reconciliation')),
  recorded_at timestamptz NOT NULL DEFAULT now(),
  recorded_by name NOT NULL DEFAULT current_user,
  notes text
);

REVOKE ALL ON TABLE private.neon_schema_migrations FROM PUBLIC, lfms_app;
