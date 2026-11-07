# Supabase configuration

Project: LFMS (`bwebicixgcnzyqoaccha`)  
Region: `ap-southeast-1`  
URL: `https://bwebicixgcnzyqoaccha.supabase.co`

The direct PostgreSQL endpoint is `db.bwebicixgcnzyqoaccha.supabase.co:5432`, but it resolves to IPv6 only. On IPv4-only networks use the shared pooler in session mode: host `aws-0-ap-southeast-1.pooler.supabase.com`, port `5432`, username `postgres.bwebicixgcnzyqoaccha`, database `postgres`, SSL mode `require`. The database password is not available through the integration and must be entered locally using the Supabase dashboard.

The publishable key may be used in frontend configuration. Secret keys and database passwords must remain server-side and must never be committed.

The remote project already contains identity/access-management and facility-management tables and recorded migrations. This setup does not run migrations or modify remote data/schema. Review the live schema before planning any future migration.
