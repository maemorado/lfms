-- Create the non-owner runtime principal without a password.
-- A LOGIN credential is provisioned separately when the Render service is ready.
-- The role inherits only privileges explicitly granted to lfms_app.
-- Never grant this role BYPASSRLS, ownership, or Neon administrative membership.

DO $role$
DECLARE
  existing_role record;
BEGIN
  SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolreplication, rolbypassrls
    INTO existing_role
  FROM pg_roles
  WHERE rolname = 'lfms_runtime';

  IF NOT FOUND THEN
    EXECUTE 'CREATE ROLE lfms_runtime NOLOGIN INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS';
  ELSE
    IF existing_role.rolsuper
       OR existing_role.rolcreatedb
       OR existing_role.rolcreaterole
       OR existing_role.rolreplication
       OR existing_role.rolbypassrls THEN
      RAISE EXCEPTION 'Existing lfms_runtime role has unsafe elevated attributes';
    END IF;

    EXECUTE 'ALTER ROLE lfms_runtime INHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS';
  END IF;
END
$role$;

GRANT lfms_app TO lfms_runtime;
