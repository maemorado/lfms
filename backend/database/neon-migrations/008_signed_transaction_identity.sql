-- Signed, transaction-local identity context for pooled Neon connections.
-- A custom PostgreSQL GUC is user-settable, so the raw user ID is not trusted.
-- Laravel signs user_id:txid_current() with LFMS_IDENTITY_HMAC_KEY.
-- The matching key is stored in a private table inaccessible to lfms_runtime.

CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;

CREATE TABLE private.identity_signing_key (
  singleton boolean PRIMARY KEY DEFAULT true CHECK (singleton),
  secret bytea NOT NULL CHECK (octet_length(secret) = 32),
  created_at timestamptz NOT NULL DEFAULT now()
);

INSERT INTO private.identity_signing_key (singleton, secret)
VALUES (true, public.gen_random_bytes(32));

REVOKE ALL ON TABLE private.identity_signing_key FROM PUBLIC, lfms_app, lfms_runtime;

CREATE OR REPLACE FUNCTION private.current_user_id()
RETURNS uuid
LANGUAGE plpgsql
VOLATILE
SECURITY DEFINER
SET search_path = ''
AS $function$
DECLARE
  v_user_text text := nullif(pg_catalog.current_setting('app.current_user_id', true), '');
  v_signature text := nullif(pg_catalog.current_setting('app.current_user_signature', true), '');
  v_user_id uuid;
  v_secret bytea;
  v_expected_signature text;
BEGIN
  IF v_user_text IS NULL
     OR v_signature IS NULL
     OR v_user_text !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
     OR v_signature !~ '^[0-9a-f]{64}$' THEN
    RETURN NULL;
  END IF;

  v_user_id := v_user_text::uuid;

  SELECT secret
    INTO v_secret
  FROM private.identity_signing_key
  WHERE singleton = true;

  IF v_secret IS NULL THEN
    RETURN NULL;
  END IF;

  v_expected_signature := pg_catalog.encode(
    public.hmac(
      pg_catalog.convert_to(v_user_id::text || ':' || pg_catalog.txid_current()::text, 'UTF8'),
      v_secret,
      'sha256'
    ),
    'hex'
  );

  IF v_signature IS DISTINCT FROM v_expected_signature THEN
    RETURN NULL;
  END IF;

  RETURN v_user_id;
EXCEPTION
  WHEN invalid_text_representation THEN
    RETURN NULL;
END;
$function$;

REVOKE ALL ON FUNCTION private.current_user_id() FROM PUBLIC, lfms_app;
GRANT EXECUTE ON FUNCTION private.current_user_id() TO lfms_app;

-- Replace the audit trigger body so it records only a verified actor, not a raw GUC.
CREATE OR REPLACE FUNCTION public.capture_row_audit()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $function$
DECLARE
  old_row jsonb;
  new_row jsonb;
  target_id uuid;
  actor_id uuid;
BEGIN
  actor_id := private.current_user_id();

  IF tg_op = 'INSERT' THEN
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  ELSIF tg_op = 'UPDATE' THEN
    old_row := to_jsonb(old);
    new_row := to_jsonb(new);
    target_id := (new_row ->> 'id')::uuid;
  ELSE
    old_row := to_jsonb(old);
    target_id := (old_row ->> 'id')::uuid;
  END IF;

  INSERT INTO public.audit_logs (user_id, table_name, record_id, action, old_values, new_values)
  VALUES (actor_id, tg_table_name, target_id, tg_op, old_row, new_row);

  IF tg_op = 'DELETE' THEN
    RETURN old;
  END IF;
  RETURN new;
END;
$function$;

-- Supabase Auth email changes are synchronized only for the verified profile owner.
CREATE OR REPLACE FUNCTION private.sync_auth_email(p_user_id uuid, p_email text)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $function$
DECLARE
  v_actor uuid := private.current_user_id();
  v_email text := nullif(btrim(p_email), '');
BEGIN
  IF v_actor IS NULL OR v_actor IS DISTINCT FROM p_user_id THEN
    RAISE EXCEPTION 'Only the authenticated profile owner may synchronize an email address'
      USING ERRCODE = '42501';
  END IF;

  IF v_email IS NULL THEN
    RAISE EXCEPTION 'A non-empty Auth email address is required'
      USING ERRCODE = '22023';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM public.profiles
    WHERE id = p_user_id AND status = 'active'
  ) THEN
    RAISE EXCEPTION 'An active LFMS profile is required for email synchronization'
      USING ERRCODE = '42501';
  END IF;

  UPDATE public.profiles
  SET email = v_email, updated_at = now()
  WHERE id = p_user_id
    AND email IS DISTINCT FROM v_email;
END;
$function$;

REVOKE ALL ON FUNCTION private.sync_auth_email(uuid, text) FROM PUBLIC, lfms_app;
GRANT EXECUTE ON FUNCTION private.sync_auth_email(uuid, text) TO lfms_app;
