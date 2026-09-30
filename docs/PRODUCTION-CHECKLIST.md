# Production launch checklist

The software foundation cannot certify institutional, legal, payment, regulatory, or security readiness by itself. An authorised owner must sign off each area.

## Identity and statutory content

- [ ] Replace the demonstration name, address, contacts, staff profiles, images where required, and all placeholder domains.
- [ ] Publish only verified university affiliation, PCI/AICTE approvals, sanctioned intake, programme recognition, and validity dates.
- [ ] Obtain formal approval for admissions copy, eligibility, age, reservations, quotas, seat matrix, fee/refund rules, deadlines, declarations, and letter templates.
- [ ] Confirm the 2027–28 cycle remains Draft until all owners sign off.

## Privacy and Aadhaar

- [ ] Obtain qualified Indian legal/compliance review for the privacy notice and DPDP obligations.
- [ ] Document the lawful purpose and authority for every sensitive field.
- [ ] Do not enable Aadhaar merely because the system supports it. Confirm whether collection is permitted, at which stage, who may view it, retention, masking, and deletion.
- [ ] Publish applicant rights/contact routes and a breach-response process.
- [ ] Verify consent versions and data-retention settings.

## Infrastructure

- [ ] Deploy with the web root pointing only to `public/`.
- [ ] Use supported PHP/MySQL versions and install Composer dependencies with `--no-dev --optimize-autoloader`.
- [ ] Set `APP_DEBUG=false`, a unique `APP_KEY`, `APP_ENV=production`, exact HTTPS `APP_URL`, and `SESSION_SECURE=true`.
- [ ] Use HTTPS with automatic certificate renewal and HSTS at the proxy/web-server layer.
- [ ] Restrict database and filesystem permissions; never expose `.env`, `storage/`, logs, or backups.
- [ ] Configure authenticated SMTP; enable staff MFA only after testing OTP delivery and recovery.
- [ ] Configure PHP upload/body limits consistently with the application limit.
- [ ] Add malware scanning for uploads if required by the institution’s risk assessment.

## Access and operations

- [ ] Create named staff accounts; do not share Super Admin credentials.
- [ ] Review every role and permission using least privilege.
- [ ] Separate admission decisions, document checks, and payment verification where staffing permits.
- [ ] Test suspended users, password resets, throttling, session expiry, staff MFA, and audit visibility.
- [ ] Review logs without storing plaintext identity/payment secrets.

## Backup and recovery

- [ ] Create an encrypted backup and download it to separate protected storage.
- [ ] Keep the matching `APP_KEY` in a secrets vault separate from the archive.
- [ ] Test restoration on an isolated server; record recovery time and responsible staff. The guarded CLI is `php scripts/restore-backup.php /path/to/archive.zip.enc --confirm=RESTORE`.
- [ ] Set `BACKUP_RETENTION_DAYS` and schedule `php scripts/scheduled-backup.php` outside the web request. On Windows/XAMPP, use Task Scheduler with `C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\netaji-hub\\scripts\\scheduled-backup.php`; on Linux, use cron. The command uses a non-blocking lock, returns a non-zero failure code, and prunes only completed archives older than the configured period.
- [ ] Copy each current encrypted archive to separate protected storage and monitor task exit codes/logs; local retention is not an off-site backup.
- [ ] Define retention and secure deletion for backups and applicant records.

## Workflow acceptance tests

- [ ] Registration → verification → sign-in → draft save → upload → final submit.
- [ ] Reviewer assignment → document verification → correction request → resubmission.
- [ ] Manual payment proof → accounts verification → receipt.
- [ ] Selection → offer letter → fee verification → admission confirmation → enrollment record.
- [ ] Applicant support ticket → staff reply → applicant notification.
- [ ] CMS translation fallback and public page publishing.
- [ ] CSV/PDF output, print layouts, mobile layout, accessibility keyboard flow, and browser compatibility.

## Automated preflight

Run after configuration:

```bash
php scripts/preflight.php
```

Every automated check should pass. This supplements—not replaces—the manual review above.
