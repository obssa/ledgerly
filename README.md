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
├── .github/workflows/ci.yml       Database, API and web tests on every pull request
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
DB_USERNAME=ledgerly_owner DB_PASSWORD=ledgerly_owner php artisan migrate   # migrations run as the table owner
php artisan serve                                                            # app runs as ledgerly_web (RLS applies)

# Web
cd ../web
npm install
LEDGERLY_API_URL=http://localhost:8000 npm run dev    # omit LEDGERLY_API_URL for demo mode with sample data
```

## Tests

```bash
PGUSER=ledgerly_owner bash apps/api/database/schema/tests/run.sh   # fresh DB, schema, guard + concurrency tests
cd apps/api && php artisan test                                    # unit + feature (feature tests need Postgres)
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

## Verified in the build sandbox (13 Nov 2026)

- **Database: 36 checks pass** on PostgreSQL 16.15 as the restricted app role: 32 guard checks (T1–T25) and 4 two-session
  concurrency checks (C1–C4). Benchmark on 3.9 million lines / 300 companies: balances equal the ledger, partition pruning
  touches 1 of 16 partitions.
- **API: 44 unit tests pass**, run through a PHPUnit-compatible shim because Packagist is blocked in the sandbox.
- **API feature tests (`InvoiceFlowTest`, 6 tests) have NOT been run**, and neither has any Laravel middleware, controller or
  job in this repo: they were written without a Laravel install. Run `composer install && php artisan test` first; expect to
  fix small things. CI does this on every pull request.
- PostgreSQL 18 (the production target) has not been tried here; CI runs the database tests on 16 and 18.
- Web: 10 unit tests, lint (0 errors), production build, all pages render.

## Before go-live (not done here)

- **Ethiopia tax data**: `ET.json` was checked against published summaries of Proclamations 1341/2024 and 1395/2025 on
  13 Nov 2026. A licensed tax adviser must confirm every rate, threshold and date against the official text.
- **Ethiopia e-invoicing**: Directive 1142/2026 is in force but the Ministry's implementation schedule and technical
  specification are not published. The connector keeps submissions queued until Ledgerly is authorised.
- Kenya pack and eTIMS connector: second country, not reviewed.
- Billing provider integration that moves the subscription state machine; nightly `ledger_verify` and
  `audit_log_verify` jobs; load test at the plan's targets.
- Sign-in UI, MFA, bills and expenses screens, payments UI, bank statement import and matching UI.
