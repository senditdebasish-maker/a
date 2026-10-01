# Rollback / forward-fix: 003_submission_snapshot_revisions

This migration is intentionally additive. Production rollback should normally be a **forward fix**: stop new deployments, retain `application_submission_snapshot_revisions`, correct the application code, and redeploy. The table contains immutable evidence and must not be dropped while admission records exist.

If a deployment is rolled back to code that predates migration 003, leaving the table in place is safe; older code does not reference it. Do not delete revision rows.

For a non-production, empty installation only, an operator may remove the migration ledger row and table after independently verifying that the table has no rows:

```sql
SELECT COUNT(*) FROM application_submission_snapshot_revisions;
-- Only when the result is 0 in a disposable environment:
DROP TABLE application_submission_snapshot_revisions;
DELETE FROM schema_migrations WHERE version = '003_submission_snapshot_revisions';
```

Never run those destructive statements on a production or evidentiary database. Always take and verify an encrypted backup first.
