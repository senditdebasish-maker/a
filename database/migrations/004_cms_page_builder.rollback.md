# Migration 004 recovery guidance

Migration `004_cms_page_builder` is additive. It creates `page_sections`, adds the missing public route page shells with `INSERT ... ON DUPLICATE KEY`, preserves every non-empty legacy page body in a rich-text section, and supplies an editable starter home composition. It does not drop or rewrite an existing page, translation, application, payment, upload, or admission record.

## Preferred recovery

Do not drop the table on a production database. If deployment fails after MySQL or MariaDB auto-commits part of the migration:

1. Keep maintenance mode enabled.
2. Inspect `page_sections`, the twelve expected `pages.slug` values, and `schema_migrations`.
3. Correct the reported database or permission issue.
4. Rerun `php scripts/migrate.php --confirm=APPLY --backup-confirmed`.

Every schema/content operation in migration 004 is guarded or idempotent, so a rerun completes missing work without replacing existing content.

## Disable without deleting

To withdraw a section, set its status to `draft` or use **Archive** in the CMS. To withdraw a route page, set its page status to `draft`. These actions preserve content and translations.

## Exceptional removal

Removing `page_sections` is destructive and intentionally has no automated rollback command. Only consider it after exporting the table and matching `content_translations` rows where `entity_type = 'page_section'`, confirming a tested full backup, and deploying application code that no longer queries the table. Never remove `pages` or generic translation records as part of this recovery.
