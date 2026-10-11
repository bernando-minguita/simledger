# SIMLedger — Driver SIM & Contact Management System

A dedicated application for tracking **company-owned SIM cards** issued to drivers
in a logistics/transport operation across multiple Saudi Arabian terminals.

> **Core principle:** A company SIM is a company asset. A driver is only the
> *current assignee*. The SIM number is never stored as an editable field on the
> driver record — it is derived from the active assignment, and every change
> (Issue / Return / Transfer / Replace / Lost) is a transactional, audited event
> that preserves history forever.

## Requirements

- PHP 8.0+ (PDO MySQL, mbstring, OpenSSL)
- MySQL 8+ (uses generated columns for active-assignment uniqueness guards)
- Any standard PHP/MySQL hosting (Apache + mod_php, Nginx + PHP-FPM, or the
  PHP built-in server for development)

## Installation

1. Copy the project to your web root (e.g. `htdocs/simledger`).
2. Create your local configuration from the committed sample and adjust it
   if your database credentials differ from `127.0.0.1` / `root` / `root` /
   database `simledger`:
   `copy config\config.sample.php config\config.php` (`cp` on Linux/macOS).
3. Open **`install.php`** in a browser, set the administrator username/password
   and press *Install*. It will:
   - create the database and run `sql/schema.sql`,
   - seed terminals (**Dammam / Riyadh / Jeddah**), carriers (**STC / Mobily /
     Zain / Other**), roles and settings,
   - create the first administrator account.
4. **Delete `install.php`** after installation.
5. Log in at `login.php`.

Manual alternative: create the database with `mysql -u root -p < sql/schema.sql`,
then insert your own administrator row (store a bcrypt `password_hash()`).

### Accounts

The installer creates only the administrator account (username and password
of your choice). Additional roles (manager, dispatcher, …) are managed from
`admin/roles.php`; there are no baked-in demo credentials.

## Feature map

| Area | Pages |
|------|-------|
| Dashboard | `index.php` — KPI cards, operational alerts, recent transactions |
| SIM management | `sims.php` (inventory), `available.php`, `assignments.php`, `assign.php`, `return-sim.php`, `transfer.php`, `replace-sim.php`, `mark-lost.php`, `sim-history.php` |
| Drivers | `drivers.php` (search, agency/Iqama/nationality columns), `driver.php` (profile), `contacts.php` |
| Reports | `reports/drivers-without-sim.php`, `reports/transfers.php`, `reports/assignments.php`, `reports/contact-coverage.php`, `reports/inventory.php` |
| Administration | `admin/users.php` (with per-user permission overrides), `admin/roles.php` (role → permission groups), `admin/terminals.php`, `admin/carriers.php`, `admin/agencies.php` (SMASCO/SRACO/TAMKEEN/RT), `admin/drivers.php`, `admin/sims.php`, `admin/audit-log.php`, `import.php` |
| Account | `login.php`, `logout.php`, `profile.php` |

### Access control

- **Roles → permissions (group level):** `admin/roles.php` assigns the
  permission catalog (SIM, drivers, contacts, reports, import, administration)
  to roles; defaults mirror the historical admin/manager/dispatcher behaviour.
- **Per-user fine-tuning:** each account can override individual permissions
  with *Always allow* / *Always deny* on the Users page (deny wins; the admin
  role always holds everything). Enforced server-side via `requirePermission()`
  on every page and mirrored in sidebar visibility.
- **Agencies:** drivers are sourced from outsourcing providers (SMASCO, SRACO,
  TAMKEEN) in addition to RT (own company); filterable and exported everywhere.

## Architecture

```
config/          config.php (DB constants), database.php (PDO + session)
includes/        auth.php (roles, terminal scoping), csrf.php, audit.php,
                 permissions.php (permission catalog, bootstrap, can()/requirePermission()),
                 functions.php (phone normalization, masking, XLSX export, paging),
                 lookups.php, header.php / footer.php
includes/services/SimService.php   <- ALL assignment transactions
sql/schema.sql   full schema with FKs, indexes and DB-level guards
assets/          vendored Bootstrap 5 + theme CSS
dev/             CLI helpers: installer, seeder, lint, acceptance tests,
                 HTTP smoke test, export test

### Business rules enforced at the database level

- `company_sims.normalized_mobile_number` **UNIQUE** and `iccid` **UNIQUE** —
  `0551234567`, `+966551234567`, `966551234567` are the same number.
- `driver_sim_assignments.sim_active_guard` (generated column) — a SIM can
  never have two open assignments.
- `driver_sim_assignments.driver_active_guard` (generated column) — a driver
  can never hold two open company SIMs (the service layer re-checks this
  before issue and transfer; changing the rule would need a schema migration).
- Assignment rows are **never deleted or rewritten after closure**; closures
  stamp `returned_at / returned_by / return_reason` only.
- `audit_logs` is append-only; no application code updates or deletes it.

### Transactions

`SimService::issue() / returnSim() / transfer() / replace() / markLostDamaged()
/ retire()` each run inside a single DB transaction with `SELECT … FOR UPDATE`
row locks, validation re-checks, status transitions and an audit record —
commit or rollback as a unit (transfer never makes the SIM disappear).

### Roles and terminal scoping

| Capability | Admin | Dispatcher | Manager |
|---|---|---|---|
| All terminals | yes | own terminal only | yes |
| Issue / Return / Transfer / Replace / Mark Lost | yes | own terminal | read-only |
| SIM master data (create/edit number, ICCID) | yes | no | no |
| Users / terminals / carriers / audit log | yes | no | no |
| See full phone numbers | yes | own terminal | masked (setting) |

Terminal enforcement is server-side on **every** page and transaction
(`assertTerminalAccess()` / `terminalScopeSql()`), never by hiding UI buttons.

### Phone numbers

- Internal storage: `+9665XXXXXXXX`; display: `05XXXXXXXX`;
  masking: `055****567` for users without full-number permission.
- Personal contacts live in `driver_contact_numbers` (soft-deactivated, never
  mixed with company inventory).

## Local development

```bash
copy config\config.sample.php config\config.php   # once, then edit credentials
php -S 127.0.0.1:8080 -t .                        # built-in dev server
```

Internal CLI tooling (database seeder, acceptance scenarios, HTTP smoke and
UI checks, lint sweep) is used during development but is not part of this
repository.

## Validation status (this build)

- **Lint:** all PHP files clean; stylesheet brace-balanced.
- **Acceptance scenarios (spec section 49):** 23/23 pass — issue, return,
  transfer, re-issue preserving history, double-assignment rejection,
  driver-already-has-SIM rejection, dispatcher terminal security, historical
  integrity (including the DB rejecting a second active assignment).
- **HTTP smoke:** 31/31 — login/CSRF/redirects, every page renders a complete
  document (`</html>` truncation guard), unauthenticated access redirects,
  logout works.
- **UI structure:** 26/26 — every page's `<div>`
  tags balance, the filter groupbox closes before the table card (no merged
  filter/table rendering), and each page's `$activeNav` maps to its own
  sidebar entry (correct highlight).
- **RBAC:** dispatcher receives HTTP 403 on all admin pages.
- **Excel exports:** 12/12 produce valid formatted .xlsx workbooks (bold title,
  bold bordered headers, bordered cells) respecting filters and permissions.
- **Table toolkit:** every table has a `#` column, a Columns show/hide menu and
  click-to-sort headers - all persisted across sessions; sorting appears in
  the URL (`?sort=…&dir=…`) and paginated lists sort server-side; filter
  submissions refresh only the table block over AJAX.

## Table toolkit

- `assets/js/table-toolkit.js` (vanilla, no dependencies) enhances every
  `<table class="table">` automatically:
  - **Row numbers** – a leading `#` column, offset-aware for paginated lists.
  - **Columns menu** – show/hide any column; the choice is stored per page in
    `localStorage` and survives sessions.
  - **Sorting** – click any header to sort. Paginated lists (SIM Inventory,
    Drivers, Audit Log) sort **server-side** via `?sort=<key>&dir=` (shareable,
    bookmarkable, restored from the URL or the last-used preference); all
    other tables sort client-side with the same URL sync.
  - **AJAX filtering** – filter submits, pagination clicks and server-side
    sorts refresh only their table **region** (`data-tt-partial` containers;
    the legacy `form.filter-bar` + `#table-partial` pair is still honoured).
    The dual picker tables on Transfer / Replace, the Mark Lost picker, the
    admin Drivers & SIM Records lists and SIM History search all use it —
    searching one picker leaves the other's state untouched in the URL.
    Browser back/forward re-applies previous states.
- Server-side sortable tables declare `data-sort` on their `<th>` elements,
  `data-tt-sort="server"` on the `<table>`, an optional `data-offset` for row
  numbering, and keep their column allow-lists in the page's `$sortMap`.

## Security checklist

- Prepared statements everywhere (`PDO::ATTR_EMULATE_PREPARES = false`)
- CSRF token on every POST (`csrfCheck()` / `csrfField()`)
- `password_hash()` / `password_verify()` (bcrypt), never plaintext
- Session hardening: HttpOnly, SameSite=Lax, Secure-on-HTTPS, ID regeneration
  at login, idle timeout, deactivate-takes-effect-next-request
- Output escaping via `e()` on every interpolated value
- Server-side role + terminal authorization on every page (403, not hidden links)
- Audit trail: WHO / WHAT / WHEN / WHERE / WHY for every significant action

```
