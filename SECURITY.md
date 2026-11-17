# Ledgerly security

Security review of 14 Nov 2026, done before the project moved to Claude Code. This file is the
standing record: what protects the system, what was proven by a test, what is written but never run,
and what must be built before a real customer signs in.

**Status in one line:** the database layer is hardened and tested (46 checks). The Laravel request layer
now runs (14 Nov 2026) and its feature tests run as `ledgerly_web` with two-company isolation tests (S1 done). Sign-in is not finished (no authenticator
code yet). The product is **not ready for real customer data** until the "Before launch" list is done.

## 1. What we are protecting, and from whom

| Asset | Worst case |
|---|---|
| A company's books | Another company reads or changes them |
| The ledger and audit log | Someone rewrites history to hide fraud |
| Sign-in details (password hash, authenticator secret, tokens) | Account takeover |
| Tax registration (invoice numbers, QR, authority credentials) | Invoices issued in a company's name |
| Availability | Books unreachable at month end or a filing deadline |

| Who | What they try |
|---|---|
| A stranger on the internet | Guess passwords, inject SQL or script, flood the API |
| A signed-in user of another company | Read or write across the tenant boundary by changing ids |
| A low-rights user inside a company | Give themselves a higher role, reopen a closed period, edit without trace |
| A Ledgerly staff member | Look into or change a customer's books without a reason on record |
| Someone holding a stolen laptop, token or database backup | Use what they found |

## 2. Controls in place

"Tested" means a test in this repository fails if the control is removed. "Written" means the code
exists but has not been executed anywhere (Laravel could not be installed in the build sandbox).

### Tenant isolation
| Control | State |
|---|---|
| Every business table has `company_id` and PostgreSQL row-level security; no context means no rows | Tested: T8, T22, T24, T35 |
| The context is set per transaction, so a pooled connection cannot carry it to the next request | Tested: T24 |
| Partitions cannot be read directly, only through the parent table where the policy applies | Tested: T16 |
| The application connects as `ledgerly_web`: not owner, not superuser, no BYPASSRLS, cannot create or alter anything | Tested: T34, T35 |
| User records (email, password hash, authenticator secret) visible only to the user and their company | Tested: T32 |
| Eloquent global scope adds the company filter as a second layer | Tested: `TenantIsolationTest` (scope alone, RLS bypassed) |
| `ResolveCompany` checks an active membership before the company enters the context | Tested: `TenantIsolationTest` (B's id in the header, every company route) |

### Access inside a company
| Control | State |
|---|---|
| Only an owner or admin can add, change or remove members | Tested: T26 |
| Only an owner can grant, change or remove the owner role | Tested: T27 |
| A company always keeps one active owner | Tested: T28 |
| An invited user can accept the invitation and change nothing else | Tested: T29 |
| Reopening a closed period and changing company settings need an owner or admin | Tested: T30 |
| Adding a company to an organisation needs its creator, owner or admin | Tested: T31 |
| The application cannot change its own subscription status or plan | Tested: T22 |
| Every controller action checks a permission (`authorizeCompany`) | Written; all 21 company routes reviewed by hand (route-by-role test is S4) |

### Integrity of the books
| Control | State |
|---|---|
| Ledger lines cannot be changed or deleted; entries must balance; closed periods refuse postings | Tested: T1–T7, T18 |
| Posted documents change only through a saved version (full edit history) | Tested: T9, T12, T13 |
| Audit log is append-only and hash-chained; tampering is detected | Tested: T10, T14, T15, T33 |
| State machines refuse illegal status moves in the database | Tested: T19–T21, T23 |
| Balances table cannot be written by the application and is checked against the ledger | Tested: T17, T25 |
| Concurrent posting and period close cannot interleave | Tested: C1–C4 |

### Sign-in and sessions
| Control | State |
|---|---|
| Passwords: 10+ characters, checked against known leaked passwords, stored with bcrypt | Written |
| Lockout: 5 failures per email or 20 per address locks sign-in for 15 minutes | Written |
| Same answer and same timing whether or not the email exists | Written |
| Tokens stored as hashes, expire after 12 hours, deleted at sign-out | Written |
| Authenticator secret encrypted in the database | Written |
| **Authenticator code at sign-in** | **Not built** |
| **Email verification, password reset, sign out everywhere** | **Not built** |

### Suppliers and bills (Module 3)
| Control | State |
|---|---|
| Bills name suppliers and invoices name customers, in the database (T46); bill and invoice routes reach only their own kind | Tested |
| A supplier's payout details are changed only by `contacts.edit` roles; each change is audited as `payout_changed` with the old and new details | Tested |
| Duplicate bills (same supplier and reference, or same total on the same date) are flagged on the bill | Tested |
| The chart of accounts (no balances) and the tax rates are readable by every role: every document form names them | Tested |
| Paying suppliers (`payments.pay`) is for accountants, owners and admins; bookkeepers and purchasing staff record bills only | Tested |
| A payment made settles only its supplier's bills; no tax is withheld from a supplier with an exemption certificate (T47) | Tested |
| Refunds: paying a customer back needs `payments.pay`; a refund settles only credits of its contact and never gives back more than is left (T50) | Tested |
| Stock: the database costs every move and keeps the balances; the application can only read them; stock never goes below zero by default (T51) | Tested |
| Bank statements: imported by `banking.review` roles only; the file is read and dropped, never stored; what the bank said cannot be changed or deleted; a line is explained by one entry of the same account for the same amount; an explained entry cannot be reversed until the match is undone (T54) | Tested |
| Reconciliation (`banking.reconcile`): finishes only at a zero difference, saves its report, locks the account through the statement date; a completed reconciliation never changes; only the latest is undone, with a reason kept in the audit log (T55) | Tested |
| Cheques: a cheque number is used once per bank account and kept by a cancelled cheque; only a cheque received bounces, reversed on or after the day it came in, the bounce date written once with the reversal (T56); bouncing needs `payments.reverse` | Tested |
| Petty cash: only cash accounts keep a float; a cash count (`banking.count`) names who counted, is never changed or removed, and leaves the books holding what was counted, its difference posted to cash over and short (T57) | Tested |
| Chart of accounts (`accounting.chart`, accountants): accounts are never deleted; system accounts keep their key and stay active; only an empty account with nothing active under it is archived; sub-accounts stay within their company and type (T58) | Tested |
| Manual journals (`journals.create`): never on accounts kept by documents, checked by the database (T59); only manual journals are reversed by hand, with a reason in the audit log; entries made by documents are corrected through the document | Tested |
| Manual journals above `settings.approval.journal_above` wait as requests: asked and decided by the person signed in (the database checks it), approved or rejected by someone other than the asker (`journals.approve`), decided once, never changed or deleted (T60); an approval checks the journal again before posting | Tested |
| Period close (`periods.close`): the close checklist informs the close and its open items are kept in the `period_closed` audit record; reopening needs `periods.reopen` (owner, admin) and a reason | Tested |
| Currency revaluation (`accounting.revalue`, accountants): one run per date, made by the person signed in, naming its own revaluation entry and the reversal dated after it; kept as made, never changed or deleted (T61) | Tested |
| Recurring journals (`journals.create` to set up, edit, pause, resume and post what is due; `accounting.view` to read): checked as manual journals when set up and each time they post, within the journal approval limit, posted as the person who set them up or last edited or resumed them (a trigger refuses anyone else, T63). The hourly scheduler runs as the application login outside any company: `recurring_journals_due()` (SECURITY DEFINER) gives it template, company and poster ids only, and nothing inside a company's context; each template then posts in its own company's tenant transaction | Tested |
| Fixed assets (`assets.view`: accountant, bookkeeper, approver, viewer; `assets.manage` and `assets.depreciate`: accountant, owner, admin): an asset uses the company's own fixed asset, accumulated depreciation and expense accounts and locations; once depreciated, its cost, residual value, method, life or rate, start and accounts never change; one run per month end, by its own entry dated that day; runs and charges are kept as posted (the app role may only add them); no asset is depreciated below its residual value (T69); an asset is disposed of once, by its own entry dated on or after it was acquired, and never changes again; the proceeds go only to the company's own money account (T70, `TenantIsolationTest`) | Tested |
| Time entries (`projects.time` to log one's own: accountant, bookkeeper, sales, purchasing, approver, owner, admin; `projects.manage` for everyone's and to bill them): an entry is for a project of the company, worked by an active member, entered by the person signed in; a billed entry (its invoice not void) never changes or goes; only billable hours are billed, on an invoice of the company (T68); someone else's hours are not found for those who see only their own; billing loads the project before the body, so another company's is not found | Tested |
| Projects (`projects.view`: accountant, bookkeeper, approver, viewer, owner, admin; `projects.manage`: accountant, owner, admin): a project's details belong to a project dimension of the company, for one of its customers; a document line names only a project of its own company (T67); the line rules accept only an active project, loaded through row-level security; two-company attempts in `TenantIsolationTest` | Tested |
| Report exports (`reports.view`): the web builds the CSV from the report the API gives the same person under their own session, escapes cells a spreadsheet would run as formulas, and records the export (`POST reports/exports`: report from a fixed list, format csv or pdf, period, row count) in the audit log before handing the file over; printing or saving as PDF is recorded the same way before the print dialog opens | Tested |
| Payment reminders (048, Module 2; T75, `InvoiceReminderTest`): only for a posted invoice of the company with something owed, sent to the customer's own email by staff who write invoices (`invoices.create`) at most once a day, or by the scheduler (which learns only ids from `invoice_reminders_due()`, outside any company, then works in each company's context) once per step; records are append-only and company-isolated; not from the shared demo accounts; every reminder in the audit log | Tested |
| Custom roles (047, Module 11; T74, `TeamTest`): a custom role works only with the permissions stored on its membership; the database keeps them a list of `module.action` or `module.*` names, never `*`, and only on a custom role; the API lets an owner or admin tick single permissions from `permissions.grantable` (which leaves out managing people, exporting all data, company settings and reopening periods) and always adds `company.view`; changing them is audited with before and after | Tested |
| Company data export (Module 11, `company.export`: owners and admins; `CompanyExportTest`): every company table the database knows, as one CSV each in a ZIP, read as the application in the company's context (row-level security applies, and each query names the company); link hashes, retry keys, queues, counters and per-user settings left out; formula cells neutralised as in report CSVs; three an hour per person; not for the shared demo accounts; each download in the audit log; `TenantIsolationTest` reads inside the ZIP for company B's values | Tested |
| Signed-in devices (Module 11, `SessionTest`): each sign-in keeps a short device name ("Chrome on Windows", never the full browser string) and the address, both believed from the web server only with its key (S16); one lists and signs out only one's own sessions, not the current one (that is sign out); not for the shared demo accounts, whose sessions are every visitor's (`not.demo`); ending one is logged without the token | Tested |
| Team and invitations (046, `users.manage`: owners and admins): an invitation is an `invited` membership and a one-time link (only its SHA-256 hash kept, at most 14 days, 7 by default, a new link replaces the last); the app role reads only its own company's links (hashes) and writes none, links are made by `invitation_issue()` only for an owner or admin of that company and an open invitation, read and used once by `invitation_lookup()` and `invitation_take()` only outside any company (T73); someone new gets an account with no usable password until they accept; accepting runs as the invited person, who may only accept their own invitation (008); only an owner grants or removes the owner role and a company keeps an owner (database rules); nobody deactivates themselves; deactivated people keep their history; the shared demo accounts send no invitations and change no one (`not.demo`); every invitation, link sent again, role change, deactivation, reactivation and acceptance is in the audit log (`TeamTest`, `DemoLoginTest`) | Tested |
| Saved and scheduled reports (045, `reports.view`): row-level security on company and person, so each person sees only their own (another member's id is a 404); saved by the person signed in, an active member (T72); options limited to each report's own values, an account or warehouse loaded through its company-scoped model; the scheduler finds what is due through `saved_reports_due()` (ids only, outside any company), sends in the company's context as that person, only while they are an active member who reads reports; the email holds the report's name and period and a link that needs signing in, never figures; saving, changing, removing and sending are in the audit log (`SavedReportTest`) | Tested |
| Salesperson (044): an invoice names an active member of its own company (checked in the API and by the database, T71); only sales documents name one; a credit note keeps its invoice's, even after that person left; `GET people` (`company.view`) lists active members' names only, no roles or emails (`SalespersonTest`) | Tested |
| Stock movement and stock ageing (`reports.view`): read-only, from the company's own stock moves under row-level security and an explicit company filter; a warehouse is loaded through its company-scoped model (another company's is refused, `StockReportTest`); every SQL fragment fixed in code, dates and ids bound | Tested |
| Audit log (`audit.view`: accountant, owner, admin): read-only, the company's own rows under row-level security; filters validated (dates, uuids, action and record type as `[a-z_]` words) and bound as parameters; newest first in pages of 100 by id; the chain check calls `audit_log_verify` for the current company only; exported as CSV through the report export, itself recorded | Tested |
| Tax audit file (`tax.view`): CSV of the company's documents or ledger lines for a period, built by the API from company-scoped queries; a text cell starting with `=`, `+`, `-`, `@`, tab or CR gets an apostrophe so a spreadsheet never runs it (CSV injection); every export is in the audit log with its period and row count; the web route only passes the file on, under the user's own session | Tested |
| Tax returns (`tax.view`: accountant, viewer, owner, admin; `tax.file` to file and settle: accountant, owner, admin): a return is filed by the person signed in, after its period, never overlapping another of its kind, its figures adding up and never changing; settled once, by its own entry dated after the period; a filed period is refused for that tax's accounts in `PostingService` and in the database (T66). The worksheet's document conditions are fixed strings chosen in code, the dates and ids bound parameters | Tested |
| Review queue (`accounting.view` to read; `journals.review` to mark reviewed: accountant, approver, owner, admin): an entry is reviewed once, by the person signed in, in its own company; reviews are kept as they were, the application role may only add one (T65); the flags are fixed names computed in code, the time zone and dates are bound parameters | Tested |
| Reclassify in bulk (`journals.create`): the lines are loaded through the company (another company's line is refused, `TenantIsolationTest`), all of one account and of standing entries; the journal is drafted by `ManualJournal` (kept accounts refused on either side) and goes through the approval limit; the audit record names every line moved | Tested |
| Audit adjustments (`periods.reopen`, owner and admin): only into the latest closed year whose audit is not signed off, dated its last day, never on accounts kept by documents (T64); the lock steps back a day only while the adjustment and the new closing entry post, inside one transaction; signing off (`periods.reopen`) is final: the year stays closed and its sign-off never changes (T64) | Tested |
| Year-end close (`periods.close`; reopening `periods.reopen`, owner and admin, with a reason): one standing closing entry per fiscal year, dated its last day; a year is closed only with it and with the books locked through its end (T62); a year already locked is closed by an owner or admin, the lock stepping back a day only for the closing entry | Tested |

Product decision (CLAUDE.md, light controls): no second approver for payout changes and no three-way match. The audit
record and the duplicate warning are the controls; `settings.approval.bill_above` is the one optional approval step
(like `invoice_above` and `journal_above`), set by owners and admins in Company settings.

### Demo sign-in (off unless `DEMO_LOGINS=true`)
One shared account per role in a demo company, one click from the sign-in page (`config/demo.php`).
| Control | State |
|---|---|
| Which accounts are demo accounts is a table only the owner role writes (`018_demo_accounts.sql`, T45); the deploy fills it | Tested |
| A demo account is an active member of the demo company only; the database refuses it any other membership | Tested |
| Setup always creates new users with random passwords; it never adopts an existing account with a demo address | Tested |
| Demo accounts cannot change password, authenticator or email, sign out other devices, create a company, or get a reset email | Tested |
| Demo owner works without an authenticator (a shared second factor protects nothing); this can only reach the demo company | Tested |
| Demo sessions last 2 hours; the sign-in is rate-limited like the password sign-in | Tested |
| Turned on and off by the repository variable `DEMO_LOGINS`; the deploy writes it to the server's settings | Written |

Every visitor shares the demo data and can change it. Turn demo sign-in off (`DEMO_LOGINS=false`) before real
customers use the same database. When e-invoicing goes live (`EINVOICE_FORCE_SANDBOX=false`), keep the demo company on the
sandbox connector first.

### Requests
| Control | State |
|---|---|
| All input validated; no string-built SQL anywhere (`grep` clean on 14 Nov) | Reviewed by hand |
| Idempotency keys belong to one user and one request body | Written |
| Rate limit 120 requests a minute per user; 10 a minute on sign-in and registration | Written |
| API responses: no caching, no framing, no sniffing, HTTPS only; errors always JSON, plain 404s | Tested: `ApiResponsesTest` |
| Web app: no framing, no sniffing, HTTPS only; no tokens in `localStorage` (none found) | Build passes |
| Amounts are decimal strings end to end, never floating point | Tested (unit tests) |
| Dependency vulnerability scan in CI (`composer audit`, `npm audit`); npm scan clean on 14 Nov | In CI |

## 3. What the review found and fixed (14 Nov 2026)

| # | Finding | Severity | Fix |
|---|---|---|---|
| 1 | Any member could insert or update memberships at database level: a viewer could become owner if one controller missed a check | High | Role-aware policies and trigger (008); T26–T29 |
| 2 | Any member could move `books_closed_until` back and reopen closed books | High | `companies_guard` (008); T30 |
| 3 | `users` had no row-level security: one missing filter would expose every email and password hash | High | Policies on `users` (008); T32 |
| 4 | Any member could add a company to an organisation they could see | Medium | `company_create` policy (008); T31 |
| 5 | `audit_log_verify` ran with owner rights for any company id | Low | Limited to the current company (008); T33 |
| 6 | Idempotency replay did not check the user: a colleague who learned a key could read the stored response | Medium | Same-user check |
| 7 | Tokens never expired | High | 12-hour expiry (`config/sanctum.php`) |
| 8 | Sign-in: no lockout per account, timing revealed whether an email exists, weak password rule | Medium | `AuthController` |
| 9 | Authenticator secret would be stored readable | Medium | `encrypted` cast |
| 10 | No security headers on API or web | Medium | `SecurityHeaders`, `next.config.ts` |
| 11 | Audit query filtered by document id only | Low | Company filter added |
| 12 | Any member could rename the organisation | Low | Owner/admin only (008); T30 |
| 13 | Functions with owner rights resolved names through the temporary schema | Low | `search_path = public, pg_temp` (008) |
| 14 | No statement or lock timeout on the application role | Medium | `docker/postgres/init.sql` |
| 15 | No dependency scan in CI | Medium | Added |
| 16 | Feature tests ran as the database owner, which bypasses row-level security | High | S1: owner migrates, requests run as `ledgerly_web`; `TenantIsolationTest` |

Found on 14 Nov 2026 by running the Laravel layer for the first time:

| # | Finding | Severity | Fix |
|---|---|---|---|
| 17 | `php artisan migrate` never applied `008_access_hardening.sql`: a database built through Laravel had none of findings 1–5, 12, 13 | High | Migration `2026_11_14_000000_apply_access_hardening` |
| 18 | Tests inside the compose container ran against the development database and rebuilt its schema (Laravel reads `$_SERVER` before phpunit's `<env>`) | High | `<server>` entries; test base refuses any database not named `*_test` |
| 19 | A 404 named the model class and echoed the requested id | Low | Plain `Not found.` for every API 404 |
| 20 | A request without a token and without `Accept: application/json` got a 500 (redirect to a missing login route); validation errors redirected | Medium | API errors always JSON; guests get 401 |
| 21 | Behind a load balancer the API could not see HTTPS (no HSTS) and saw every client as one address, so the per-address sign-in lockout would lock out everyone | Medium | `TRUSTED_PROXIES` |
| 22 | On the cPanel host, "grant all privileges" made the app login a member of the database-owner role: it could drop or alter the database. And `public` is writable by every login there, which the owner-rights functions searched | High | Tables in their own schema, functions search only it; `ledgerly:check-database-roles` stops any deploy whose app login could bypass row-level security. The membership itself could not stay revoked: see 23 |
| 23 | cPanel's PostgreSQL lets a login connect only as a member of the role named after the database (pg_hba `samerole`), and that role owns the database. Revoking the membership (22) left the API unable to connect at all | Medium, accepted on the cPanel host | `DB_ALLOW_OWNER_ROLE_MEMBERSHIP=true` there only: the role check reports the membership as a warning and still refuses superuser, RLS bypass, table ownership and CREATE in the schema. The tables and the `ledgerly` schema belong to the owner login, so row-level security still applies; the CI job `api-shared-host` runs every feature test with this membership in place. What remains is CREATE SCHEMA and ALTER DATABASE, reachable only through SQL injection; both connections set `search_path` themselves, so a planted database default does not reach the migrations. Goes away with S12 (on PostgreSQL 16+, `GRANT ... WITH INHERIT FALSE, SET FALSE`) |
| 24 | Two unnamed `throttle:n,1` middlewares on one route share one counter: every sign-in request was counted twice, so the limit of 10 a minute was 5, shared by register, sign-in and every other caller behind the same address | Medium | Named limiters with their own counters (`api`, `auth`), keyed by signed-in user or visitor address |
| 25 | On a host without Redis the cache (and so every rate-limit counter) lived on the request's own database connection: a failed request rolled back its own failed-attempt count | Medium | The database cache store uses its own connection (`DB_CACHE_CONNECTION=cache`) |
| 26 | Saving an invoice validated the request before checking the role: a member without `invoices.create` got a validation answer (422) instead of a refusal (403), and could probe the rules | Low | `SaveInvoiceRequest::authorize()` checks the permission first; found by `PermissionMatrixTest` |

## 4. Honest limits

- **Row-level security does not survive SQL injection.** The tenant context is a setting the application
  sets; SQL injected on the application's connection could set it too. The defence is that no SQL is ever
  built from input: query builder and bound parameters only. One `DB::raw($input)` undoes the tenant wall.
- **The owner role bypasses everything.** Migrations, billing and the staff console use it. Its password
  must live only in the deploy system and the staff service, never in the API's environment.
  (`DB_OWNER_URL` in `.env.example` is for local development only.)
- **On the cPanel host the API's login is a member of the database-owner role** (finding 23). It cannot read
  across companies, but SQL injected there could alter database settings or create schemas. Staging and
  early access only; S12 removes it.
- **The audit chain detects tampering, it does not prevent it** by someone with superuser access. Send a
  daily chain head (last hash per company) to storage the database administrator cannot write.
- **Nothing in the Laravel layer has run.** Every "Written" row above becomes real only when its test passes.

## 5. Before launch (blocking)

Do these in Claude Code, in this order. Each ends with a test.

| Task | What | Done when |
|---|---|---|
| ~~S1~~ | **Done 14 Nov 2026.** Run feature tests as `ledgerly_web` (migrate with the owner connection, serve requests with the application connection). Add a two-company test for **every** endpoint: company B's id in the URL, body and header returns 404 or 403, never data | The suite fails if a policy or scope is removed |
| ~~S2~~ | **Done 15 Nov 2026** (`MfaTest`, `TotpTest`, database test T36). Authenticator code (TOTP) at sign-in: enrol with QR, 10 one-time recovery codes (stored hashed), required for owner and admin, required for all staff. Code checked before the token is issued; code attempts rate-limited | Sign-in without a code fails for an enrolled user |
| ~~S3~~ | **Done 15 Nov 2026** (`AccountSecurityTest`, database test T37). Email verification before a company can be created; password reset with a single-use, 60-minute token; changing password or resetting it deletes all tokens; "sign out everywhere" | Tests for each |
| ~~S4~~ | **Done 15 Nov 2026** (`PermissionMatrixTest`: 9 roles x 21 routes). A permission test per route: each role in `config/permissions.php` against each route, from one table | A new route without a row fails the test |
| ~~S5~~ | **Done 17 Nov 2026** (`CorsTest`). `config/cors.php`: only `FRONTEND_URL` (no wildcard; until now the framework default answered every origin with `*`), no credentials, only the methods and headers the API uses | Request from another origin is refused |
| S6 | Web: full Content-Security-Policy with nonces; token kept in an `HttpOnly`, `Secure`, `SameSite=Lax` cookie set by the Next.js server, never readable by page script | No token in `localStorage` or page source |
| S7 | **So far (17 Nov 2026):** no file is stored. The one upload, a bank statement, is read as text (900 KB at most, CSV only), parsed and never kept or run; report and company-data CSVs neutralise formula cells (`CompanyExportTest`, web `csv.test.ts`). Logos and attachments follow the rule below when they are built. Files (logos, attachments, bank statements): type checked by content, 10 MB limit, stored outside the web root under a random name in private storage, served through a signed 5-minute link after a permission check; spreadsheet imports never executed; CSV exports prefix cells starting `= + - @` with `'` | Upload of a script and a formula cell are both neutralised |
| S8 | Staff console as a separate app and login: own user table, authenticator required, allow-listed addresses, read-only support sessions that expire and need a reason, every staff action in the staff log, no posting into customer books | Staff tests |
| S9 | **Start-up check done 17 Nov 2026** (`ProductionChecksTest`, `ProductionBootTest`): in production the API refuses to start with `APP_DEBUG` on, without `APP_KEY`, or with `DB_OWNER_PASSWORD` set while serving requests or in a cached configuration (a deploy's migrations alone may have it). Still open: per-company e-invoicing and WhatsApp credentials encrypted in a secret store. Secrets: `APP_KEY`, database passwords, e-invoicing and WhatsApp credentials in the host's secret store; per-company authority credentials encrypted; `APP_DEBUG=false`; a start-up check refuses to boot in production with debug on or the owner password present | Boot check test |
| ~~S10~~ | **Done 17 Nov 2026** (`SecretsNeverLoggedTest`): a whole account life (sign-up, wrong and right passwords, authenticator, codes, recovery codes, reset link, password change) leaves no password, token, challenge, secret or code in the log; sign-ins, failed sign-ins (with a hash of the address typed and the visitor address), lockouts, wrong codes and account changes are logged; role changes, period reopening and exports are in the audit log. Alerts on repeated failures and on a `ledger_verify` or audit-chain failure belong with S12's monitoring | Log scrub test |
| S11 | Backups: encrypted, off-site, point-in-time recovery; a restore is tested monthly; daily audit-chain heads stored where the DBA cannot write | A restore drill on record |
| S12 | Hosting: TLS 1.2+ only, HSTS, database and Redis on a private network, PgBouncer in transaction mode, firewall or CDN rate limiting, automatic security updates | Deployment checklist signed |
| ~~S13~~ | **Done 17 Nov 2026** (`JobIsolationTest`). The one queued job (`SubmitEInvoice`) sets the tenant from the company id it was queued with, inside one transaction; queued for company A with company B's submission, it finds nothing and changes nothing; the scheduled saved-report sender reads only due ids, then works inside each report's own company | Job isolation test |
| S14 | Independent penetration test before the first paying customer; fix all high and medium findings | Report on file |
| ~~S16~~ | **Done 15 Nov 2026** (`SignInLimitsTest`). Sign-in lockout per person, not per web server: the web app signs people in from the server, so the API's per-address limits (10 sign-ins a minute, lockout after 20 failures) currently count everyone together, and 20 wrong passwords lock all users out for 15 minutes. Have the web server pass the visitor's address (from Cloudflare's `CF-Connecting-IP`) on a request the API can authenticate as coming from the web server, and limit on that | Lockout test with two visitor addresses |
| S15 | Data protection (Ethiopia Proclamation 1321/2024): legal opinion on where data may be hosted, privacy notice, data-export and account-closure process, breach-notification procedure | Opinion and documents on file |

## 6. Rules for every change

1. No SQL built from input. Query builder or bound parameters only.
2. Every new table: `company_id`, row-level security policy, explicit grant, and a line in T35's expectations.
3. Every new route: inside `auth:sanctum` + `tenant.tx` + `company`, starts with `authorizeCompany(...)`, validates every field, and gets a two-company test and a permission test.
4. Never trust an id from the client: load it through a model (scoped) or filter by the current company.
5. The ledger, audit log, stock moves and document versions are append-only. No migration, job or support tool edits them; corrections are new entries.
6. No rule that protects the books gets a feature switch or a staff override.
7. Secrets never enter git, logs, error messages or API responses.
8. Money is a decimal string with 2 places. Never a float.
9. A security control is not done until a test fails without it.

## 7. Reporting a problem

Until a security contact address exists: tell the founder directly, do not open a public issue.
