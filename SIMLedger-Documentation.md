# SIMLedger — Driver SIM & Contact Management System

## Complete Logic and Workflow Documentation (As-Is)

> This document describes the **current, running behavior** of the Driver SIM & Contact Management System (SIMLedger). It is reverse-documentation of the system as it exists today. It does not propose new features, redesigns, or improvements. Items that could not be fully confirmed from the running system are marked **[TO VERIFY]** and collected in the final section.

---

## Table of Contents

- [System Overview](#system-overview)
- [Actors and Roles (as currently defined)](#actors-and-roles-as-currently-defined)
- [Entities and Data Model (as-is)](#entities-and-data-model-as-is)
- [Core Workflows (as currently implemented)](#core-workflows-as-currently-implemented)
  - [SIM Card Issuance to a Driver](#sim-card-issuance-to-a-driver)
  - [SIM Return, Reassignment, and Deactivation](#sim-return-reassignment-and-deactivation)
  - [Driver Onboarding (including agency-sourced drivers)](#driver-onboarding-including-agency-sourced-drivers)
  - [Driver Offboarding](#driver-offboarding)
  - [Contact Update and Verification](#contact-update-and-verification)
  - [Role and Permission Assignment (group level and user level)](#role-and-permission-assignment-group-level-and-user-level)
  - [Sign-in and Two-Factor Authentication](#sign-in-and-two-factor-authentication)
  - [Excel Export Generation](#excel-export-generation)
  - [Table Interactions (sort, filter, columns, persistence, URL sync)](#table-interactions-sort-filter-columns-persistence-url-sync)
- [Business Rules and Validation (current)](#business-rules-and-validation-current)
- [State and Status Lifecycles (current)](#state-and-status-lifecycles-current)
- [Permissions Matrix (current)](#permissions-matrix-current)
- [Country Access Restriction (GeoIP)](#country-access-restriction-geoip)
- [Notifications and Alerts (current)](#notifications-and-alerts-current)
- [Audit and Logging (current)](#audit-and-logging-current)
- [Error Handling and Edge Cases (current)](#error-handling-and-edge-cases-current)
- [Integration Points (current, logical)](#integration-points-current-logical)
- [External API (driver data sync, current)](#external-api-driver-data-sync-current)
- [Glossary](#glossary)
- [Assumptions [TO VERIFY]](#assumptions-to-verify)

---

## System Overview

**Purpose.** SIMLedger tracks company-owned SIM cards as corporate assets that are issued to drivers in a logistics/transport operation. The system records which driver holds which SIM at any moment, preserves the full history of every assignment, and maintains driver contact information across multiple Saudi Arabian terminals.

**Scope of the current system.**

- Maintains a master inventory of company SIM cards (carrier, terminal, status, ICCID, auto-generated SIM reference).
- Maintains driver master records, including terminal, sourcing agency (in-house RT or outsourced providers such as SMASCO, SRACO, TAMKEEN), Iqama (residency permit) number, and nationality.
- Records every SIM lifecycle transaction — issue, return, transfer, replace, mark lost/damaged/suspended, retire — as append-only assignment history rows inside database transactions.
- Maintains drivers' personal contact numbers (separate from company SIM assets).
- Provides role-based access control: role-level permission groups plus per-user permission overrides, enforced server-side on every page.
- Provides a filtered, sortable, paginated dashboard and list views with Excel (.xlsx) export of any filtered result set.
- Optional sign-in hardening: reCAPTCHA v2 invisible (enforced only when keys are configured, never on localhost) and TOTP two-factor authentication with QR-code enrolment on the Profile page.
- Optional country-based access restriction (GeoIP): with the feature enabled, only requests from an allowlist of countries (default Saudi Arabia) reach the application — resolved from a local MaxMind-DB file, off by default, with an office/VPN IP bypass list (see [Country Access Restriction](#country-access-restriction-geoip)).
- Provides a two-phase CSV import for bulk loading drivers and SIMs.
- Records an append-only audit trail of all significant actions.

**Deployment characteristics.**

- Web application (PHP 8 / MySQL 8) with session-based authentication; default timezone Asia/Riyadh.
- Multi-terminal operation: Dammam (DMM), Riyadh (RUH), Jeddah (JED) are seeded; terminals are configuration data, not hard-coded.
- Terminal scoping: dispatcher accounts are confined to their own terminal; admin and manager accounts see all terminals.
- An installation wizard (`install.php`) creates the database, seeds master data (terminals, carriers, roles, settings), and creates the first administrator account. It is intended to be removed after installation.
- Per-host configuration (database credentials, reCAPTCHA keys, `GEO_*` switches) lives in `config/config.php`; FTP update packages **never** contain `config/`, so deploying an update cannot overwrite a host's settings. New settings ship as `defined()` fallbacks in code and take effect only when the host defines them in its own config.

**Out of scope of the current system** (observed absences, stated as facts): no SMS/email notifications, no HR or agency API integration, no Iqama expiry tracking, no automated contact verification, and no data deletion UI for assignments or audit records.

---

## Actors and Roles (as currently defined)

Roles are database records (not hard-coded); three roles exist by default and additional custom roles can be created.

### 1. System Administrator (role key `admin`)

- Holds **every permission implicitly** — the permission engine short-circuits to "allowed" for the admin role regardless of role or user permission rows.
- Manages users, roles, permission assignments, terminals, carriers, agencies, driver master records, and SIM records.
- Runs all SIM transactions and CSV imports.
- Views the audit log.
- Is not confined to any terminal (sees and acts across all terminals).
- Created by the installer; cannot be deleted through the UI. **[TO VERIFY: no UI path exists to delete any role or user account; only deactivation exists.]**

### 2. Operations Manager (role key `manager`)

- Sees all terminals (not terminal-scoped).
- Default permission grants: view SIM inventory/assignments/history, view and manage driver contacts, view drivers, view/export reports.
- **Cannot** run SIM transactions (issue/return/transfer/replace/mark-lost), import data, or access any administration page with the default grants.
- Can be fine-tuned per account (individual permission overrides).

### 3. Terminal Dispatcher (role key `dispatcher`)

- Confined to one terminal: sees only that terminal's data and may only act on records belonging to that terminal (enforced server-side on every transaction).
- Default permission grants: everything the manager has plus **run SIM transactions**.
- **Cannot** access administration pages, imports, or the audit log with the default grants.

### 4. Custom roles (created via Administration → Roles and Permissions)

- Any number of additional roles can be created (name plus a unique lowercase key such as `senior_dispatcher`).
- A newly created role starts with **no permissions** until an administrator assigns them.
- Users can then be assigned to the custom role; per-user overrides can further tune any account.

### 5. Permission overrides (per user account)

- Every user account, regardless of role, can receive per-permission overrides: **Role default**, **Always allow**, or **Always deny**.
- Precedence: an **Always deny** override wins over an Always allow override, which wins over the role's default grant.
- The admin role always holds everything and cannot be restricted.

---

## Entities and Data Model (as-is)

All entities are stored in MySQL. Relationships below are logical; uniqueness and guard constraints are enforced at the database level.

### Terminal

| Attribute | Notes |
|---|---|
| Code | Unique short code (e.g. DMM, RUH, JED) |
| Name | Unique display name (e.g. Dammam) |
| Active flag | Inactive terminals remain for historical data |

Seeded: Dammam, Riyadh, Jeddah. Every driver, SIM, and assignment belongs to exactly one terminal.

### Carrier

| Attribute | Notes |
|---|---|
| Name | Unique (e.g. STC, Mobily, Zain, Other) |
| Active flag | |

Every SIM belongs to exactly one carrier. Carrier list is filterable per page and used by CSV import matching (case-insensitive by name).

### Agency (driver sourcing provider)

| Attribute | Notes |
|---|---|
| Code | Unique short key (e.g. RT, SMASCO, SRACO, TAMKEEN); used in CSV import matching |
| Name | Unique display name |
| Active flag | |

Seeded: **RT (own drivers)**, SMASCO, SRACO, TAMKEEN. Every driver belongs to exactly one agency; existing drivers are backfilled to RT when the feature was introduced. Agencies are managed on their own admin page with driver counts shown per agency.

### Role

| Attribute | Notes |
|---|---|
| Key | Unique machine key (admin, manager, dispatcher, or custom) |
| Name | Display name |
| Active flag | |
| Permissions | Set of granted permissions (many-to-many via role permissions) |

### Permission

The catalog contains **11 permissions in 6 groups** (fixed in code; displayed from the database):

| Group | Permission key | Meaning |
|---|---|---|
| SIM Management | `sim.view` | Browse SIM lists, assignments and history |
| SIM Management | `sim.transact` | Issue, return, transfer, replace, mark lost |
| Drivers | `driver.view` | Browse driver list and profiles |
| Drivers | `driver.manage` | Create/edit driver master records |
| Contacts | `contact.view` | Browse driver contact numbers |
| Contacts | `contact.manage` | Add, edit, deactivate contact numbers |
| Reports | `report.view` | Open reports and export report files |
| Data | `data.import` | Upload and commit CSV imports |
| Administration | `user.manage` | Users, roles, permission assignments |
| Administration | `reference.manage` | Terminals, carriers, agencies, SIM records |
| Administration | `audit.view` | Read the audit trail |

### User

| Attribute | Notes |
|---|---|
| Username | Unique, max 50 characters |
| Password | Hashed (bcrypt); minimum 8 characters on set/change |
| Full name | Required |
| Role | Required; one role |
| Terminal | Required **only** for the dispatcher role; forced empty for admin/manager |
| Active flag | Deactivated users cannot sign in |
| Last login | Timestamp, updated at each successful sign-in |
| Permission overrides | Per-permission allow/deny rows overriding the role default |

### Driver

| Attribute | Notes |
|---|---|
| Badge number | Unique across the whole system; primary business identifier |
| Name | Required |
| Terminal | Required (foreign key) |
| Agency | Required (foreign key); RT = own company |
| Status | active / resigned / terminated / inactive / transferred |
| Iqama number | Residency permit; optional but unique when present |
| Nationality | Optional free text |
| External driver ID | Optional placeholder field reserved for future HR/Address-Book sync |
| Notes | Optional |
| Active flag | Soft-deactivation marker; deactivated drivers are never deleted |

A driver's **current company SIM is never a field on the driver** — it is derived at read time from the open assignment row.

### Company SIM

| Attribute | Notes |
|---|---|
| SIM reference | Auto-generated identifier (SIM-000123 style) |
| Mobile number (display) | e.g. 05XXXXXXXX |
| Normalized number | Canonical form +9665XXXXXXXX; **unique** — the deduplication key so all input formats map to one SIM |
| ICCID | Optional but unique when present |
| Carrier | Required |
| Terminal | Required |
| Status | available / assigned / suspended / lost / damaged / retired |
| Notes | Optional |
| Created by / Updated by | User references |

A SIM is a company asset; it is never deleted. Status changes go through transactions or the controlled admin status override.

### Assignment (driver ↔ SIM)

The central ledger entity; append-only.

| Attribute | Notes |
|---|---|
| SIM, Driver, Terminal | Terminal is the SIM's terminal at issuance (record-owning terminal) |
| Issued at / Issued by | Timestamp and user |
| Assignment reason | e.g. transfer, replacement on re-opened rows; empty for initial issue |
| Remarks | Optional free text |
| Returned at / Returned by | Set when the assignment closes; NULL = open assignment |
| Return reason | resignation / transfer / vacation / replacement / lost_damaged / sim_change / other |
| Guard columns | Database-generated columns enforce: **one open assignment per SIM** and **one open assignment per driver** |

Closed assignment rows are never updated or deleted by the application other than setting the return fields once.

### Driver Contact Number

Personal numbers owned by the driver — explicitly **not** company inventory.

| Attribute | Notes |
|---|---|
| Driver | Required |
| Contact type | personal / emergency / other |
| Phone (display + normalized) | Normalized to +9665XXXXXXXX; same deduplication approach as SIMs |
| Primary flag | At most one active primary per driver |
| Active flag | Soft deactivation; rows are kept for audit |
| Notes | Optional |
| Created/Updated by | User references |

A normalized number can be active for **only one driver at a time** — duplicate detection runs on every add/edit.

### Audit Log

Append-only event records: timestamp, acting user (id + username snapshot), action, entity type, entity id, old values (JSON), new values (JSON), terminal id, client IP. See [Audit and Logging](#audit-and-logging-current).

### App Settings

Key/value configuration rows; business options are read from settings rather than hard-coded. Complete contents (verified 2026-10-09 against the live database, refreshed 2026-10-10 — 8 rows):

| Key | Read at runtime by | Purpose |
|---|---|---|
| `managers_see_full_numbers` | `includes/auth.php` (full-number visibility policy) | Manager masking override (0/1) |
| `mig_2fa` | `twoFactorBootstrap()` | Migration flag: `users.totp_secret` / `totp_enabled` applied |
| `mig_status_rth_removed` | `migrateSimStatusEnum()` | Migration flag: retired `returned_to_hr` status removed from the ENUM |
| `perm_bootstrap_version` | permission bootstrap | Migration flag: default roles/permissions seeded |
| `mig_api_tokens` | `migrateApiTokens()` | Migration flag: `api_tokens` table created + `api.manage` permission registered |
| `app_name` | *(not read anywhere)* | Seeded by the installer; page titles actually use the `APP_NAME` constant from `config/config.php` |
| `allow_multiple_sims_per_driver` | *(orphaned — not read anywhere)* | Vestigial row from the removed multi-SIM runtime switch; the installer no longer seeds it |
| `mig_status_returned_to_hr` | *(orphaned — superseded by `mig_status_rth_removed`)* | Historical flag from when the `returned_to_hr` status still existed |

The first five keys have runtime readers; the last three are orphaned/historical rows.

> **Note on "Group":** the system has no separate Group entity. Roles serve as the permission groups (group-level assignment), and the six permission categories (SIM Management, Drivers, Contacts, Reports, Data, Administration) are display groupings of the permission catalog.

---

## Core Workflows (as currently implemented)

### SIM Card Issuance to a Driver

**Entry points:** Issue SIM page (sidebar), "Issue SIM" buttons on the driver list / driver profile / available-SIMs list, dashboard quick action.

1. The user (admin or dispatcher by default grant of `sim.transact`) opens the Issue SIM page. The page shows two side-by-side paginated tables (10 rows each): a driver table (left) and an available-SIM table (right), each with its own server-side search box; click a row or **Select** to choose. A live Preview card sits left of the Remarks / Review action bar below the tables. Scope is limited to the user's terminal when the user is a dispatcher.
2. The user selects a driver, selects a SIM, optionally enters remarks, and submits.
3. The controller validates access: the acting user must be permitted to act on **both** the driver's terminal and the SIM's terminal (terminal-scope assertion).
4. **Step 1 — confirmation screen:** the system renders a review prompt ("Assign SIM \<number\> to \<driver\> (Badge \<badge\>)?") with the chosen values. Nothing is written yet.
5. **Step 2 — execution:** the user confirms. The request carries a confirmation flag plus a CSRF token.
6. Inside `SimService::issue()` a database transaction opens and the driver and SIM rows are locked (`SELECT … FOR UPDATE`) to prevent concurrent races. The service validates:
   - the driver is active (`is_active = 1` and status `active`);
   - the SIM status is `available` (a specific message names the current holder if it is assigned);
   - the driver's terminal equals the SIM's terminal;
   - the driver holds no other open assignment (single-SIM rule, enforced again by the database guard).
7. A new assignment row is inserted (open: `returned_at IS NULL`) and the SIM status becomes **assigned**.
8. The transaction commits; an audit event records the issuance; a success flash message is shown and the user is redirected to the SIM's history page.

Concurrency: the database guard columns make it impossible for two open assignments to exist for the same SIM or for the same driver even under simultaneous requests; the loser receives an integrity-violation error surfaced as a friendly message.

### SIM Return, Reassignment, and Deactivation

**Return (SIM comes back from a driver).**

1. From the Return SIM page, the user selects the assignment in a **searchable, paginated table** (10 rows per page; search matches badge, driver name or SIM number). "Return" buttons on the inventory and assignments lists deep-link here with `assignment_id` or `sim_id`; the page resolves the target, lands on the page containing it, and shows it preselected.
2. The controller asserts terminal access on the assignment before touching it.
3. The user enters the return date (must match YYYY-MM-DD), selects a **return reason** from the fixed list (Resignation, Transfer, Vacation, Replacement, Lost/Damaged, SIM Change, Other), optionally enters remarks, and submits.
4. **Step 1 — confirmation screen:** the system shows a review box ("Return SIM \<number\> from \<driver\>?") with the assignment terminal, carrier, date, reason and remarks. Nothing is written yet.
5. **Step 2 — execution:** on confirmation, `SimService::returnSim()` validates the date format, that the reason belongs to the reason list, and that the assignment is still open (an already-closed assignment is rejected with "already been closed"), then — inside one transaction — closes the assignment (`returned_at`, `returned_by`, `return_reason`) and sets the SIM status to **available**. The assignment remains in history forever.
6. Audit event recorded; success flash; redirect to the SIM history page.

**Transfer (reassignment to another driver without the SIM leaving stock).**

1. From the Transfer SIM page, the user selects an open assignment and a receiving driver from **two side-by-side, searchable, paginated tables** (10 rows each — the receiving-driver table lists active, in-scope drivers **without** an active SIM), then enters the effective date, a transfer reason (required), and optional remarks. Selections survive searches and page changes because the whole picker state (queries, pages, selections) is carried in the URL.
2. Step 1 shows a confirmation screen summarizing old driver → new driver.
3. On confirmation, `SimService::transfer()` in one transaction: locks rows, validates the receiving driver is active/eligible and holds no other open SIM, **closes** the previous assignment (reason `transfer`), and **opens** a new assignment on the **same SIM** for the new driver. The SIM never re-enters available stock mid-flow and keeps its history contiguous.
4. Terminal/custody rule: access is enforced per terminal. A dispatcher may act on records of their own terminal **and on assignments whose current holder belongs to their terminal** (physical custody — the same rule applies to return, replace and mark-lost, and their assignment pickers show both). Admins act everywhere. Cross-terminal transfers (admin) are intended behavior (acceptance scenario 3): the new assignment keeps the SIM's terminal as the record-owning (book) terminal, and the SIM's inventory ownership is changed only explicitly by an admin editing the SIM record's terminal.
5. Audit event; redirect to SIM history.

**Replace (damaged/old SIM retired, new SIM issued).**

1. From the Replace SIM page the user selects the open assignment and a replacement SIM from **two side-by-side, searchable, paginated tables** (10 rows each; the replacement table lists only **available** SIMs — the chosen SIM must also be in the **same terminal** as the current assignment), then enters a reason and optional remarks.
2. Confirmation screen, then `SimService::replace()` in one transaction: the old assignment closes (reason `replacement`), the old SIM is marked **damaged**, and a new open assignment is created on the replacement SIM.
3. Audit event; redirect to the new SIM's history.

**Mark lost / damaged / suspended.**

1. From the Mark Lost page the user first selects the assigned SIM in a **searchable, paginated table** (10 rows per page; search over number, driver name or badge; `?sim_id=` deep links preselect the row), then selects a target status (Lost, Damaged, or Suspended), a valid date, a reason, and remarks — **both reason and remarks are required** by the controller.
2. The service closes the open assignment and sets the SIM's status accordingly, inside a transaction.
3. A suspended, lost/damaged or returned-to-HR SIM cannot be issued again until an admin changes its status.

**Admin status override.** On the SIM Inventory page an admin can directly change the status of an unassigned SIM (e.g. suspend, resume to available, mark lost/damaged, retire). Leaving the `assigned` state always goes through a closing transaction, never a raw status write (an assigned SIM must be returned/replaced first). Retire is terminal: retired SIMs leave the issuable pool. *The former `returned_to_hr` status was removed on 2026-10-10; a migration strips it from existing ENUMs when no rows use it.* The modal's **Reason** field is a one-off justification for that single status change and is stored with its audit entry (transactional paths — retire, mark lost/damaged — also carry it into the closing assignment record); it is not written to the SIM record. The same Actions column also offers **Notes…** — an admin modal that edits the SIM's persistent `notes` field (`update_notes` action, audited with old/new values); notes editing is independent of status changes.

### Driver Onboarding (including agency-sourced drivers)

**Manual creation (admin).**

1. An admin opens Driver Management and fills the create form: badge number (required, unique), name (required), terminal (required), **agency (required** — pick RT for in-house or an outsourced provider), status (defaults to active), optional Iqama number (unique when present), optional nationality, optional external ID and notes.
2. On save the driver master row is created; an audit event is written.
3. Errors map database uniqueness violations to friendly messages (duplicate badge **or** Iqama).

**Bulk onboarding via CSV import.**

1. The admin opens Import Data (permission `data.import`) and chooses the **drivers** type.
2. Expected headers: `Badge Number, Driver Name, Terminal, Status, Personal Mobile` plus optional `Agency, Iqama, Nationality`.
3. **Phase 1 — upload & preview:** the file is parsed and each row is classified as **valid**, **duplicate**, or **invalid** with per-row reasons (missing badge/name, unknown terminal, unknown agency, invalid mobile number, duplicate badge). Nothing is written.
4. **Phase 2 — confirmation:** the admin re-selects the same file and confirms. Only rows classified valid are inserted, in one transaction; personal mobile numbers (when valid) are attached as the driver's primary contact. Duplicates/invalid rows are skipped and counted in the result message.
5. An audit event records the import with committed/total counts.

Agency-sourced drivers (SMASCO/SRACO/TAMKEEN) use exactly the same flows; the agency field distinguishes sourcing only and does not change any SIM workflow.

### Driver Offboarding

1. Offboarding is a **soft deactivation**: on Driver Management the admin presses Deactivate (with a confirmation prompt warning that SIM history is preserved). The driver's `is_active` becomes 0 and an active status is moved to `inactive`.
2. Deactivated drivers can no **longer** be selected for new SIM issuance (eligibility check fails with their status).
3. If the driver still holds a company SIM, the system warns (dashboard alert and driver-profile banner) and **requires a documented reason**: the deactivate action is rejected with an error naming the held SIM until it is returned, transferred or reported — unless the admin enters an explicit deactivation reason in the prompt. A deactivation performed with an outstanding SIM stores the reason and the held SIM's number in the audit event.
4. Assignment history and contact rows are preserved; there is no hard delete of drivers anywhere in the UI.

### Contact Update and Verification

Contact numbers are managed on the driver profile page (permission `contact.manage`).

1. **Add:** the user opens the contact dialog on the profile, enters a phone number (any Saudi format: 05XXXXXXXX, +9665XXXXXXXX, 9665XXXXXXXX — all normalize to the same canonical value), a type (personal/emergency/other), an optional primary flag and notes.
2. **Duplicate check:** the system rejects the number if it is already an active contact of **any other driver**, naming that driver's badge in the error.
3. **Primary flag:** marking a number primary clears the primary flag on the driver's other numbers in the same operation.
4. **Edit / deactivate:** contacts are edited in place or soft-deactivated (is_active = 0); deactivated rows remain for audit purposes.
5. Every change writes an audit event with old/new values.

**Verification:** the current system contains **no verification workflow** (no confirmation codes, no call/SMS verification, no verification status). **Coverage reporting** (Contact Coverage report, dashboard alert) measures whether a driver is **reachable** — an active personal contact number **or** an open company-SIM assignment counts — not whether a number was verified. The report's summary cards, its Reachable / Not-reachable filter, the detail table's *Reachable* column, the Excel export, and the dashboard alert all use this same definition; the two ingredients are still shown separately everywhere (personal numbers belong to the Contacts domain, SIMs to SIM Management).

**Masking:** personal contact numbers are masked as `055****567` for users without full-number visibility: **admins always see full numbers; dispatchers see full numbers only for their own terminal; managers are masked by default** (the setting `managers_see_full_numbers` can widen this). The Drivers Without SIM report and the Driver Contacts Excel export apply exactly the same policy as the on-screen views. Company SIM numbers are asset identifiers and are shown unmasked on every screen and export, subject to the same terminal scoping.

### Role and Permission Assignment (group level and user level)

**Group level (Administration → Roles and Permissions, permission `user.manage`).**

1. The page lists roles with user counts and granted-permission counts (admin shows "all").
2. **Create role:** name plus unique lowercase key (3–30 characters). The new role starts with zero permissions and the editor opens for it.
3. **Edit role:** rename and tick/untick permissions grouped into the six categories. Saving replaces the role's entire permission set atomically. An informational note states the admin role always holds everything.
4. Audit events: role creation and role update (with granted-permission count).

**User level (Administration → Users, edit form).**

1. Each of the 11 permissions shows a three-state control: **Role default (granted/denied)**, **Always allow**, **Always deny**. The role default state is displayed inline.
2. Saving the user replaces the account's override rows: only non-default choices are stored.
3. Effective access = deny override → else allow override → else role grant. Changes take effect on the user's next request (the session re-reads the user and permissions per request).

### Sign-in and Two-Factor Authentication

1. **Credentials.** The user submits username and password on the sign-in page. When reCAPTCHA v2 (invisible) keys are configured in `config/config.php`, the invisible widget runs first and the server verifies the token against Google **before** the password check. Verification is fail-closed: a missing/invalid token or an unreachable Google service rejects the attempt. The captcha is automatically skipped on `localhost` / `127.0.0.1` / `::1` (Google does not accept localhost site keys), and with empty keys the feature is entirely off — sign-in behaves exactly as it always did.
2. **Optional TOTP step.** If the account has two-factor enabled (self-service on the Profile page), a valid password does **not** open the session: a pending step (5-minute expiry, session id regenerated) is stored and a 6-digit code form is shown instead. **Sign-in reminder:** while an account has *no* 2FA, every page after sign-in carries a dismissible banner nudging the user to enrol (`two_factor_reminder` session flag set during login, live-checked against `users.totp_enabled`; **Dismiss** clears it for the session, enabling 2FA clears it permanently, an admin Reset 2FA takes effect at the user's next sign-in; never shown while impersonating).
3. **Code verification.** A correct time-based code (RFC 6238: SHA-1, 6 digits, 30-second period, ±1 step window for clock drift) completes the sign-in — fresh session id, `LOGIN` + `LOGIN_2FA` audit events, redirect to the dashboard. Wrong codes are rejected with an attempts counter; after **10 failed attempts** the pending step is dropped and the user must start over. "Back to sign in" (`?cancel_2fa=1`) clears a pending step; the next sign-in still challenges while 2FA remains enabled.
4. **Enrolment (Profile → Two-Factor Authentication).** *Enable* generates a 16-byte base32 secret held in a pending state; the profile then shows a QR code — an `otpauth://TOTP/SIMLedger:<username>` URI rendered **locally** by the vendored MIT `qrcode.min.js`, so the secret never leaves the page — plus the grouped manual key. *Confirm* requires a valid code before the account switches on (`ENABLE_2FA` audit). *Disable* also requires a current valid code (`DISABLE_2FA` audit). *Cancel enrolment* discards the pending secret.
5. **Recovery.** No backup codes exist: an admin can reset a user's enrolment from Administration → Users (**Reset 2FA** button, shown only for enrolled users), clearing the secret and flag (`RESET_2FA` audit).
6. **Account recovery (Administration → Users).** Every account row carries three admin recovery actions: **Reset Password** mints a one-time temporary password (`Reset-<8 hex>`) shown exactly once, sets `users.must_change_password` (`RESET_PASSWORD` audit) and forces the user through the profile's Change Password form before any other page at their next sign-in (`CHANGE_PASSWORD` audit clears the flag; a *Must change password* badge marks the row meanwhile); **Login as** opens the target user's session directly from the admin account — a top banner identifies the impersonation and returns to the administrator through the CSRF-protected `impersonate.php` (`LOGIN_AS` / `EXIT_LOGIN_AS` audits, session id regenerated both ways); **Reset 2FA** (item 5). The `must_change_password` column self-provisions on older databases through `userAdminBootstrap()` (flagged `mig_user_admin`).

7. **Schema.** Existing databases gain `users.totp_secret` / `users.totp_enabled` through the self-provisioning `twoFactorBootstrap()` migration (flagged `mig_2fa` in `app_settings`); fresh installs receive them from `sql/schema.sql`.

### Excel Export Generation

1. Any list or report page with an "Export Excel" button exports the **currently filtered result set** (the button URL carries the active filters; the sort order of the on-screen query is also applied on the paginated pages).
2. The server re-runs the filtered query without pagination limits and produces an **.xlsx** workbook containing:
   - Row 1: large bold merged title (page/report name);
   - Row 2: subtitle with generation timestamp and row count;
   - Row 3: bold, filled, bordered column headers;
   - Data rows: bordered cells; numeric-looking safe values stored as numbers; phone/ICCID-style values kept as exact text (leading zeros preserved);
   - Frozen header pane and auto-sized columns.
3. The file downloads with a page-specific name and timestamp (e.g. `drivers-20261008-120000.xlsx`).
4. Thirteen export endpoints exist: SIM inventory, available SIMs, current assignments, drivers, driver contacts, SIM history (both the global feed and the per-SIM chain of custody), five reports, and the audit log. Exports always return **every** matching row even though the on-screen lists are paginated (25 per page). Export requires the same page permission as viewing the page. If the server lacks ZIP support the exporter falls back to CSV (defensive fallback).
5. **Field-level masking matches the screen.** Exports use the same authorization policy as the views they come from: personal contact numbers appear masked in the Driver Contacts and Drivers Without SIM exports for users without full-number visibility (see Masking above). The audit export is limited to holders of the audit permission (admin by default).

### Table Interactions (sort, filter, columns, persistence, URL sync)

Applied automatically to every data table in the application:

- **Row index (`#`) column.** Injected as the first column; numbers continue across pagination (page 2 starts at 26 when 25 rows per page).
- **Show/hide columns.** A "Columns" dropdown (per table) lists every column with a checkbox. The visibility choice is stored per page in the browser (localStorage) and **persists across sessions and page reloads**. Headers marked `data-col-default="hide"` — the attribution columns *Issued By*, *Returned By* and *Performed By*, plus the internal *SIM ID* reference and the *Notes* column on SIM Inventory — start **hidden** on the first visit (no saved preference yet); from then on the saved user choice always wins over the default, and unchecking/checking any column saves the full state. Excel exports are unaffected and always contain every column.
- **Sorting.** Clicking a header sorts the table (indicator arrows show direction):
  - *Server-side sorting* on paginated lists (SIM Inventory, Available SIMs, Current Assignments, Drivers, Contacts, SIM History, the administration lists and all five report pages): the sort key and direction are written to the URL (`?sort=<key>&dir=asc|desc`), the page resets to page 1, and the database sorts the **entire** filtered set across all pages. Column sort keys are allow-listed server-side. Every full list table is paginated (25 rows per page); the picker tables on the transaction pages are the deliberate exception (10 rows per page, unsortable headers — see below).
  - *Client-side sorting* on all non-paginated tables: rows are re-ordered in the browser (numeric-aware comparison; empty values sort last; the `#` column renumbers afterwards) with the same URL sync.
- **Persistence and shareability.** The URL is the source of truth: sorted views are bookmarkable and shareable. When the URL carries no sort, the toolkit restores the user's last-used sort for that page from the browser.
- **Filtering without page reload.** Filter submits, pagination clicks and server-side header sorts refresh **only their table region** via an AJAX request — the toolkit swaps any container marked `data-tt-partial` (the legacy `form.filter-bar` + `#table-partial` pattern is still honoured unchanged). Filter forms name their region with `data-tt-target` and their pagination parameter with `data-tt-page` (default `page`), which lets pages with two independent pickers refresh one table while the other picker's query, page and selection survive in the URL. The browser URL is updated with the new filter parameters (pushState) and back/forward re-applies previous states; any failed request falls back to normal full-page navigation. Wired pages: SIM Inventory, Available SIMs, Current Assignments, Drivers, Contacts, SIM History (search + server sort), all five reports, the Audit Log, the admin Drivers and SIM Records lists, the Mark Lost assignment picker, and both picker tables on Transfer / Replace.
- **Picker tables (transaction pages).** The Issue, Return, Transfer, Replace and Mark Lost pages render their selection lists as scoped picker instances of the same table component (shared `assets/js/picker.js`): a server-side search box, 10 rows per page with the standard pagination control, plus the `#` column and the Columns menu. Their headers are deliberately **not sortable** (`data-no-sort`). On the dual-picker pages (Transfer, Replace) and Mark Lost each picker card is its own AJAX region (`data-tt-target` / `data-tt-page`): searching or paginating one picker refreshes only that card while the other picker's query, page and current selection survive in the URL; a failed request falls back to a normal page load that carries the whole picker state in the URL. Clicking a row (or its **Select** button) highlights the row, flips the button to `✓ Selected`, fills the card's "Selected: …" status line, and writes the choice into hidden form inputs that are restored after every reload. On two-picker pages (Transfer, Replace) both choices sit side by side; the Issue page additionally mirrors the selections into its live Preview card. The primary action button stays disabled until every picker has a selection — an additive UI guard only: server-side validation and the two-phase confirmation are unchanged.

---

## Business Rules and Validation (current)

**SIM asset integrity**

1. A SIM can have at most one **open** assignment (database-enforced via a generated guard column and unique index).
2. A driver can hold at most one open company SIM — enforced in the database (generated guard column + unique index) **and** re-checked in the application service layer before issue and transfer. There is no runtime switch to relax this; enabling multiple SIMs would require a coordinated schema change.
3. Issuance requires SIM status `available` and an active driver; driver terminal must equal SIM terminal.
4. A SIM is never deleted; history rows are never removed. Returns/transfers only close rows.
5. Normalized mobile numbers are globally unique; ICCID is unique when present; SIM references are auto-generated.
6. Retired SIMs cannot be issued. Suspended/lost/damaged SIMs require an explicit admin status change back to available first.
7. Replacement SIMs must be available and share the terminal of the assignment being replaced.

**Driver integrity**

8. Badge numbers are unique across the entire system.
9. Iqama numbers are unique when present.
10. Terminal and agency are mandatory on driver records.
11. Only drivers with status `active` and `is_active = 1` may receive a SIM.
12. Drivers are deactivated, never deleted. Deactivating a driver who still holds a company SIM is rejected unless the admin supplies a documented reason; the reason and the held SIM are recorded in the audit event.

**Contacts**

13. A phone number (normalized) may be active for only one driver at a time.
14. At most one active primary contact per driver; setting a new primary clears the previous one.
15. Numbers must be valid Saudi mobile formats; all accepted formats normalize to +9665XXXXXXXX.
16. Contacts are personal data only — they never substitute for a company SIM assignment.

**Acting-user rules**

17. Dispatchers act only inside their own terminal (enforced server-side on reads and on every transaction).
18. Admins/managers must not be bound to a terminal (the terminal field is forced empty for those roles).
19. Deactivated user accounts cannot sign in; account state is re-read on every request so role/permission/terminal changes apply immediately.
20. Every POST requires a valid CSRF token; passwords are bcrypt-hashed with an 8-character minimum.

**Imports**

21. Imports are two-phase: preview first, commit only on explicit confirmation; only valid rows are committed.
22. Unknown terminals, agencies, carriers, statuses, invalid numbers, and duplicate badges/mobiles/ICCIDs are rejected per row with reasons.

**Permissions**

23. Permission checks happen server-side on every page (403 page on failure) and also drive sidebar visibility.
24. Deny overrides beat allow overrides; the admin role cannot be restricted.
25. **Sign-in hardening.** reCAPTCHA verification is enforced only when keys are configured **and** the host is not localhost (fail-closed when enabled). TOTP challenges expire after 5 minutes and allow at most 10 failed code attempts before the pending step is dropped.
26. **2FA lifecycle.** Enabling and disabling two-factor authentication each require a valid current TOTP code; only the admin Reset 2FA action bypasses the code (audited). Password verification and all existing session rules are unchanged.
27. **Country access restriction (GeoIP).** When `GEO_BLOCK_ENABLED` is `true`, a request whose resolved country falls outside `GEO_BLOCK_ALLOW_COUNTRIES` (default `SA`) is rejected with a standalone 403 **before** authentication. localhost / `127.0.0.0/8` / `::1` and `GEO_BLOCK_BYPASS_IPS` always pass; the guard fails open with a log line when the country database is unavailable (unless `GEO_BLOCK_FAIL = closed`) and never fatals. The feature ships disabled and is not permission-scoped — it applies uniformly to every visitor.

---

## State and Status Lifecycles (current)

### SIM status lifecycle

| Status | Meaning | Allowed transitions currently possible |
|---|---|---|
| **available** | In stock, issuable | → assigned (issue); → suspended / lost / damaged / retired (admin override) |
| **assigned** | Held by a driver (open assignment) | → available (return); stays assigned across transfer (old assignment closes, new opens); → available + damaged old SIM (replace); → lost/damaged/suspended via mark-lost (closes the assignment) |
| **suspended** | Withdrawn from circulation temporarily | → available / lost / damaged / retired (admin override) |
| **lost** | Reported lost | → available / damaged / retired (admin override) |
| **damaged** | Physically damaged (typical after replacement) | → available / lost / retired (admin override) |
| **retired** | Permanently out of service | effectively terminal; not issuable |

Notes: the only path out of `assigned` for normal operations is a closing transaction (return/transfer/replace/mark-lost). A raw status write while assigned is rejected — the system forces the transactional path.

### Driver status lifecycle

| Status | Meaning |
|---|---|
| **active** | Eligible to receive SIMs (also requires `is_active = 1`) |
| **resigned / terminated / inactive / transferred** | Not eligible; chosen during offboarding or data correction |

Separately, the `is_active` flag implements soft deactivation. Deactivation is reversible in data terms (an admin can re-activate a record), but the system records the state changes in the audit trail. **[TO VERIFY: a UI action to re-activate a deactivated driver exists only via the edit form's Active checkbox; there is no dedicated "restore" workflow.]**

### Assignment lifecycle

`open` (issued, `returned_at IS NULL`) → `closed` (one of: return, transfer-out, replacement, lost/damaged closure) with closed rows carrying `returned_at`, `returned_by`, and a `return_reason`. The transfer flow immediately opens a successor row on the same SIM, so a SIM's history reads as a continuous chain.

---

## Permissions Matrix (current)

Default grants after installation/upgrade (per-user overrides can change any cell except admin's implicit all):

| Action / module | Admin | Manager | Dispatcher |
|---|---|---|---|
| View dashboard | ✔ | ✔ | ✔ |
| View SIM inventory / available / assignments / history | ✔ | ✔ | ✔ |
| Run SIM transactions (issue/return/transfer/replace/mark-lost) | ✔ | ✖ | ✔ |
| Change SIM status directly / retire (admin override on inventory) | ✔ | ✖ | ✖ |
| View driver list and profiles | ✔ | ✔ | ✔ |
| Manage driver master records (create/edit/deactivate) | ✔ | ✖ | ✖ |
| View driver contacts | ✔ | ✔ | ✔ |
| Manage driver contacts (add/edit/deactivate) | ✔ | ✔ | ✔ |
| View reports and export them | ✔ | ✔ | ✔ |
| Import CSV data | ✔ | ✖ | ✖ |
| Manage users and roles / assign permissions | ✔ | ✖ | ✖ |
| Manage reference data (terminals, carriers, agencies, SIM records) | ✔ | ✖ | ✖ |
| View audit log | ✔ | ✖ | ✖ |
| Manage API tokens (external integrations) | ✔ | ✖ | ✖ |

Additional structural rules: the admin role always holds every permission; custom roles start empty; terminal scoping further limits dispatchers regardless of permission grants.

---

## Country Access Restriction (GeoIP)

**Status:** shipped **off by default** — completely inert until enabled per host. `includes/geo-guard.php` is required (and enforced) from `includes/auth.php`, so every page — including sign-in — passes through it; CLI dev scripts and `install.php` are unaffected.

**How it works.** The visitor's `REMOTE_ADDR` is resolved to an ISO country code against a **local** MaxMind DB-format database (`includes/geoip/GeoLite2-Country.mmdb`, no external calls, no server modules). A request whose country is not in the allowlist receives a standalone **403 "Access restricted"** page and the attempt is written to the PHP error log (`SIMLedger geo-block: ip=… country=… reason=… uri=… ua=…`). The reader is the vendored `maxmind-db/reader` (Apache-2.0, under `includes/geoip/maxmind/`, LICENSE included) — no composer; works on plain FTP hosting.

**Settings** (declared with `defined()` fallbacks — override them in the per-host `config/config.php`, which is never shipped in update zips):

| Constant | Default | Meaning |
|---|---|---|
| `GEO_BLOCK_ENABLED` | `false` | Master switch; set `true` to activate. |
| `GEO_BLOCK_ALLOW_COUNTRIES` | `'SA'` | Comma list of allowed ISO country codes. |
| `GEO_BLOCK_FAIL` | `'open'` | `open` = missing database / unknown country allows (logged); `closed` = blocks. |
| `GEO_BLOCK_BYPASS_IPS` | `''` | Comma list of exact IPs or CIDRs (v4+v6) always allowed — office/VPN kill switch. |
| `GEO_DB_PATH` | `includes/geoip/GeoLite2-Country.mmdb` | Country database location. |
| `GEO_TEST_IP` | *(undefined)* | Automated-test override of the client IP — **never define in production**. |

**Safety rails.** (1) Disabled by default: deploying the code changes nothing. (2) localhost / `127.0.0.0/8` / `::1` are never blocked (development and the automated suites). (3) The bypass list is checked before any country lookup. (4) Fail-open default: a missing, corrupt, or outdated database allows traffic with a log line instead of locking everyone out. (5) The guard never fatals — the worst case is an allowed request.

**Enabling on a host:** add `define('GEO_BLOCK_ENABLED', true);` and (recommended) `define('GEO_BLOCK_BYPASS_IPS', '<office/VPN IP>');` to that host's `config/config.php`, then verify: a non-SA VPN gets the 403 page, a Saudi connection works normally. To revert, set the flag back to `false` (or delete the lines).

**Database updates.** GeoIP data ages — refresh `includes/geoip/GeoLite2-Country.mmdb` periodically by overwriting it via FTP: MaxMind GeoLite2-Country (free account) or the format-compatible **DB-IP country-lite** download. Any MaxMind-DB-format country database works; no code change is needed.

**Limitations.** GeoIP is approximate: VPNs, carrier-grade NAT, and mobile roaming can present non-Saudi exit IPs (and vice versa). This is a regional restriction, **not** an access-control substitute — authentication, RBAC, and 2FA remain the security boundary. If the site is ever placed behind a reverse proxy or CDN, `REMOTE_ADDR` becomes the proxy's address and the bypass settings must be revisited.

**Tests:** `tests/probe-geo.php` (read-only, 55 checks) — country lookups (SA/US/AU/AE, IPv6, `registered_country` fallback), loopback and exact/CIDR bypass matching, a 13-case decision matrix (allowed / blocked / bypass / fail-open / fail-closed / disabled / localhost / no-IP), feature-off HTTP pass-through, and a self-cleaning forced-block endpoint proving the real 403 page end-to-end.

---

## Notifications and Alerts (current)

The system has **no push channels** (no SMS, no email, no browser notifications). "Alerts" are in-application warnings:

**Dashboard operational alerts** (shown when counts are non-zero; each links to the relevant report and respects the dashboard's terminal filter):

1. N active drivers have no company SIM → Drivers Without SIM report.
2. N company SIMs are currently unassigned → Available SIMs list.
3. N SIMs are suspended → SIM inventory filtered to suspended.
4. N SIMs are lost or damaged → SIM inventory filtered to lost.

**Profile warning banner:** on a driver's profile, if the driver's status is resigned/terminated/inactive **but** an open assignment exists, a warning banner links to the return workflow.

**List badges:** drivers without a SIM show a "None" warning badge in the contacts column context; SIM/driver statuses are rendered as colour-coded badges on all lists.

**Not implemented:** there is no Iqama expiry tracking or expiring-Iqama alert, no unreturned-SIM time-based reminder, and no deactivation deadline enforcement. The only "unreturned SIM" signals are the state-based alerts above (inactive driver still holding a SIM, shown on profile and dashboard driver metrics).

---

## Audit and Logging (current)

**Principle:** the audit trail is **append-only**. No screen in the application updates or deletes audit rows.

**What is recorded** (actor username, timestamp, client IP, entity, old and new values in JSON where applicable):

- Authentication: successful sign-in (`LOGIN`); successful sign-in completed with a 2FA code (`LOGIN_2FA`); 2FA enrolment and removal (`ENABLE_2FA` / `DISABLE_2FA`); admin recovery reset (`RESET_2FA`).
- Users: user creation and update (role, terminal, active flag, whether the password changed).
- Roles: role creation; role permission updates (with granted-permission count).
- Agencies, carriers, terminals: creation and updates.
- Drivers: creation and update (badge, name, status, terminal, etc.); deactivation (soft delete flagged).
- Contacts: add, update, and deactivation (soft delete flagged).
- SIM records: status changes (including admin overrides) and import events.
- SIM transactions: issue, return, transfer, replacement, mark-lost flows with before/after assignment references.
- Imports: driver and SIM import summaries with committed/total row counts.

**Complete action catalogue — 33 actions** (verified 2026-10-09 by a full scan of every `logAudit()` call site, dynamic concatenations included, cross-checked against `SELECT DISTINCT action FROM audit_logs` — every action observed in the database maps to a code call site):

| Module | Actions |
|---|---|
| Authentication | `LOGIN`, `LOGOUT`, `LOGIN_2FA`, `ENABLE_2FA`, `DISABLE_2FA`, `RESET_2FA` |
| Users and roles | `CREATE_USER`, `UPDATE_USER`, `CREATE_ROLE`, `UPDATE_ROLE` |
| Drivers and contacts | `CREATE_DRIVER`, `UPDATE_DRIVER`, `ADD_DRIVER_CONTACT`, `UPDATE_DRIVER_CONTACT`, `DELETE_DRIVER_CONTACT` |
| SIM transactions | `ISSUE_SIM`, `RETURN_SIM`, `TRANSFER_SIM`, `REPLACE_SIM`, `MARK_LOST`, `MARK_DAMAGED`, `MARK_SUSPENDED`, `RETIRE_SIM` |
| SIM records and imports | `CREATE_SIM`, `UPDATE_SIM`, `IMPORT_SIMS`, `IMPORT_DRIVERS` |
| Reference data | `CREATE_TERMINAL`, `UPDATE_TERMINAL`, `CREATE_CARRIER`, `UPDATE_CARRIER`, `CREATE_AGENCY`, `UPDATE_AGENCY` |

Six actions are assembled dynamically at the call site: `IMPORT_DRIVERS`/`IMPORT_SIMS` by upload type; `RETURN_SIM` or `MARK_<status>` from `SimService::returnSim()` depending on the resulting SIM status; and `MARK_LOST`/`MARK_DAMAGED`/`MARK_SUSPENDED` from the Mark Lost flow by target status. All other actions are literal strings.

Blocked requests from disallowed countries (GeoIP guard) are **not** audit events — they occur before authentication and are written to the PHP error log instead (see [Country Access Restriction](#country-access-restriction-geoip)).

**Who can view:** accounts holding `audit.view` (default: admin role only), via Administration → Audit Log.

**Viewing and filtering:** date range (defaults to the last 14 days), action, user, terminal, free-text search over payload fields; pagination (50 per page); CSV/Excel export of the filtered range. Sorting by time, user, action, entity, terminal, IP, or payload.

**Retention:** none configured — rows accumulate indefinitely. Purge/archival policy is **[TO VERIFY]** (no retention feature exists in the application).

---

## Error Handling and Edge Cases (current)

- **Validation errors** are displayed inline on the originating form in a red error panel; the user's input context is preserved (the form re-renders with data).
- **Friendly messages for constraint violations:** duplicate username; duplicate badge **or** Iqama; duplicate carrier/terminal/agency name or code; duplicate normalized phone (contacts and imports) — the contact duplicate message names the other driver's badge.
- **Authorization failures:** permission failures render a full 403 page ("Access denied") with a link back to the dashboard. Cross-terminal record access returns a plain 403 text ("this record belongs to another terminal").
- **GeoIP block (country restriction):** when the restriction is enabled, blocked requests receive a standalone 403 "Access restricted" page rendered outside the application layout (no session, no sign-in form); the attempt is written to the PHP error log with IP, country, reason, URI and user agent.
- **Missing records:** an unknown driver profile renders a 404-styled page inside the application layout; unknown assignments/SIMs produce inline "not found" errors.
- **Transaction safety:** every SIM transaction runs in a single database transaction with row locks; on any exception the transaction is rolled back and the error surfaces inline — partial updates never persist. Database-level guard columns make double-open assignments impossible even under races.
- **Session expiry:** sessions carry an 8-hour idle timeout; expired sessions are destroyed and the user is redirected to the login page with an "expired" notice. Session identifiers are regenerated at sign-in (fixation defense); CSRF tokens protect every POST.
- **Imports:** malformed files, empty files, and upload errors are rejected before any write; the two-phase design prevents partial commits — only explicitly confirmed, classified-valid rows are inserted.
- **Exports:** if the server lacks ZIP support, the Excel exporter falls back to CSV so exports never hard-fail.
- **Table interactions:** if an AJAX table refresh fails, the application falls back to normal full-page navigation; client-side sorting skips empty-state rows and renumbers correctly.
- **Deactivated accounts:** sign-in attempts with a deactivated account return a distinct message ("contact your administrator").

---

## Integration Points (current, logical)

The system today interacts with its environment through these logical boundaries:

1. **CSV file import (manual boundary).** Staff export data from upstream systems (HR/agency spreadsheets), transform it to the expected headers, and upload it through the two-phase import. There is no automatic feed. Driver records carry an optional **External driver ID** field explicitly reserved for a future Address-Book/HR synchronization, but no sync logic exists.
2. **Excel export (outbound boundary).** Filtered datasets are downloaded for offline reporting; no report distribution channel (email/SMS) exists.
3. **Agency relationship.** Agencies (RT, SMASCO, SRACO, TAMKEEN) are maintained as master data inside SIMLedger; there is no API or data feed to or from agency systems. Sourcing is expressed purely through the driver's agency field.
4. **Web server / network context.** The application records the client IP on audit rows and reads the protocol context for cookie security flags; it is deployable behind a reverse proxy (HTTPS detection honours the forwarded-protocol header). The optional GeoIP guard resolves the visitor's country from a **local** MaxMind-DB file — no external geolocation service is ever contacted, and the file is refreshed manually. The guard reads `REMOTE_ADDR` directly, so behind a reverse proxy or CDN that address is the proxy's and the bypass settings must be revisited in such deployments.
5. **Telecom numbering.** Saudi mobile-number normalization (+9665XXXXXXXX canonical form) is applied internally for deduplication and display; no live number validation against a carrier occurs.
6. **Authentication boundary.** Single application-managed account store; no SSO/LDAP federation exists.
7. **External API (outbound data boundary, added 2026-10-10).** A read-only, token-authenticated JSON endpoint (`api/drivers.php`) serves the active driver roster and per-badge lookups to other in-house systems — see [External API](#external-api-driver-data-sync-current). This is the first machine interface; nothing ever writes into SIMLedger through it.

---

## External API (driver data sync, current)

SIMLedger exposes a **read-only JSON API** so other in-house systems (the Leave Management System first, payroll/HR later) can pull driver mobile numbers. It ships with the base product; a consumer needs nothing beyond an HTTP GET with a Bearer token.

### Endpoint

| Call | Returns |
|---|---|
| `GET /api/drivers.php` | Full roster: every driver with `is_active = 1 AND status = 'active'`, ordered by badge number |
| `GET /api/drivers.php?badge=NNNN` | One driver by badge number (`404 driver_not_found` when unknown or inactive) |

Auth header (either form is accepted): `Authorization: Bearer <token>` or `X-API-Key: <token>`. Responses are `Cache-Control: no-store` JSON:

```json
{ "ok": true, "driver": { "badge_number": "4321", "name": "...", "status": "active",
  "terminal": "Dammam", "mobile": "05...", "updated_at": "2026-10-08 12:00:00" } }
```

`mobile` is the driver's primary **active personal contact number** (primary first, else lowest id; `null` when none is on file). Error shapes: `401 {"error":"unauthorized"}` (missing / unknown / revoked token, or a non-Bearer scheme), `400 bad_request` (badge longer than 30 chars), `404 driver_not_found`, `405 method_not_allowed` (GET only).

### Tokens (Administration → API Tokens)

Each consuming system gets its **own named token** (e.g. "Leave Management", "Payroll") minted on the **API Tokens** page (permission `api.manage`, admin by default). Only the SHA-256 hash is stored; the plaintext is displayed **once**, immediately after minting. Revoking is a soft action (`is_active = 0`, rows are never deleted so the audit trail keeps naming them) and takes effect on the system's next call. The `Last used` column shows when the token last made any call.

### Security boundary

The token **is** the boundary: API calls skip session login, CSRF, 2FA and the GeoIP guard — they are server-to-server. Deploy the endpoint behind **HTTPS only** in production and give every consumer its own revocable token. Every call is written to `audit_logs` with `action = API_READ` and `username = api:<token name>`. The payload never exposes Iqama numbers, notes, inactive drivers or contact types.

### Consumer example: the Leave Management System

The LMS driver profile carries a **"Sync from SIMLedger"** button in the Contact card (visible with the LMS permission `drivers.master_correct` — the same right as direct master-data correction), and the Drivers list carries a bulk **"Sync all from SIMLedger"** that diffs the whole roster in one API call. Both prefer the driver's **currently assigned company SIM** (`company_sim`) and fall back to the **primary personal contact** (`mobile`). The profile button POSTs `api/drivers/sync-simledger` with the driver id; the bulk button POSTs `api/drivers/sync-simledger-all`; the LMS server calls `GET /api/drivers.php` (by badge, or the full roster), writes `drivers.mobile_number` only when the value differs (audit action `driver-mobile-synced`, with `simledger_field` = `company_sim` or `personal`), and answers with a summary — a second click is a no-op. Configuration lives in `lms/app/config/config.php` (`SIMLEDGER_API_URL`, `SIMLEDGER_API_TOKEN`; both overridable via environment, the shipped defaults are development values). End-to-end test: `lms/tools/simledger-sync-test.php`.

### Tests

`tests/probe-api.php` (CLI, self-cleaning fixtures, 21 checks): auth contract (Bearer + X-API-Key, unknown/revoked/non-Bearer), roster and badge SQL parity, error codes, inactive-driver exclusion, the `API_READ` audit trail and `last_used_at` stamping.

## Glossary

| Term | Definition in SIMLedger |
|---|---|
| **Company SIM** | A SIM card owned by the company and tracked as an inventory asset; never a driver's personal property. |
| **Assignment** | The ledger record linking a SIM to a driver for a period; open while `returned_at` is NULL. |
| **Open assignment** | The current, active holding of a SIM by a driver. |
| **Guard columns** | Database-generated columns that guarantee one open assignment per SIM and (by default) per driver. |
| **Terminal** | A physical branch/depot (Dammam, Riyadh, Jeddah by default); the unit of dispatcher scoping. |
| **Badge** | The driver's unique employee number; the primary driver identifier. |
| **Iqama** | The driver's Saudi residency permit number; optional, unique when present. |
| **Nationality** | Driver's nationality as free text. |
| **Agency** | The sourcing provider of a driver: RT (in-house) or an outsourced firm (SMASCO, SRACO, TAMKEEN). |
| **RT** | The owning company's own drivers (default agency). |
| **Normalization** | Canonicalizing phone numbers to +9665XXXXXXXX so all input formats deduplicate correctly. |
| **Masking** | Displaying numbers as e.g. 055****567 for users without full-number visibility. |
| **Role** | A named permission group (admin, manager, dispatcher, or custom). |
| **Permission override** | A per-user Always allow / Always deny decision that beats the role default. |
| **Two-phase import** | Upload → preview classification → explicit confirmation commit; only valid rows are written. |
| **Soft deactivation** | Setting `is_active = 0`; rows are never physically deleted. |
| **Partial refresh** | Table-only AJAX refresh of filtered/paginated views without a full page reload. |
| **Picker table** | A scoped table used to choose a record (assignment, driver, or SIM) on the five transaction pages: server-side search, 10 rows per page, row-click selection with visible feedback (`✓ Selected` + status line), and picker state preserved in the URL across searches and page changes. |
| **Reachable** | A driver is reachable when they have an active personal contact number **or** currently hold a company SIM (open assignment). Drives the Contact Coverage report (summary, filter, detail column, export) and the dashboard's not-reachable alert. Not the same as *verified* — no verification workflow exists. |
| **Excel export** | Formatted .xlsx download (bold title, bordered headers/cells) of the filtered dataset. |
| **Audit log** | Append-only record of who did what, when, from where, with before/after values. |
| **Status override** | Controlled admin change of an unassigned SIM's status; leaving `assigned` always requires a transaction. |
| **GeoIP guard** | Country-based access restriction (`includes/geo-guard.php`): resolves the visitor IP to a country via the local MaxMind-DB file and allows only the `GEO_BLOCK_ALLOW_COUNTRIES` list (default `SA`). Off by default; localhost and `GEO_BLOCK_BYPASS_IPS` always pass; fails open when the database is unavailable. |
| **reCAPTCHA (invisible v2)** | Google bot check on the sign-in form; enforced only when keys are configured and never on localhost / 127.0.0.1 / ::1. |
| **TOTP** | Time-based one-time code (6 digits, 30-second window) from an authenticator app — the second sign-in factor. |
| **otpauth URI** | The QR payload (`otpauth://TOTP/…`) that transfers the enrolment secret to the authenticator app; rendered locally, never sent to a third party. |

---

## Assumptions [TO VERIFY]

The following items could not be fully confirmed from the running system and should be verified before this document is treated as authoritative. (Items previously listed here for phone-masking in exports, return-reason enforcement, and the `allow_multiple_sims_per_driver` setting have been **resolved**: exports now apply the screen's masking policy, the service layer validates the return date/reason/open state, and the multi-SIM runtime switch has been removed — the rule is enforced at both the service and database layers.) A verification round on **2026-10-09** (full code scan, live-database inspection, and mmdb metadata reads) then resolved or confirmed every item below; each is annotated with its method and what — if anything — remains open.

1. **Complete audit action catalogue — resolved (2026-10-09).** Enumerated by scanning every `logAudit()` call site in the application (including dynamically concatenated actions) and cross-checking against `SELECT DISTINCT action FROM audit_logs`: **33 actions**, now listed in [Audit and Logging](#audit-and-logging-current). Every action observed in the database maps to a code call site; no unexplained actions exist.
2. **Permission-override audit granularity — verified as described.** Code check (`admin/users.php`): overrides are saved via `setUserPermissionOverrides()` immediately before `UPDATE_USER` is logged, and the audit payload contains only `role_id`, `terminal_id`, `active`, `password_changed` — override allow/deny decisions are **not** itemized (likewise the profile password-change `UPDATE_USER` entry, whose payload is `field: password_hash`). Whether override changes *should* be itemized is an open product decision, not a system unknown.
3. **Re-activation of deactivated drivers/users — verified as described.** No restore/reactivate route, action string, or `SET is_active = 1` statement exists anywhere in the application code; re-activation happens only through the edit form's Active checkbox (`admin/drivers.php`, `admin/users.php`, and the reference-data edit forms). Whether that matches the intended offboarding policy remains an organizational decision.
4. **Audit retention — verified as described.** The application contains no `DELETE`/`TRUNCATE`/purge path for `audit_logs`; the only deletion is the development-only scenario-reset script (`dev/cli-scenarios.php`), which is not part of the deployed application. Organizational retention rules, if any, remain external.
5. **Setting catalogue — resolved (2026-10-09; refreshed 2026-10-10).** Full contents enumerated against the live database: 7 rows — `managers_see_full_numbers`, `mig_2fa`, `mig_status_rth_removed`, `perm_bootstrap_version` (all four read at runtime), plus `app_name` (seeded by the installer but never read; page titles use the `APP_NAME` constant), `allow_multiple_sims_per_driver` (an orphaned row from the removed switch), and `mig_status_returned_to_hr` (the orphaned flag from the removed `returned_to_hr` status). Documented as a table under [App Settings](#app-settings).
6. **Inactive master-data semantics — verified by code inspection (2026-10-09).** Dropdown lookups default to `activeOnly = true` (`WHERE is_active = 1`, `includes/lookups.php`); CSV import builds its terminal/carrier/agency lookup maps from `is_active = 1` rows only, so an inactive master record makes the row fail as "unknown"; and export/report queries join master tables **without** active-flag predicates, so historical rows render the master name regardless of the record's current active flag. The conclusion is query-level — individual export edge cases were not all executed.
7. **SIM history global feed cap — resolved.** The global SIM-history view is now **paginated** (25 rows per page, server-side sorted, `renderPagination()` confirmed on `sim-history.php`) with no row cap; the Excel export still returns every matching row, and the per-SIM chain-of-custody view/export shows the SIM's complete history.
8. **reCAPTCHA keys and TOTP secret storage — code-verified 2026-10-09; two external bits remain open.** Verified: empty keys disable the captcha and localhost is always exempt (covered by probe-2fa, 60 checks); TOTP secrets are stored as plain base32 in `users.totp_secret` (schema + `totpGenerateSecret()`); and no backup-code code path exists anywhere in the application (zero matches). **Still open (external):** a live Google siteverify with real keys, and confirmation that the database's encryption-at-rest policy satisfies organizational requirements for the stored secrets.
9. **GeoIP database vintage — resolved; one live observation open.** Bundled database metadata: `GeoLite2-Country`, build epoch **2026-10-07** (two days old at verification), IPv6-capable, 546,762 nodes. **Still open:** no request from a genuinely non-SA network was observed with the restriction enabled from this environment (all tests force the client IP via the `GEO_TEST_IP` hook); a single manual check — enable the flag, open the site through Tor or a foreign VPN, expect the 403 page — closes this item.
