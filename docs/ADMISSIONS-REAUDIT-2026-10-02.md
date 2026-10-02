# Admission Management Module Re-audit

**Repository:** `senditdebasish-maker/a`  
**Branch:** `arena/01a0f38a-a`  
**Audit started from:** `0b47c4f534f02aa30db108b9db2ab324c1759db1`  
**Tested implementation commit:** `a417016`  
**Audit date:** 2 October 2026  
**Executed CI run:** <https://github.com/senditdebasish-maker/a/actions/runs/37022407628>

## Status definitions

- **PASS** — inspected and exercised by the stated automated test with the expected result.
- **FAIL** — tested and the expected behavior did not occur.
- **PARTIAL** — the core path passed, but not every variant or deployment environment was exercised.
- **NOT TESTED** — not executed; no result is implied.

## Executive result

The relevant automated gate passes on MySQL 8 with PHP 8.1, 8.2 and 8.3. The run includes clean-schema import, Seeder checks, clean and existing-database preflights, guarded migration dry-run/apply/idempotency, all-admin-route registration and permission auditing, lifecycle workflows, dynamic forms, a real multipart protected upload plus a forged-file rejection, fee/payment verification, seat allocation, immutable snapshots, filtered/empty reports, CSV export, public cycle selection, applicant/staff HTTP routes, reviewer IDOR checks and PDF generation.

No schema or migration was changed during this re-audit. No production or applicant record was deleted. Test mutations ran only in the disposable CI databases.

This is **not an unconditional production-readiness declaration**. The tested MySQL 8 build passes, but the actual XAMPP database, backup/restore, filesystem permissions, representative browsers, SMTP and any MariaDB-specific target remain deployment acceptance items.

## 1. Database files and migration audit

### Exact files

| Purpose | File |
|---|---|
| Fresh database schema, 64 tables, migration ledger entries 001–003 | `database/schema.mysql.sql` |
| Production/demo Seeder used after clean import | `database/Seeder.php` |
| Existing-install baseline fixture, 49 tables | `database/baselines/001_initial_schema.mysql.sql` |
| Additive admission management migration | `database/migrations/002_admission_management.php` |
| Immutable submission revision migration | `database/migrations/003_submission_snapshot_revisions.php` |
| Non-destructive recovery guidance | `database/migrations/002_admission_management.rollback.md` |
| Non-destructive recovery guidance | `database/migrations/003_submission_snapshot_revisions.rollback.md` |
| Guarded runner | `scripts/migrate.php` |
| Read-only preflight | `scripts/admissions-preflight.php` |
| Migration fixture/verifier | `scripts/ci-migration-smoke.php` |
| Clean schema/Seeder verifier | `scripts/ci-database-smoke.php` |

### Results

| Feature | Status | Test performed | Result |
|---|---|---|---|
| Fresh MySQL database | **PASS** | `.github/workflows/php-quality.yml` imported `database/schema.mysql.sql` into an empty MySQL 8 database, then ran `scripts/ci-database-smoke.php`. | 64 base tables, Seeder minimum records, super-admin permissions, status history, migration ledger 001–003, configuration versions, snapshot links and seat counters passed. |
| Fresh-database preflight | **PASS** | `php scripts/admissions-preflight.php` after clean import and Seeder. | Step passed without preflight errors. |
| Existing database | **PASS** | Imported the 49-table baseline, seeded a legacy cycle/application/document/sensitive mail record, ran preflight, dry-run, apply, verify and post-migration preflight. | Upgrade reached 64 tables; the legacy cycle was normalized/versioned; the application, preference and document were preserved; document revision, fees, snapshot and snapshot revision were backfilled; sensitive mail body was redacted. |
| Migration idempotency | **PASS** | Re-ran `scripts/migrate.php --confirm=APPLY --backup-confirmed` and required `up to date`. | Passed. |
| Migration checksums and locking | **PASS** | Inspected `scripts/migrate.php`; CI verified the migration 003 checksum ledger. | Advisory lock, maintenance file, backup confirmation, migration checksum and failed-migration non-recording are present. |
| Additive/data-preserving design | **PASS** | Inspected migrations 002/003 and the runner; existing-install fixture was compared after migration. | Migrations add/modify columns, indexes, foreign keys and tables; they do not drop admission/application tables or delete applicant records. |
| Rollback execution | **NOT TESTED** | No destructive rollback was run. | Rollback files are recovery guidance only; this is intentional to preserve records. |
| MariaDB/XAMPP database engine | **NOT TESTED** | CI used MySQL 8.0. | Run preflight, dry-run and a restored backup on the exact XAMPP/MariaDB version before production apply. |
| Actual production database | **NOT TESTED** | No production credentials or snapshot were available. | Deployment remains gated on backup verification and production preflight. |

## 2. Admin routes, permissions and lifecycle

Primary files: `routes/web.php`, `app/Core/Router.php`, `database/Seeder.php`, `app/Controllers/Admin/AdmissionController.php`, `app/Services/AdmissionCycleService.php`, `scripts/ci-route-audit.php`, `scripts/ci-http-smoke.php`.

| Feature | Status | Test performed | Result |
|---|---|---|---|
| All admin route declarations | **PASS** | `scripts/ci-route-audit.php` reflected the registered router table. | Exactly 65 admin routes and 39 admission/application/payment/report admin routes were found; there were no duplicate method/path pairs. |
| Admin handler existence | **PASS** | Route audit resolved every admin controller class/action and required a public method. | Every registered admin handler exists and is public. |
| Admin authentication middleware | **PASS** | Route audit required `auth` on every `/admin` route. | All 65 admin routes passed. |
| Permission declarations | **PASS** | Route audit required exactly one `permission:*` rule per admin route and looked up every slug in the database. | All 65 routes mapped to existing permission records. |
| CSRF on mutations | **PASS** | Route audit verified global POST CSRF enforcement in `app/Core/Router.php`; HTTP mutation tests used real tokens. | Invalid token bypass was not used; tested mutations passed only with session CSRF tokens. |
| Super-admin workflow | **PASS** | HTTP login and admission mutations in `scripts/ci-http-smoke.php`. | Configuration and lifecycle routes succeeded. |
| Reviewer scope/IDOR | **PASS** | Reviewer opened assigned application 2, received 403 for unassigned application 1 and its generated document, 403 for cycle creation, publication and form mutation. | Row-level and permission denials passed. |
| Accounts role | **PASS** | Accounts officer opened admissions/reports and received 403 for cycle creation and close. | Read/finance access remained separate from lifecycle management. |
| Create cycle | **PASS** | HTTP POST `/admin/admissions`. | Draft created with default sections. |
| Edit cycle and configuration | **PASS** | HTTP edits for cycle settings, programme assignment, eligibility create/update, form field create/update/options/conditional JSON, seat capacity, fee and document requirement. | Database-backed edits persisted. |
| Publish | **PASS** | HTTP POST publish after readiness configuration. | Stored status became `published`, version became 1, and the SHA-256 configuration snapshot matched its JSON. |
| Published immutability | **PASS** | Attempted a form-field mutation after publish. | Mutation was rejected with the immutable-configuration warning. |
| Close | **PASS** | HTTP POST close. | Published cycle changed to closed and stopped accepting applications. |
| Archive | **PASS** | HTTP POST archive after close. | Closed cycle changed to archived and became unavailable publicly. |
| Duplicate | **PASS** | HTTP POST duplicate from archived source. | New draft had configuration version 0, copied form configuration, and contained zero applications and zero seat allocations. |
| Guarded configuration-delete endpoints | **PARTIAL** | Routes, ownership checks, draft checks and usage guards were inspected; handlers were included in route/permission audit. | The full delete matrix was not invoked end-to-end in this re-audit. |
| Every non-admission admin mutation | **PARTIAL** | Route/permission/handler checks covered all; representative CMS/support/system pages were HTTP-smoked. | Every one of the 65 admin routes was not individually mutated because the request was an admission-module re-audit. |

Admission route permission families verified in `routes/web.php` include `admissions.view`, `admissions.manage`, `admissions.publish`, `admissions.duplicate`, `admission_forms.manage`, `admission_documents.manage`, `admission_fees.manage`, `admission_seats.manage`, `applications.view`, `applications.review`, `applications.decide`, `applications.correct`, `applications.assign`, `documents.verify`, `payments.view`, `payments.verify`, `reports.view` and `reports.export`.

## 3. Public admission cycle and Apply Now

Primary files: `app/Services/AdmissionCycleService.php`, `app/Controllers/PublicController.php`, `app/Controllers/AuthController.php`, `app/Controllers/ApplicantController.php`, `resources/views/public/home.php`, `resources/views/public/admissions.php`, `resources/views/public/admission-detail.php`, `resources/views/public/program.php`, `resources/views/layouts/public.php`.

| Feature | Status | Test performed | Result |
|---|---|---|---|
| Public cycle filtering | **PASS** | Created and published a currently live cycle, then fetched homepage, admissions listing and detail. After close/archive, fetched the source and duplicated draft directly. | Live published cycle appeared; archived and draft slugs returned 404. |
| Effective status/date enforcement | **PASS** | `AdmissionCycleService` was exercised through public listing/detail and Apply route using server dates. | Only scheduled/live/closing-soon published cycles were public; only live/closing-soon accepted an application. |
| Homepage cycle | **PASS** | HTTP fetched `/` after publishing the live test cycle. | Homepage featured the expected live published cycle. |
| Admission-detail Apply Now | **PASS** | HTTP asserted `register?cycle=ci-editable-cycle`, then authenticated applicant used `/admissions/ci-editable-cycle/apply`. | Correct cycle-specific application was created. |
| Programme Apply link | **PASS** | HTTP fetched `/programs/bachelor-of-pharmacy` and asserted both cycle and programme in its registration URL. | Link targeted the current configured published cycle and programme. |
| Global public Apply link | **PASS** | Inspected `resources/views/layouts/public.php`. | Global link now opens the published-cycle selection page rather than silently choosing an unrelated registration cycle. |

## 4. Applicant configuration and workflow

| Feature | Status | File/test | Result |
|---|---|---|---|
| Dynamic form storage | **PASS** | `ApplicantController::saveCustomFields`; HTTP submitted the configured radio field. | Allowed value persisted in `application_field_responses`. |
| Dynamic options and conditional rule | **PASS** | Admin configured `yes/no` options and a category condition; applicant category `General` rendered and submitted it. | Condition and options were enforced on the tested path. |
| Every supported widget type | **PARTIAL** | Rendering code was inspected; HTTP used radio/custom response and existing standard sections. | Text, textarea, email, telephone, number, date, select, checkbox and multiselect were not each submitted separately in this re-audit. |
| Eligibility | **PASS** | `scripts/ci-admission-workflow.php` ran the seeded subject, marks, category threshold and age rules. | Expected result was exactly `eligible` for one programme; the test no longer accepts any arbitrary eligibility status. |
| Targeted corrections | **PASS** | Workflow requested one section, marked it responded and resubmitted. | Correction status became submitted and a second immutable application snapshot revision was created. |
| Document upload | **PASS** | Real multipart PNG upload through `/student/application/document`. | MIME inspection accepted it, private metadata was stored, and immutable document revision 1 was created. |
| Forged upload rejection | **PASS** | Uploaded text while claiming `image/png`. | Upload was rejected and the document revision remained unchanged. |
| Upload maximum-size boundary | **NOT TESTED** | No oversized multipart fixture was sent. | Size enforcement exists in `UploadService`, but the boundary was not executed in this run. |
| Manual payment submission | **PASS** | Real multipart proof posted with assessed amount and unique reference. | Pending payment stored with the server assessment; client amount had to equal the assessed amount. |
| Payment verification | **PASS** | Admin verified the pending payment. | Payment became verified, assessment became paid and receipt number was created. |
| External payment gateway | **NOT TESTED** | The repository implements manual proof/verification, not a gateway. | No gateway result is claimed. |
| Seat allocation | **PASS** | Workflow evaluated the assigned programme, waived the test assessment, selected the applicant and allocated the OBC-B/state row. | Allocation became reserved and `filled_seats` increased by exactly one. |
| Admission confirmation | **PASS** | Settled the admission assessment and transitioned selected to admitted. | Allocation became confirmed and one enrollment was created. |
| Fee guards | **PASS** | Workflow used application/admission assessments and transitions in `ApplicationWorkflowService`. | Selection/admission required paid or formally waived positive assessments. |
| Configuration snapshot | **PASS** | Publish HTTP test checked SHA-256; workflow checked application snapshot hash and configuration-version ownership. | Configuration and application snapshots matched their JSON and correct cycle/version. |
| Snapshot revisions | **PASS** | Initial seeded revision plus correction resubmission. | Exactly two revisions existed after resubmission; current snapshot remained hash-valid. |
| Unique application/cycle selection | **PASS** | Authenticated Apply Now for the live test cycle. | A distinct application was created for the intended cycle without overwriting the existing application. |

## 5. Reports and exports

Primary files: `app/Controllers/Admin/ReportController.php`, `app/Controllers/Admin/ApplicationController.php`, `resources/views/admin/reports.php`.

| Feature | Status | Test performed | Result |
|---|---|---|---|
| Admissions report page | **PASS** | Admin HTTP smoke of unfiltered report. | Funnel, profile, payment and seat report rendered. |
| Empty filtered report | **PASS** | Requested a duplicated cycle containing no applications. | Returned 200 with empty-state output rather than a runtime error. |
| Filtered CSV export | **PASS** | Requested `/admin/applications/export?cycle=1`. | Returned HTTP 200, `text/csv`, headers and expected cycle application numbers. |
| Spreadsheet formula escaping | **PARTIAL** | Inspected export callback in `ApplicationController::export`. | Cells beginning with formula/control prefixes receive a leading apostrophe; a malicious-cell fixture was not inserted in this re-audit. |
| Pixel/chart visual QA | **NOT TESTED** | No browser screenshot engine was available. | Deployment browser QA remains required. |

## 6. Confirmed defects fixed

Only defects confirmed by inspection plus regression behavior were changed.

1. **Empty report filter runtime failure**  
   - File: `resources/views/admin/reports.php`  
   - Cause: the bar helper used variadic `max()` on an empty data set, the same empty-set pattern previously confirmed on the dashboard.  
   - Fix: compute the maximum with an empty-safe reducer.  
   - Regression: HTTP request for a cycle with zero applications now returns 200 and the empty state.

2. **Programme/global Apply links did not preserve explicit cycle selection**  
   - Files: `resources/views/public/program.php`, `resources/views/layouts/public.php`  
   - Cause: programme CTAs linked to generic registration and displayed a hard-coded cycle label; the global CTA also bypassed cycle selection.  
   - Fix: programme CTAs carry the actual published cycle and programme when open, otherwise lead to its details; the global CTA leads to the database-driven cycle list.  
   - Regression: HTTP assertions verify the expected cycle and programme query parameters and confirm the resulting application belongs to that cycle.

Regression/audit additions are in `scripts/ci-route-audit.php`, `scripts/ci-admission-workflow.php`, `scripts/ci-http-smoke.php` and `.github/workflows/php-quality.yml`.

## 7. Automated test execution

CI run `37022407628` completed successfully:

| Job/step | Status |
|---|---|
| Composer strict validation | **PASS** |
| Dependency installation | **PASS** |
| PHP syntax lint — PHP 8.1 | **PASS** |
| PHP syntax lint — PHP 8.2 | **PASS** |
| PHP syntax lint — PHP 8.3 | **PASS** |
| Fresh MySQL schema import | **PASS** |
| Production Seeder smoke | **PASS** |
| Admin route/permission audit | **PASS** |
| Clean seeded database preflight | **PASS** |
| Existing-install preflight/dry-run/apply/idempotency/verify/postflight | **PASS** |
| Admission workflow invariants | **PASS** |
| Public/applicant/staff/CMS/PDF and admission HTTP workflow | **PASS** |

Local PHP, Composer and MySQL executables were not available in the sandbox. Results above are from the linked GitHub Actions jobs, not fabricated local output.

## 8. Production-data safety and deployment gate

No destructive migration was introduced and no production operation was run. For an existing installation:

```bash
php scripts/admissions-preflight.php
php scripts/migrate.php --dry-run
php scripts/migrate.php --confirm=APPLY --backup-confirmed
```

Before the final command, take and independently restore-test an encrypted database plus `storage/private` backup. Never import `database/schema.mysql.sql` over an existing installation.

### Remaining production acceptance

| Item | Status |
|---|---|
| Automated MySQL 8 admission gate | **PASS** |
| Exact production/XAMPP database preflight and migration | **NOT TESTED** |
| Backup restoration using production-sized data/files | **NOT TESTED** |
| MariaDB-specific compatibility, if XAMPP uses MariaDB | **NOT TESTED** |
| Live SMTP/DNS delivery | **NOT TESTED** |
| Filesystem owner/permission checks on deployed private storage | **NOT TESTED** |
| Desktop/tablet/mobile cross-browser visual acceptance | **NOT TESTED** |
| Institution-approved eligibility, reservation, fee/refund and legal policy | **NOT TESTED** |

**Release decision:** automated acceptance is **PASS** for the tested PHP/MySQL build. Actual production deployment remains **PARTIAL** until the environment-specific items above pass.
