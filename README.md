# WRLC Claims Returned

A multi-institution staff tracker for Claims Returned cases. Each staff login is scoped to one member institution. Staff can open cases, look up patrons and item barcodes in that institution's Alma, assign cases, record four spaced searches, coordinate a partner search using a case-specific link, send patron notices by email, and review an audit history. Alma remains authoritative for circulation actions; this app does not check items in or change charges.

## Requirements

- PHP 8.2+ with `curl`, `pdo_mysql`, `mbstring`, and sessions
- MySQL 8 / MariaDB with the included schema
- HTTPS in production
- A mail transfer service for PHP `mail()`

## Local setup

1. Copy `.env.example` to a file outside the repository and set environment variables in the PHP server or test host. Never put secrets in the repository or web root. Configure a distinct Alma API key for each member; request only the API areas and read/write permissions required for user/item lookup and user-note updates.
2. Create the database and set `DB_DSN`, `DB_USER`, and `DB_PASSWORD`.
3. Install tables: `php bin/install.php`.
4. Add member tenants: `php bin/add-institution.php <slug> "Institution Name" <ALMA_CODE> [partner-email]`.
5. Create each initial administrator: `php bin/create-user.php <slug> admin@example.org admin "Staff Name"`.
6. Configure the member slugs and matching `ALMA_KEY_<SLUG>` environment values. For example, slug `gw-law` maps to `ALMA_KEY_GW_LAW`.
7. Run locally with `php -S 127.0.0.1:8080 -t .` and visit `/login.php`.

When upgrading an existing database, run `php bin/migrate-access-levels.php` once before using the new access levels. Before enabling account deletion, apply `migrations/2026-11-12-user-deletion.sql` once so case and activity history is retained with deleted-user references cleared. Existing `staff` and `viewer` accounts become `user`; existing `admin` accounts remain admins. Run `php bin/migrate-email-only.php` once to convert SMS or combined notice preferences to email when an address exists, otherwise to manual / none. Run `php bin/migrate-partner-notifications.php` once to add the partner email notification preference.

For the first test-server deployment, keep `ALMA_ENABLE_NOTE_UPDATES=false`; validate read access and the case workflow first. Then enable it only after confirming the institution's note type, API permissions, and update behavior in an Alma sandbox. The updater reads the full patron object, preserves the returned note list, appends a private internal note, and sends the full object back because Alma's user update operation can replace user segments. Test this carefully with representative internal and externally managed patron records before production.

## Credentials and multi-institution boundaries

- API keys, database credentials, and `APP_KEY` belong in server environment variables or a secret manager (for Azure, use App Service settings/Key Vault references).
- The repository contains no institution credentials. Never copy the local `key-checker` configuration into this project.
- User accounts are attached to one institution. `user` accounts manage submissions at their institution, `super_user` accounts manage submissions across institutions, and `admin` accounts manage submissions and user accounts. Only admins can open the Admin page.
- Partner links are random, hashed, and limited to partner search status for one case. They reveal only the item/search fields needed by the lending library.
- Use an approved database and retention policy for patron identifiers, contact data, and case history. Back up and restrict database access independently of the web app.
- Notifications are event-triggered and logged by recipient and delivery status. `mail()` requires a configured host mail service. Failed email attempts are recorded; staff should retry or use a manual message.

## Workflow

Opening a case creates Search 1 and schedules the first due date (default seven days). The member's `search_interval_days` controls the default spacing; staff may adjust a case's due date. Completing a search writes its timestamp and staff member, advances the stage, and records the search notes in Alma without emailing the patron. Patron email is sent when a case opens, closes, or reopens. Search 4 advances to final resolution. “Found” and “Not found” close the case and send the final outcome. CLS/P2P/ILL cases can store a partner library and email address; when partner email notifications are enabled, the partner receives the search request, assigned staff contact details, and a scoped case link. Partners are notified when the assigned staff contact changes. Partner library choices include consortium institutions, CLS, P2P, and ILL.

## User accounts

Admins can find accounts by name, email, or institution; look up a staff member by Alma user ID to prefill a new account; assign an institution and access level; disable accounts; and set new passwords. Passwords are stored as one-way hashes and cannot be extracted. Admins reset them by entering a replacement password.

## Azure Web App

Deploy the PHP application to an Azure App Service configured for PHP 8.2+ and connect it to managed MySQL. Set environment values in App Service Configuration / Key Vault, including `SESSION_SECURE=true`, `APP_URL`, database values, member API credentials, mail relay settings. Run schema installation as a deployment step or from a controlled administrative console. Use HTTPS, restrict database network access, enable backups, and configure centralized logs with secrets and patron contact details excluded. App Service local filesystem storage is not used for case data.

## Notes

- This first implementation uses password-based local staff accounts. An institution may later add SSO (OIDC/SAML) behind the same institution-scoped authorization model.
- Partner links are random bearer links, stored only as hashes, and expire after 90 days or when the case closes. They do not grant access to the staff queue.
- Institutional differences such as search intervals, note types, partner messaging, and retention should be agreed by the consortium and configured before rollout.
