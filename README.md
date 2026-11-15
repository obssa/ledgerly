# Ledgerly — multi-tenant financial accounting

Working skeleton for a QuickBooks-class accounting SaaS for small and medium businesses: Laravel 13 API +
Next.js 16 web app on PostgreSQL. **Launching first in Ethiopia; usable in any country and for any type of
business.** Financial software, not an ERP. The plan lives in two docs: "Ledgerly — Plan Review and Production
Architecture" (scope, state machines, architecture, delivery) and "Ledgerly — Module Skeleton" (modules).

```
ledgerly/
├── apps/api/                      Laravel 13 API (modular monolith)
│   ├── app/Domain/
│   │   ├── Ledger/                Money, JournalDraft, PostingService (the only writer to the ledger)
│   │   ├── Tax/                   TaxCalculator (line rounding, inclusive/exclusive tax)
│   │   ├── Sales/                 Invoices: save, submit, approve, edit with history, void
│   │   ├── Banking/               Deposits and transfers
│   │   ├── Compliance/            E-invoicing connectors (sandbox, Ethiopia MoR, KRA eTIMS) + retrying job
│   │   ├── Reports/               Ledger → trial balance → P&L, balance sheet (A = L + E check), bin card
│   │   └── Tenancy/               Companies, memberships, roles, country packs, tenant context
│   ├── app/Support/               StateMachine, EthiopianCalendar, FiscalCalendar
│   ├── app/Http/Middleware/       RunInTenantTransaction, Idempotency
│   ├── config/state_machines.php  Every allowed status change (mirrored in the database)
│   ├── database/schema/           Core schema in plain PostgreSQL: partitions, triggers, row-level security
│   │   └── tests/                 Self-checking SQL tests + two-session concurrency tests (run.sh)
│   ├── database/country-packs/    ET (launch), KE (not reviewed), _default (any other country),
│   │                              _chart_ifrs_sme (base chart), _industries (13 business types)
│   └── tests/                     PHPUnit unit + feature tests
├── apps/web/                      Next.js 16 app (dashboard, invoices, new invoice, reports, onboarding)
├── .github/workflows/ci.yml       Database, API, web tests and image builds on every pull request
├── .github/workflows/deploy.yml   Production images to GHCR for every green commit on main
├── docker/postgres/init.sql       Owner vs app roles so row-level security applies to the app
└── docker-compose.yml             Postgres, Redis, Meilisearch, MinIO, Mailpit, API, worker
```

## Run it

```bash
docker compose up -d postgres redis meilisearch minio mailpit

# API
cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --database=owner   # migrations run as the table owner (DB_OWNER_* in .env)
php artisan serve                      # the app runs as ledgerly_web: row-level security applies

# Web
cd ../web
npm install
LEDGERLY_API_URL=http://localhost:8000 npm run dev    # omit LEDGERLY_API_URL for demo mode with sample data
```

PHP 8.3+ is required. Without it locally, run the API commands in the container instead, for example
`docker compose run --rm api php artisan test`.

## Tests

```bash
PGUSER=ledgerly_owner bash apps/api/database/schema/tests/run.sh   # fresh DB, schema, guard + concurrency tests
cd apps/api && php artisan test                                    # unit + feature; feature tests rebuild ledgerly_test
cd apps/web && npm test && npm run lint && npm run build
```

## One country first, every country possible

| Layer | What it is | Ethiopia (launch) | Any other country |
| --- | --- | --- | --- |
| Core | Ledger, documents, inventory, banking, reports, IFRS for SMEs chart | Same | Same |
| Country pack | Tax rates and rules, tax ID format, time zone, calendar, e-invoicing connector | `ET.json`: VAT 15%, withholding 3% above ETB 20,000 goods / 10,000 services (30% without TIN), ETB 50,000 cash limit, Ethiopian calendar, Ministry of Revenue connector | `_default.json`: company picks its currency and enters its own VAT / GST / sales tax rates; no e-invoicing |
| Industry template | Extra accounts for the type of business | 13 templates | Same 13 |

Adding a country later = one JSON file + one connector class. Nothing in the core changes.

## Rules the code enforces

| Rule | Where |
| --- | --- |
| Every journal entry balances, at least two lines | `JournalDraft::assertBalanced` + deferred DB trigger |
| Journal lines never changed in place; edits are reversals; a posted entry accepts no further lines | DB triggers + `PostingService::reverse` |
| Nothing posts into a closed period, an archived company or a suspended subscription | `PostingService` + DB trigger |
| Posting and period close cannot slip past each other; companies never block each other | Shared / exclusive advisory lock per company (`002_ledger.sql`) |
| Trial balance and statements never scan the ledger: `account_balances` kept in step by trigger | `002_ledger.sql`, `trial_balance()`, checked by `ledger_verify()` |
| Every status follows its state machine; paid states follow the open amount | `006_state_machines.sql` + `config/state_machines.php` (a test keeps them identical) |
| Payments cannot exceed the open amount, hit a void invoice, or leave a paid invoice voidable | `payment_allocations` triggers |
| Posted documents editable while the year is open; closed year admin only (`periods.reopen`) | `EditPostedInvoice`, `InvoiceWorkflow::void` |
| Every edit keeps history: version snapshot, reversal + corrected entry, audited reason | `document_versions` + DB guard refuses edits that skip it |
| A document cleared by the tax authority is corrected by credit note, never edited or voided | DB trigger + actions |
| Audit log append-only and tamper-evident (hash chain, `audit_log_verify()`) | `005_history.sql` |
| Tenant isolation: company filter + row-level security; context lives for one transaction only | Eloquent scope + `007_rls.sql` + `RunInTenantTransaction` |
| Partitions reachable only through the parent table | Grants in `007_rls.sql` |
| The app cannot change its own subscription status or write balances | Column grants and revokes in `007_rls.sql` |
| A repeated request does nothing twice; a stale save is refused | `Idempotency` middleware, `documents.lock_version` |
| Exact money, never floats, always 2 decimal places | `Money` (brick/math) and `src/lib/decimal.ts` (bigint), same rounding rules |
| Assets = Liabilities + Equity | `ReportService::balanceSheet` → `AccountingEquation` |

## Scale design (what was fixed after the plan review)

| Gap found | Fix in this skeleton |
| --- | --- |
| Reports summed raw ledger lines | `account_balances` per account and month; measured 939 ms → 8 ms for a company with 1.2 million lines |
| Tenant context set per session | Set per transaction (`set_config(..., true)`); T24 proves nothing is left behind |
| Every posting locked the company row | Shared period lock + sequence row lock only; C1–C3 prove close and posting interlock |
| Unpartitioned ledger | `journal_entries`, `journal_lines`, `stock_moves`, `audit_log`, `document_versions` hash-partitioned by company (16 here; pick the production count before launch) |
| Statuses were free text | State-machine table + triggers; 78 transitions across 10 machines |
| No tenant lifecycle | Subscription, company, membership and fiscal-year machines; suspended = read-only |
| Double clicks and concurrent edits | Idempotency keys; optimistic locking |
| No CI | `.github/workflows/ci.yml` |

## Verified

**15 Nov 2026, in CI on every pull request (PostgreSQL 13, 16 and 18; PHP 8.3 and 8.4):**

- **Database: 52 checks pass on PostgreSQL 13, 16 and 18** as the restricted app role: 48 guard checks (T1–T41) and 4
  two-session concurrency checks (C1–C4).
- **API: 127 tests pass.** `PermissionMatrixTest` calls every company route as every role (SECURITY.md S4). Feature tests build the schema as the owner and run every request as
  `ledgerly_web`, so row-level security applies (SECURITY.md S1). `TenantIsolationTest` covers every company route with
  company B's ids in the header, URL and body, and tests row-level security and the Eloquent scope each on its own;
  removing either one fails the suite.
- **Shared hosting**: the feature tests also pass on PostgreSQL 13 with the role layout of the cPanel account (no role
  creation, `public` writable by everyone, tables in their own schema), in CI on every pull request.
- **Web: 21 unit tests, lint (0 errors), production build.**
- **Production images** build and run: the API image migrated a database as the owner, served sign-up and company setup as
  `ledgerly_web`, and runs as a non-root user; the web image runs in demo mode or against the API from the same build.

Earlier, in the build sandbox: benchmark on 3.9 million lines / 300 companies (balances equal the ledger, partition pruning
touches 1 of 16 partitions).

## Feature progress (Release 1, in the order of the module document)

| Module | Built | Next |
| --- | --- | --- |
| 1 Company & Setup | Company setup; profile and settings (legal form, address, tax ID checked per country); the foundation of the books (base currency, calendar, fiscal year start, conversion date) fixed once anything is posted (database rule T38); period lock: accountants close, owners and admins reopen with a reason; foreign currencies and daily exchange rates (T39), a foreign-currency invoice takes the rate of its date and keeps it; opening trial balance on the conversion date (a difference goes to Opening balance equity, flagged; re-entry reverses and replaces); gapless document numbers given at posting (per fiscal year, in the company's calendar) or from a printed book, a posted number never changes (T40); locations, classes and projects on journal lines, only the company's own and of the right kind (T41); several companies under one organisation with a company switcher; every change audited | Data import; open invoices and bills at conversion (with the Customer and Supplier modules); invoice templates (with PDFs); central-bank rate feed |

## CI/CD

| Workflow | When | What |
| --- | --- | --- |
| `ci.yml` | every pull request and push to `main` | database guards on PG 16 + 18; API unit and feature tests on PG 18; `composer audit`; web tests, lint, build; `npm audit`; both production images build |
| `deploy.yml` | after `ci` passes on `main` (or by hand) | builds `ghcr.io/<owner>/ledgerly-api` and `ledgerly-web`, tagged with the tested commit and `main`, with provenance and SBOM |
| `deploy.yml` → `cpanel` | same, once `DEPLOY_ENABLED=true` | deploys to the cPanel account (docs/runbook/cpanel.md) |
| Dependabot | weekly | grouped updates for actions, composer, npm and base images |

## Deploying

**Now: the cPanel account** (https://ledgerly.orcamind.dev, API at https://ledgerly-api.orcamind.dev). `deploy.yml`
uploads each green `main` over SSH, migrates as the owner, refuses to go live unless the app login is properly
restricted, switches atomically, smoke-tests and rolls back on failure. Setup and operations:
[`docs/runbook/cpanel.md`](docs/runbook/cpanel.md). Shared hosting is for staging and early access only (S11, S12).

**Later: a container host.** Images are ready. A release, in this order:

1. **Migrate** with a one-off task that alone holds the owner credentials:
   `docker run --rm -e DB_HOST=... -e DB_DATABASE=... -e DB_OWNER_USERNAME=... -e DB_OWNER_PASSWORD=... ghcr.io/<owner>/ledgerly-api:<sha> php artisan migrate --database=owner --force`
2. **Roll out the API** (`:8080`, health check `/up`) with `APP_KEY`, `APP_URL`, `FRONTEND_URL`, `DB_*` as `ledgerly_web`,
   `REDIS_HOST`, and `TRUSTED_PROXIES` set to the load balancer's addresses (needed for HTTPS detection and per-client
   rate limits). Never give it the owner credentials.
3. **Roll out the worker**: the same image, command `php artisan horizon`.
4. **Roll out the web app** (`:3000`) with `LEDGERLY_API_URL` pointing at the API.

TLS is terminated by the load balancer. SECURITY.md S9 and S12 list what the host must provide before real customers sign in.

## Security

`SECURITY.md` is the record of the 14 Nov 2026 security review: threat model, every control and whether a test proves it,
the 16 findings and their fixes, and the 15 blocking tasks (S1–S15) before a real customer signs in. `CLAUDE.md` holds the
rules Claude Code follows in this repository.

## Before go-live (not done here)

- **Ethiopia tax data**: `ET.json` was checked against published summaries of Proclamations 1341/2024 and 1395/2025 on
  13 Nov 2026. A licensed tax adviser must confirm every rate, threshold and date against the official text.
- **Ethiopia e-invoicing**: Directive 1142/2026 is in force but the Ministry's implementation schedule and technical
  specification are not published. The connector keeps submissions queued until Ledgerly is authorised.
- Kenya pack and eTIMS connector: second country, not reviewed.
- Billing provider integration that moves the subscription state machine; nightly `ledger_verify` and
  `audit_log_verify` jobs; load test at the plan's targets.
- Turning the authenticator off or renewing recovery codes in the web app (the API has both), bills and expenses screens, payments UI, bank
  statement import and matching UI. Sign-in, sign-up, sign-out and company setup are built (`apps/web/src/app/(auth)`).
