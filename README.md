# Netaji College of Pharmacy — Admission & College Data Hub

A no-Node.js, XAMPP-ready PHP/MySQL foundation for a configurable pharmacy-college website, online admissions, applicant/student portal, administration dashboard, multilingual CMS and future college ERP modules.

**Developer handover:** Read [docs/DEVELOPER-HANDBOOK.md](docs/DEVELOPER-HANDBOOK.md) for the complete technical architecture, request lifecycle, database map, security model, workflows, extension rules, testing strategy, known boundaries and approved Phase 2 scope.

> **Netaji College of Pharmacy is an editable demonstration identity in this repository.** Seeded content does not claim real affiliation, recognition, PCI/AICTE approval, sanctioned intake, faculty employment, or current admission rules.

## Visual preview

If you are viewing the Arena live preview:

- `/preview.html` — public college website
- `/student-preview.html` — applicant/student dashboard
- `/admin-preview.html` — administration dashboard

These three files contain mock preview data only. The working PHP app starts at `public/index.php` after installation.

## Implemented Release 1

- Responsive pharmacy/science-themed public website with original generated imagery.
- Public programme, admissions, facilities, faculty, notices, gallery, FAQ, contact, privacy and terms pages.
- English/Bengali/Hindi interface dictionary foundation and CMS translation records with English fallback.
- Email-verification registration, password recovery, login throttling and optional staff email OTP.
- One application per cycle with ranked programme choices.
- Personal, address, guardian, Class 10/12, entrance exam, programme preference, declaration and secure document workflows.
- Draft, submission, assignment, review, correction, decision, fee verification and admission state history.
- Granular roles/permissions for Super Admin, Admissions, Reviewer, Accounts, Principal, HOD, Faculty, Librarian, Exam Cell, Office Staff, CMS, Support, Auditor and Applicant.
- Applicant dashboard, document status, configured-fee payment proof, receipts, notifications and threaded helpdesk tickets.
- Admin dashboard, filtered application queue, automated eligibility flags with manual final decisions, document/payment decisions, staff notes, reporting, CSV export, users overview, settings, audit and mail log.
- CMS CRUD for notices, faculty, facilities, FAQs and gallery images, with manually maintained Bengali/Hindi translations and English fallback.
- Public notice filtering/detail pages and a staff processing inbox for public contact enquiries.
- Printable/Dompdf-ready application summary, cover sheet, acknowledgement, correction memo, offer letter, admission confirmation and payment receipt.
- Protected local storage, MIME/size checks, random paths, SHA-256 file checksums and permission-checked streaming.
- Configurable Aadhaar stage, AES-256-GCM field encryption, masking, consent/audit/retention foundation.
- Browser installer that creates a clean database, roles, permissions, B.Pharm, draft 2027–28 cycle, CMS content and optional demonstration records.
- Admin-triggered and lock-protected scheduled encrypted backup archives containing SQL + protected files, with configurable retention.
- Production preflight, guarded restore and scheduled-backup commands plus launch checklists.

## Stack

- PHP 8.1+
- MySQL 5.7+/MariaDB 10.4+
- Apache/XAMPP
- Composer: PHPMailer + Dompdf
- Responsive CSS + vanilla JavaScript
- **No Node.js, npm, bundler, or Node server**

## Quick installation

See [docs/XAMPP-INSTALL.md](docs/XAMPP-INSTALL.md).

```bash
composer install --no-dev --optimize-autoloader
```

Then start Apache/MySQL and open:

```text
http://localhost/netaji-hub/public/install/
```

The installer checks PHP extensions, creates a new database, seeds content, writes `.env` outside `public/`, and locks itself after success.

## Demonstration accounts

Only when **Add demonstration data** is checked during installation:

| Role | Email | Password |
|---|---|---|
| Admission Officer | `admissions@demo.test` | `DemoOfficer#2027` |
| Accounts Officer | `accounts@demo.test` | `DemoAccounts#2027` |
| Applicant | `ishita@demo.test` | `StudentDemo#2027` |

Never enable demonstration data on a real installation. The Super Admin credentials are created by the installer and are not hard-coded.

## Repository map

```text
app/Core/                 Router, database, auth, CSRF, config, encryption, views
app/Controllers/          Public, authentication, applicant and staff workflows
app/Services/             Uploads, mail, audit and encrypted backups
config/                   Application, database, mail and security configuration
database/                 MySQL schema and configurable seeder
public/                   Only intended web root; front controller and assets
public/install/           Locked first-run browser installer
resources/lang/           EN/BN/HI interface dictionaries
resources/views/          Public site, portals, documents and errors
routes/web.php             GET/POST routes and permission middleware
scripts/preflight.php     Production configuration check
scripts/scheduled-backup.php  Locked CLI backup and retention command
scripts/restore-backup.php    Guarded encrypted-backup restoration
storage/                  Private uploads, logs, sessions and encrypted backups
docs/                     Architecture, XAMPP, privacy and launch guidance
```

## Production status

This repository is a substantial Release 1 implementation, but **no generic software can be declared production-ready without environment, institution and workflow acceptance**. Before real use:

1. Complete every item in [docs/PRODUCTION-CHECKLIST.md](docs/PRODUCTION-CHECKLIST.md).
2. Replace all fictional/placeholder content and legally review admissions/privacy/Aadhaar settings.
3. Configure HTTPS, SMTP, staff MFA, permissions, monitoring and externally stored tested backups.
4. Run `php scripts/preflight.php`.
5. Perform security review, accessibility QA, browser/device QA, performance/load tests and a full user-acceptance test with Admissions, Accounts and management.

Release 1 admissions and website/CMS workflows are implemented. The sidebar items explicitly labelled **Phase 2** (full academic operations and fee-ledger management) are out of Release 1 scope and remain disabled rather than posing as working modules.

## Documentation

- [Complete developer handbook and Phase 2 handover](docs/DEVELOPER-HANDBOOK.md)
- [Architecture](docs/ARCHITECTURE.md)
- [XAMPP installation](docs/XAMPP-INSTALL.md)
- [Production checklist](docs/PRODUCTION-CHECKLIST.md)
- [Privacy/Aadhaar note](docs/PRIVACY-AADHAAR-NOTICE.md)
