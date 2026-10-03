# Admission Management Module — Acceptance and Operations Report

**Institution:** Netaji College of Pharmacy  
**Release branch:** `arena/01a0f38a-a`  
**Release date:** 3 October 2026
**Architecture:** custom PHP 8.1+ / MySQL 8 / Apache (XAMPP compatible); no Node.js runtime

## 1. Delivery status

The database-driven Admission Management Module is integrated into the existing application. It reuses the existing authentication, RBAC, CSRF middleware, database wrapper, layouts, upload storage, payment records, `MailService`, audit log, CMS, PDF generation and applicant accounts.

The operational hierarchy is:

`Admission Cycle → Cycle Programmes → Eligibility → Form Fields → Documents → Fees → Applications`

Cycle state and application state are separate. Public availability is calculated on the server from stored lifecycle state and dates. Published cycle configuration is immutable through the application; changes require duplication into a new draft.

## 2. Principal implementation files

### Controllers

- `app/Controllers/Admin/AdmissionController.php` — cycle dashboard, lifecycle, preview, publication, duplication, programme catalogue/assignment, seats, eligibility, form, document and fee configuration.
- `app/Controllers/Admin/ApplicationController.php` — paginated review queue, reviewer scoping, eligibility, targeted corrections, decisions, document/payment verification and safe export.
- `app/Controllers/Admin/DashboardController.php` — cycle-filtered operational statistics.
- `app/Controllers/Admin/ReportController.php` — cycle/programme reports for funnel, profile, geography, workload, finance and seats.
- `app/Controllers/ApplicantController.php` — cycle-specific drafts, dynamic forms, ranked preferences, conditional fields, protected documents, submission, corrections, assessments and payments.
- `app/Controllers/PublicController.php` — live/scheduled admission cards, details and prospectus delivery.
- `app/Controllers/FileController.php` and `GeneratedDocumentController.php` — owner/permission/reviewer-scoped protected files, revisions, receipts and letters.

### Services

- `AdmissionCycleService` — effective status, readiness, publication snapshots, close/archive and safe duplication.
- `ApplicationWorkflowService` — guarded application transitions and admission hand-off.
- `SeatAllocationService` — row-locked allocation, confirmation and release.
- `CorrectionService` — validated field/section/document correction targets and resubmission.
- `EligibilityService` — database rules evaluated per ranked programme.
- `AdmissionFeeService` — category/rule-based immutable assessments.
- `ApplicationNumberService` — transactional per-cycle/programme numbering.
- `ApplicationSnapshotService` — configuration-linked submission evidence and immutable revisions.
- `ReapplicationService` — open-cycle, latest-rejected-attempt validation and transactional copying into a separately linked draft without mutating source evidence.
- `UploadService` — private randomized storage, allowlisted MIME inspection, size checks, PDF/image validation and SHA-256 hashes.

### Views and assets

- `resources/views/admin/admissions/` — cycle list, create form and complete cycle workspace.
- `resources/views/admin/applications/` — filtered queue and detailed review desk.
- `resources/views/admin/reports.php`, `admin/dashboard.php` — real database reporting.
- `resources/views/public/admissions.php`, `public/admission-detail.php`, `public/home.php` — public integration without duplicate CMS admission copy.
- `resources/views/student/application.php`, `student/dashboard.php` — cycle-aware applicant workflow and multiple-cycle history.
- `public/assets/css/admissions-admin.css` — responsive admissions workspace styles.

## 3. Schema

A clean installation contains **65 tables**. Migration 002 added the admission configuration/workflow foundation; migration 003 adds immutable submission revisions; additive migration 004 adds the public CMS page-section builder; migration 005 replaces the one-row-per-user/cycle constraint with non-destructive numbered attempts and self-lineage. No migration deletes admission records.

Core admission additions are:

- `admission_categories`
- `admission_configuration_versions`
- `admission_form_sections`
- `admission_form_fields`
- `application_field_responses`
- `application_submission_snapshots`
- `application_submission_snapshot_revisions`
- `application_corrections`
- `application_correction_items`
- `application_document_versions`
- `admission_fee_rules`
- `application_fee_assessments`
- `seat_allocations`
- `application_number_counters`
- `payment_refunds`

Existing tables receive additive nullable/defaulted columns and indexes only. The guarded migrations do not delete applications or production records.

`application_submission_snapshots` remains the compatibility/current snapshot. `application_submission_snapshot_revisions` preserves revision 1 and every correction resubmission as immutable JSON plus SHA-256 hash, configuration version, event, actor and timestamp.

## 4. Routes

### Public/applicant

- `GET /admissions`
- `GET /admissions/{slug}`
- `GET /admissions/{slug}/prospectus`
- `GET /admissions/{slug}/apply`
- `GET /student/application`
- `POST /student/application/save`
- `POST /student/applications/{id}/reapply`
- `POST /student/application/document`
- `POST /student/application/documents/continue`
- `POST /student/application/submit`
- `POST /student/application/corrections/resubmit`
- `GET|POST /student/payments`
- protected document, receipt and generated-document routes

### Admissions administration

- `GET /admin/admissions`
- `GET /admin/admissions/create`
- `POST /admin/admissions`
- `POST /admin/admissions/programs`
- `GET|POST /admin/admissions/{id}`
- `GET /admin/admissions/{id}/preview`
- `POST /admin/admissions/{id}/{publish|close|archive|duplicate}`
- programme assignment plus programme-default update/removal routes beneath `/admin/admissions/{id}`
- seat-capacity/matrix, eligibility, fee, form-section, form-field and document-requirement create/update routes
- guarded POST removal routes for unused draft seat rows, eligibility rules, fee rules, empty sections, unanswered fields and unused document requirements

### Review/reporting

- `GET /admin/applications` and `/admin/applications/export`
- `GET /admin/applications/{id}`
- eligibility, status, targeted-correction, assignment, note, document and payment actions beneath the application route
- `GET /admin/reports`
- protected admin receipt and generated-document routes

All state-changing routes use the existing CSRF middleware and POST semantics.

## 5. RBAC

Migration 002/Seeder define:

- `admissions.view`
- `admissions.manage`
- `admissions.publish`
- `admissions.duplicate`
- `admission_forms.manage`
- `admission_documents.manage`
- `admission_fees.manage`
- `admission_seats.manage`
- `applications.correct`
- existing application, document, payment, report and audit permissions

The super-admin receives the full permission set. Admission officers manage operational configuration and review. Reviewers see and mutate only applications assigned to them. Accounts officers verify payments and view finance reports. Principal/auditor access remains read-oriented except for explicitly granted publication authority.

## 6. Functional behavior

- Draft cycles are configured through one responsive seven-step workspace: notice and dates, programmes, seats and eligibility, fees, application form, documents, and final review/publication.
- A branded cycle command centre summarizes the live application window, programme count, configured seats, configuration version, circular completion progress and current editing step. The step rail exposes ready/current/to-do states, while an accessible unsaved-change indicator warns before navigating away from edited forms.
- Seat distribution uses a plain-language intake equation and live shortage/excess feedback; eligibility rules use a sentence builder while the server remains authoritative.
- Application form customisation separates sections from applicant questions, groups questions visually, provides a student-style quick-edit preview, reuses configured section/field copy in the applicant view, protects operational bindings, and derives omitted internal keys server-side.
- Contextual `?` help markers explain admission option headings on hover, keyboard focus or touch focus; action controls expose purpose text.
- Existing programme defaults, capacity/category seats, eligibility and fee rules, form sections/fields/options/conditional JSON, and document requirements are editable rather than add-only; technical controls remain available under advanced settings.
- Draft-only removal actions enforce ownership and usage guards so applicant-linked configuration is preserved; fields can be set inactive when deletion is not safe.
- Readiness blocks publication until dates, programme, seats, eligibility, fees, required fields, documents, instructions and declaration are valid.
- Publication captures a SHA-256 configuration version and freezes application-level editing.
- Close/archive are explicit lifecycle transitions; date-derived `scheduled`, `live`, `closing_soon` and `closed` states are server-calculated.
- Duplication copies configuration but never applications, payments, allocations or filled-seat counts.
- Applicants start one active attempt per cycle. While the cycle remains open, a rejected latest attempt can create a separate linked draft that copies editable data and documents; every prior attempt remains unchanged and each submission binds to its published configuration version.
- Programme choices are ranked and revalidated against the cycle on submission.
- Dynamic custom fields support text, textarea, email, telephone, number, date, select, radio, checkbox and multiselect controls. Simple JSON `all`/`any` conditional rules are enforced in rendering and server validation.
- File fields bind to protected document types rather than accepting arbitrary public uploads.
- Required programme/category document rules are enforced with immutable document revisions.
- Eligibility is evaluated for every ranked programme; selection requires an eligible result for the chosen programme.
- Application and admission fees come from server assessments. Proof amount and reference checks do not trust hidden/browser values.
- Seat selection uses transaction locks, ranked-programme validation and category/quota capacity counters.
- Admission requires an allocation and paid/waived positive admission fee, confirms the seat and creates one enrollment record.
- Corrections are limited to validated sections, configured fields and required document types; unrelated applicant updates are rejected server-side.
- Application lists are database-paginated and filterable by status, cycle, programme, category, eligibility, payment and reviewer.
- CSV exports apply the active filters, reviewer scope and spreadsheet-formula escaping.

## 7. Security findings and controls

Implemented controls:

- Prepared SQL through the existing database abstraction.
- Contextual output escaping in templates.
- CSRF middleware on mutation routes.
- Permission middleware plus reviewer row-level IDOR checks on detail, generated documents, document revisions and review actions.
- Applicant ownership checks on cycle applications, files and receipts.
- Private upload storage outside the public web root; randomized names, MIME allowlists, magic-byte/image validation, limits and SHA-256 checksums.
- Path canonicalization and containment checks before streaming files.
- Transaction locks and optimistic `status_version` checks around decisions.
- Server-derived cycle availability, programme membership, status transitions, eligibility, assessments, fees and allocation capacity.
- Authentication mail bodies containing verification/reset/MFA secrets are redacted from `mail_logs`; only safe metadata/checksums remain.
- Authenticated SMTP can be configured and tested from the permission-protected Admin Settings workspace. The password uses AES-256-GCM at rest, is write-only in the interface, is excluded from old-input and audit data, and safely overrides the `.env` fallback without disabling TLS certificate verification.
- CSV cells beginning with spreadsheet formula/control prefixes receive a leading apostrophe.

No production database or deployment credentials were available in the development environment, so the actual installation still requires preflight, backup verification and post-migration checks.

## 8. Automated validation

GitHub Actions runs:

1. Composer strict validation.
2. Dependency installation.
3. PHP syntax lint on PHP 8.1, 8.2 and 8.3.
4. Clean MySQL/MariaDB schema import (65 tables).
5. Production Seeder integrity checks.
6. Existing-install baseline migration dry-run/apply/idempotency checks for migrations 002–005, including attempt fields, replacement uniqueness and self-lineage.
7. Admission lifecycle, targeted correction, eligibility and immutable snapshot revision workflow checks.
8. Public, applicant, staff, admissions, reports, CMS and PDF HTTP smoke routes on MySQL 8.0 and MariaDB 10.4.
9. Reapplication ownership, latest-attempt and open-cycle gates; source immutability; copied/reset data; configuration version; duplicate-attempt prevention; Save & next; and automatic replacement revision checks.
10. Draft-workspace HTTP mutations covering programme assignment, eligibility-rule edit, form-field option/conditional-rule edit, capacity/seat edit, fee edit and document requirement creation.
11. Reviewer assigned-record success and unassigned application/generated-document 403 checks.
12. Admin SMTP settings rendering, encrypted private password storage, blank-password preservation, audit/HTML secret exclusion and test-route delivery-mode validation.

Authoritative green run: `37144194979` at commit `778c66a`.

Manual production acceptance should additionally cover real SMTP, institution payment instructions, representative uploads, backup restore, mobile/tablet browsers and the institution's exact reservation/eligibility policy.

## 9. Migration procedure

From an XAMPP shell in the project directory:

```bash
php scripts/admissions-preflight.php
php scripts/migrate.php --dry-run
php scripts/migrate.php --confirm=APPLY --backup-confirmed
```

Before apply:

1. Put the application behind the migration runner's maintenance control.
2. Take an encrypted database and `storage/private` backup.
3. Restore that backup to a separate database and verify it.
4. Review the dry-run plan and available disk space.
5. Apply once, then rerun the command and confirm it reports up to date.
6. Sign in as super-admin and verify Admissions, one public cycle, one applicant, one protected document and reports.

Never import `database/schema.mysql.sql` over an existing database.

## 10. Rollback and recovery

MySQL DDL auto-commits. The preferred rollback is a forward fix while retaining all additive tables and columns. See:

- `database/migrations/002_admission_management.rollback.md`
- `database/migrations/003_submission_snapshot_revisions.rollback.md`
- `database/migrations/004_cms_page_builder.rollback.md`
- `database/migrations/005_application_reapply_attempts.rollback.md`

Do not drop admission tables or delete application/snapshot records in production. If an upgrade fails, keep maintenance mode active, capture the error and schema state, restore only from the verified backup when a forward fix is not viable, and reconcile uploaded private files created after that backup.

## 11. Known limitations / deployment decisions

- Payment processing remains the repository's existing manual proof-and-verification architecture; no external gateway was invented.
- Programme catalogue records referenced by published cycles are intentionally not destructively edited or deleted in this workspace. Create a new programme record or duplicate a cycle when historical meaning would change.
- Seat matrix capacity cannot be reduced below filled seats. Only empty draft rows can be deleted; rows with allocations are intentionally retained.
- Automated tests use MySQL and HTTP/PDF smoke checks. Pixel-level cross-browser screenshots were not available in the sandbox and must be completed during deployment acceptance.
- Live SMTP, DNS, storage permissions, cron/backup scheduling and the institution's production database were not available for certification.
- Institution-specific statutory approval, affiliation, reservation, refund and Aadhaar/legal-basis content must be approved by authorized institutional/legal owners before launch.
