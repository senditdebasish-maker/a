# Privacy and Aadhaar implementation note

This is a technical design note, not legal advice.

The product makes the Aadhaar collection stage configurable because an institution should not assume that collecting Aadhaar during an initial application is automatically permitted or necessary. The seeded setting is `configurable`; the administrator receives a warning before enabling collection.

Technical controls included:

- AES-256-GCM encryption for a configured government ID value.
- Separate last-four field for masked operational display.
- Private document storage outside direct public access.
- Permission-checked file streaming.
- Consent records with type, purpose/version, time and network address.
- Role-based access and audit events.
- Configurable retention setting foundation.

Before collecting real values, the deploying institution should document:

1. The specific purpose and authority for collection.
2. Whether the number is required at all, and whether document verification without retaining the number is sufficient.
3. The correct stage—application, post-selection, or final admission.
4. The exact roles that may access it and an approval process for unmasking.
5. Notice/consent language, retention, deletion, correction and incident handling.
6. Vendor/hosting/data-processor obligations and cross-system sharing.

Do not put full identity numbers in URLs, filenames, email, logs, CSV exports, support tickets, staff notes, generated document templates, analytics, or backup filenames.
