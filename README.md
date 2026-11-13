# Ledgerly — multi-tenant accounting for African businesses

Working skeleton for a QuickBooks-class accounting SaaS: Laravel 13 API + Next.js 16 web app on PostgreSQL 16.
The blueprint (modules, architecture, roadmap) lives in the "Ledgerly Africa — SaaS Accounting Platform Blueprint" doc.

```
ledgerly/
├── apps/api/                      Laravel 13 API (modular monolith)
│   ├── app/Domain/
│   │   ├── Ledger/                Money, JournalDraft, PostingService (the only writer to the ledger)
│   │   ├── Tax/                   TaxCalculator (line rounding, inclusive/exclusive VAT)
│   │   ├── Sales/                 Invoices, credit notes, InvoicePostingBuilder
│   │   ├── Banking/               Deposits and transfers (BankingPostingBuilder)
│   │   ├── Compliance/            E-invoicing connectors (sandbox, KRA eTIMS, Ethiopia MoR) + retrying job
│   │   ├── Reports/               Trial balance, P&L, balance sheet (A = L + E check), bin card
│   │   └── Tenancy/               Companies, memberships, roles, country packs, RLS context
│   ├── database/schema/           Core schema in plain PostgreSQL (triggers + row-level security)
│   │   └── tests/                 Self-checking SQL tests for every ledger guard
│   ├── database/country-packs/    ET, KE (IFRS for SMEs) + chart template
│   └── tests/                     PHPUnit unit + feature tests
├── apps/web/                      Next.js 16 app (dashboard, invoices, new invoice, reports, onboarding)
├── docker/postgres/init.sql       Owner vs app roles so RLS applies to the app
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
cd apps/api && php artisan test                         # unit + feature (feature tests need Postgres)
psql "$DB_OWNER_URL" -f database/schema/tests/ledger_guards_test.sql   # on an empty DB with schema applied
cd apps/web && npm test && npm run lint && npm run build
```

## Rules the code enforces

| Rule | Where |
| --- | --- |
| Every journal entry balances, at least two lines | `JournalDraft::assertBalanced` + deferred DB trigger |
| Posted documents editable while the year is open; closed year admin only (`periods.reopen`) | `EditPostedInvoice` |
| Every edit keeps history: version snapshot, reversal + corrected entry, audited reason | `document_versions` + DB guard refuses edits that skip it |
| Audit log append-only and tamper-evident (hash chain, `audit_log_verify()`) | `006_history.sql` |
| Journal lines never changed in place; edits are reversals | DB triggers + `PostingService::reverse` |
| Nothing posts into a closed period | `PostingService` + DB trigger |
| Tenant isolation | Eloquent scope + PostgreSQL row-level security |
| Exact money, never floats, always 2 decimal places | `Money` (brick/math) and `src/lib/decimal.ts` (bigint), same rounding rules |
| Posted documents frozen; audit log and stock moves append-only | DB triggers |
| Assets = Liabilities + Equity | `ReportService::balanceSheet` → `AccountingEquation` |

## Verified in the build sandbox (2 Nov 2026)

- 31 API unit tests pass (run through a PHPUnit-compatible shim; Packagist was blocked there, so run `composer install && php artisan test` locally — the feature tests in `InvoiceFlowTest` (incl. edit-with-history and closed-year admin edit) have not run yet).
- 22 database guard tests pass, all amounts NUMERIC(20,2) (incl. edit history, hash chain, tamper detection) on PostgreSQL 16, as the restricted app role; report SQL (trial balance, P&L, balance sheet, bin card) checked against fixtures.
- Web: 8 unit tests, lint, production build, all 5 pages render.

## Before go-live (not done here)

- Tax rates, codes and effective dates in every country pack: confirm with a tax adviser (`_verify` notes in each pack).
- KRA eTIMS connector: implement from the official spec and pass KRA certification; Ethiopia MoR connector waits for the Ministry's technical spec and system accreditation.
- Sign-in UI, MFA, bills/expenses screens, bank statement import and matching UI, payroll rule packs.
