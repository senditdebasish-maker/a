# Migration 005 rollback guidance

Migration `005_application_reapply_attempts` changes the application identity rule from one row per applicant/cycle to one row per applicant/cycle/attempt. It adds `attempt_no` and `reapplied_from_application_id`, preserves every existing row as attempt 1, and replaces the old unique index. It does not delete or rewrite an application.

A destructive rollback is intentionally not automated. Once a second attempt exists, restoring `UNIQUE (user_id, admission_cycle_id)` would require deleting or merging an application and its related documents, snapshots, history, fees and audit evidence. Do not do that.

If deployment must be reversed before any second attempt is created:

1. Enable maintenance mode and take an encrypted, independently verified backup.
2. Confirm there are no duplicate `(user_id, admission_cycle_id)` groups.
3. Restore `uq_user_cycle`, then remove the lineage foreign key/indexes and added columns only after application-code rollback.
4. Run the full migration and HTTP suites on a restored staging copy.

If any second attempt exists, keep the additive schema and deploy a forward code fix instead. Never delete an application attempt to force an old schema to fit.
