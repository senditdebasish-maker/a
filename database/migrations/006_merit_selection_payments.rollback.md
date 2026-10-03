# Rollback notes — 006 merit, selection and online payments

This migration is additive. It does not rewrite or remove applications, payments, seat allocations, academic records, documents, or admission configuration.

A rollback must first disable merit publication, offer expiry and gateway callback routes. Preserve exports of all nine added tables. Do not drop a table while any merit run has been published, any selection offer exists, or any gateway transaction/event exists: those rows are admissions and financial audit evidence.

For a pre-production installation containing no such records only, remove the two merit permission mappings and then drop tables in this dependency order: `payment_gateway_events`, `payment_gateway_transactions`, `payment_gateway_configs`, `admission_notification_outbox`, `selection_offers`, `merit_entries`, `merit_run_programs`, `merit_runs`, `merit_formula_versions`, `merit_cycle_settings`. Finally remove the `006_merit_selection_payments` migration-ledger row. Production rollback should instead deploy a forward corrective migration.
