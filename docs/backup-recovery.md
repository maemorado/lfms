# LFMS Backup and Recovery Plan

**Verification status:** No backup was created, no backup schedule was inspected in the Supabase dashboard, and no restore was performed during Phase 13. This is a documented plan, not proof of an operational backup.

## 1. What must be backed up

LFMS has three separate recovery concerns:

1. **PostgreSQL database** — application tables, relationships, constraints, functions, triggers, RLS policies, and audit data.
2. **Supabase Auth and project configuration** — Auth users/settings, redirect URLs, API configuration, and project-level settings.
3. **Supabase Storage objects** — uploaded maintenance/incident evidence files. Database backups do not include the binary objects stored through the Storage API.

The live project currently has a private `lfms-evidence` bucket with a 10 MiB object limit and an allowlist of JPEG, PNG, WebP, PDF, and plain text. The bucket currently reports **0 objects**. This is a point-in-time observation; after uploads begin, Storage backups become essential.

## 2. Supabase-managed database backups

Check the actual project plan in Supabase Dashboard > Organization Billing / Project > Database > Backups. The connected project metadata does not expose its plan tier or confirm that scheduled backups exist.

According to the current Supabase backup documentation:
- Free plan: no automatic daily database backups; export manually and keep an off-site copy.
- Pro: automatic daily backups with 7 days of retention.
- Team: automatic daily backups with 14 days of retention.
- Enterprise: up to 30 days of daily backup retention.
- PITR is an additional paid option for finer-grained recovery; enabling PITR replaces the daily-backup schedule.

See [Supabase Database Backups](https://supabase.com/docs/guides/platform/backups).

**Recommendation:** For a live campus operations system, use at least daily database backups with 30-day off-site retention where affordable. Evaluate PITR only after defining the required recovery point objective (RPO) and recovery time objective (RTO), and approving its recurring cost. Confirm the latest successful backup timestamp in the dashboard before launch.

## 3. Manual logical database export

For a project without automatic daily backups, create regular encrypted logical exports from a trusted workstation or private backup runner. Use the exact Session Pooler connection string from Supabase > Connect; do not put it in Git, command history, issue comments, or logs.

The Supabase CLI v3 is local-first. Use an explicit `--remote` target for a read-only remote dump and inspect CLI help before changing commands after a CLI upgrade.

Example Bash session (the connection string is supplied through a protected environment variable, not committed):

```bash
# Set this securely in your terminal/secret manager before running.
# SUPABASE_DB_URL must be the Session Pooler connection string.
: "${SUPABASE_DB_URL:?Set SUPABASE_DB_URL securely first}"

mkdir -p "$HOME/secure-backups/lfms"
chmod 700 "$HOME/secure-backups/lfms"

supabase db dump --remote="$SUPABASE_DB_URL" -f "$HOME/secure-backups/lfms/roles.sql" --role-only
supabase db dump --remote="$SUPABASE_DB_URL" -f "$HOME/secure-backups/lfms/schema.sql"
supabase db dump --remote="$SUPABASE_DB_URL" -f "$HOME/secure-backups/lfms/data.sql" --data-only --use-copy -x "storage.buckets_vectors" -x "storage.vector_indexes"
```

Important limitations:
- Supabase CLI `db dump` excludes Supabase-managed schemas such as `auth` and `storage`. It is not a complete substitute for a managed project backup when Auth state and Storage metadata must be recovered.
- A logical dump does not contain Storage binary objects.
- Custom roles and grants require careful review before restoring into a new project.
- Keep all dump files encrypted at rest and off-site. Never commit them to this repository or store them in a public bucket.

Official references: [Backup and Restore using the CLI](https://supabase.com/docs/guides/platform/migrating-within-supabase/backup-restore) and [CLI db dump](https://supabase.com/docs/reference/cli/supabase-db-dump).

## 4. Storage object backup

Database backups do not include Storage object binaries. For bulk object backup, Supabase supports an S3-compatible Storage endpoint.

Recommended setup:
1. Open Supabase Dashboard > Storage > Configuration > S3.
2. Enable the S3 protocol if it is not already enabled.
3. Generate a dedicated S3 access key pair for the backup process and store it in a secure local credential store or backup-runner secret manager.
4. Use the endpoint and region displayed in the dashboard.
5. Copy the private evidence bucket to an encrypted off-site directory.

Example (AWS CLI profile configured separately; replace the endpoint/region with the dashboard values):

```bash
aws s3 sync \
  s3://lfms-evidence \
  "$HOME/secure-backups/lfms/storage/lfms-evidence" \
  --endpoint-url "https://YOUR_PROJECT_REF.supabase.co/storage/v1/s3" \
  --region "ap-southeast-1" \
  --profile lfms-backup
```

Do not add `--delete` to backup commands. Do not use the destructive empty-bucket sync pattern.

**Security warning:** Supabase S3 access keys provide broad access and bypass Storage RLS. They are server/backup-only credentials, never frontend credentials. Rotate them if exposed. Do not use a service-role key in the React app.

For larger buckets, use `rclone` or AWS CLI and verify the copied object count and total bytes. Supabase docs: [Download Objects](https://supabase.com/docs/guides/storage/management/download-objects) and [S3 Authentication](https://supabase.com/docs/guides/storage/s3/authentication).

## 5. Recommended schedule and retention

| Backup component | Minimum recommendation | Retention recommendation |
|---|---|---|
| Managed database backup | Daily (verify in dashboard) | Plan-dependent; keep an additional encrypted off-site export for 30 days |
| Manual logical database export | Before each approved schema release and at least weekly if managed backups unavailable | 30 days |
| Storage object copy | Daily after evidence uploads begin; before large file migrations | 30 days or campus policy |
| Configuration inventory | After each configuration change | Keep versioned, secret-free documentation |
| Restore drill | Quarterly and after major schema changes | Keep the drill report with the backup |

Define the final RPO/RTO with the system owner. These recommendations are not a guarantee that Supabase's current plan includes these retention periods.

## 6. Recovery procedure

**Never test a restore directly on the live LFMS project.** Restore to a new disposable Supabase project or isolated staging environment first.

1. Declare the incident and stop further destructive changes.
2. Identify the latest known-good database backup and Storage object backup.
3. Record backup timestamps, source project reference, schema/migration version, and known data-loss window.
4. Create or select an isolated recovery project in the same region where practical.
5. Restore the database using the documented Supabase managed backup restore process, or carefully restore logical exports into a fresh project after reviewing Supabase-managed schema and privilege requirements.
6. Recreate project-level Auth settings, redirect allowlist, API keys, Storage bucket configuration, and any external integrations that are not included in the database restore.
7. Restore Storage objects into the matching private bucket. Avoid overwriting a live bucket during a drill.
8. Verify object counts, file sizes, MIME metadata, private access policies, and authorized signed/download access.
9. Verify profiles, role assignments, permissions, relationships, constraints, functions, triggers, RLS policies, and audit records.
10. Run backend tests and the Admin/Staff/SA acceptance checklist.
11. Verify Auth login, invitation acceptance, token refresh, and API calls against the recovery project.
12. Record results and only then plan a separately approved production cutover.

A restore may cause downtime and may lose changes after the selected backup. Restoring to a new project is safer for drills and data recovery because it avoids replacing the current database.

## 7. Recovery verification checklist

- [ ] Database backup timestamp and retention confirmed in Supabase Dashboard.
- [ ] Encrypted off-site logical export exists and checksum recorded.
- [ ] Storage bucket copy exists and object count/bytes recorded.
- [ ] Backup credentials are stored separately from application credentials.
- [ ] Recovery project restored without touching production.
- [ ] Database schema, RLS, grants, triggers, and constraints verified.
- [ ] Auth users/settings and redirect URLs verified.
- [ ] Private Storage files and ownership policies verified.
- [ ] Admin and Staff/SA acceptance workflows pass.
- [ ] Recovery duration and data-loss window recorded.

## 8. Current gaps

- The Supabase plan tier is not available through the connected project metadata; automatic backup eligibility is unknown.
- No backup list, last successful backup timestamp, PITR status, or retention window was available through the connected tools.
- No backup was generated or downloaded during Phase 13.
- No Storage S3 access key was generated, and S3 protocol enablement was not verified.
- No restore drill has been performed.
- The repository has only 7 SQL migration files while the live project has 20 migration-history entries. Recover the missing migration definitions before relying on Git migrations for a rebuild.
- The current project has 0 Storage objects at inspection time, but this does not remove the need for a future Storage backup process.
