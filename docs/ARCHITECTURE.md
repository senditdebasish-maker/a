# Architecture

## Stack

- PHP 8.1+ custom MVC/front-controller architecture
- MySQL/MariaDB with PDO prepared statements
- Apache/XAMPP rewrite rules
- Server-rendered HTML, responsive CSS, and small vanilla JavaScript enhancements
- Composer libraries: PHPMailer and Dompdf
- No Node.js runtime, build process, or Node server

## Request flow

`public/index.php` loads environment configuration, starts the secured session, applies response headers, registers routes, checks CSRF/middleware, and invokes a controller. Controllers use PDO services and render templates in `resources/views`.

## Security boundaries

- `public/` is the only intended web root.
- Applicant documents, payment proofs, logs, and backups stay under `storage/`.
- `FileController` streams private files only after owner/permission checks and path canonicalisation.
- Passwordless sign-in uses six-digit email codes stored only as password hashes; the legacy password hash column holds an unshared compatibility value.
- Configured identity values use AES-256-GCM through the application key; lists display only the last four digits.
- POST routes require a session CSRF token.
- Email-code attempts are limited, expire quickly and are auditable.
- Privileged workflows use role/permission checks and audit records.
- Every applicant, student and staff session requires an expiring hashed email OTP.

## Core domains

1. **Identity and access** — users, expanded roles, granular permissions, email verification and passwordless email-code sign-in.
2. **Admissions** — sessions, cycles, programmes, rules, seat matrix, preferences, application state history, assignments, notes.
3. **Evidence** — configurable document checklist, protected uploads, integrity checksums, review decisions.
4. **Finance hand-off** — manual UPI/bank/cash proof, separate Accounts verification, receipt number.
5. **Communication** — notifications, SMTP/log email, templates foundation, support tickets.
6. **CMS** — public pages, translations with English fallback, notices, faculty, facilities, gallery, FAQ and media schema.
7. **Governance** — consents, encrypted identifiers, audit log, retention settings, encrypted backup history.
8. **Shared college foundation** — departments, programmes, academic sessions, users, files, settings and notifications are designed to be reused by later academic/finance/campus modules.

## State model

`draft → submitted → under_review → correction_required → submitted/under_review → approved/selected or rejected → fee_verified → admitted`

Every transition creates an immutable history entry. Correction/rejection remarks are mandatory in the controller. Admission creates a student enrollment record without a second account.

## Important extension points

- Add schema versions under `database/` and record them in `schema_migrations`.
- Add storage drivers behind `UploadService` for S3-compatible storage later.
- Configure SMTP through `MailService` before enabling sign-in for real users.
- Add academic modules against existing departments, programmes, academic sessions, identities and RBAC rather than duplicating master data.
