# Rollback plan — `002_admission_management`

This migration is intentionally forward-only. It preserves all existing admission records and adds nullable columns, indexes, foreign keys, and new tables. MySQL/MariaDB DDL auto-commits, so an automatic down migration would create a false expectation of atomic rollback and could destroy configuration snapshots, correction history, fee assessments, document revisions, or seat allocations.

## Before applying

1. Put the portal in a maintenance window.
2. Create an encrypted application backup from **Admin → Backups**.
3. Download it and verify its SHA-256 checksum away from the server.
4. Run `php scripts/migrate.php --dry-run`.
5. Review the preflight output and resolve unexpected schema drift.
6. Apply only with `php scripts/migrate.php --confirm=APPLY --backup-confirmed`.

## If the migration is interrupted

Do not import the clean-install schema and do not manually drop partially created tables. The migration operations are guarded through `information_schema` and are safe to rerun. Correct the reported database error, inspect the failed operation, and rerun the same command. The migration is recorded only after post-apply verification succeeds.

## If application rollback is required

1. Keep the database and protected uploads intact.
2. Restore the previous application code release.
3. Leave added nullable columns and module tables in place; the previous release ignores them.
4. Forward-fix the application or migration and redeploy.

This is the preferred rollback because it does not destroy data.

## Full disaster recovery

Only if a forward fix is impossible:

1. Isolate the site in maintenance mode.
2. Preserve the failed database and storage directory for investigation.
3. Use `scripts/restore-backup.php` with the independently verified pre-migration encrypted backup.
4. Restore the matching previous application release and original `APP_KEY`.
5. Run preflight, verify record counts and protected files, then perform public, applicant, staff, payment, and document workflow checks before reopening.

## Security-redaction note

The approved migration replaces historical `verify_email`, `password_reset`, and `staff_mfa` mail bodies with `NULL`, retaining a SHA-256 body checksum and non-sensitive delivery metadata. Those authentication-secret bodies are deliberately not recoverable from the upgraded live database. Restoring a pre-migration backup also restores those old secrets, so such a backup must remain encrypted, access-restricted, and expire under the institution's backup-retention policy.
