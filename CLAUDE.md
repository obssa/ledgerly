# Ledgerly — instructions for Claude Code

Multi-tenant financial accounting SaaS (QuickBooks-style). Laravel 13 API (`apps/api`), Next.js 16 web
(`apps/web`), PostgreSQL 18, Redis. Launches in Ethiopia; works in any country through country packs.

Read `README.md` (architecture and what is verified) and `SECURITY.md` (controls, open tasks) before
changing anything.

## Product decisions that are fixed

- Financial accounting, **not an ERP**. Controls stay light: roles, one optional approval step, period
  locks, edit history, audit log, credit-limit warning. Do not add workflows, multi-level approvals or
  segregation-of-duties engines.
- IFRS for SMEs only. Weighted average cost by default, FIFO optional, never LIFO.
- All amounts have 2 decimal places (exchange rates up to 6). Decimal strings, never floats.
- English and Western digits only. Ethiopian calendar is an option per company.
- No SMS. Email and WhatsApp; authenticator app for sign-in codes.
- Posted transactions can be edited while the fiscal year is open (admin only after close); every edit
  keeps full history.
- Payroll and HR come later.

## Security rules (non-negotiable)

1. **No SQL built from input.** No `DB::raw`, `whereRaw`, `selectRaw`, `orderByRaw` or `DB::statement`
   with anything that came from a request. Row-level security does not survive SQL injection.
2. Every company route sits inside `auth:sanctum` → `tenant.tx` → `company`, and its first line is
   `$this->authorizeCompany('module.action')`.
3. Every new table has `company_id`, a row-level security policy, an explicit grant to `ledgerly_app`,
   and a database test. The application role is never the table owner.
4. Never use an id from the client without loading it through a company-scoped model.
5. `journal_entries`, `journal_lines`, `stock_moves`, `audit_log`, `document_versions` are append-only.
   Only `PostingService` writes the ledger. Corrections are reversals, never updates.
6. Database rules (triggers, policies, state machines) are never weakened to make a test pass. If a
   rule blocks you, the calling code is wrong.
7. No secrets in code, tests, logs or responses. Never log request bodies.
8. A feature is done only with: validation, permission check, two-company isolation test, audit record.

## First tasks, in order

1. `composer install`, run `php artisan test`. The Laravel request layer has **never been run**; expect
   to fix wiring. Do not change database rules to get green.
2. `SECURITY.md` task **S1**: feature tests must run requests as `ledgerly_web`, not the owner.
3. `SECURITY.md` tasks **S2–S4** (authenticator code, email verification and reset, permission matrix test).
4. Then features in the order of the feature tracker, Release 1 first.

## Commands

```bash
# Database rules (105 checks): needs a PostgreSQL owner role
PGHOST=... PGPORT=... PGUSER=ledgerly_owner bash apps/api/database/schema/tests/run.sh

cd apps/api && php artisan test          # unit + feature
cd apps/web && npm test && npm run lint && npm run build
```

## Where things are

- `apps/api/database/schema/00*.sql` — the schema and its rules, applied in order. `008` is access hardening.
- `apps/api/app/Domain/*` — one folder per module; `Ledger/PostingService.php` is the only ledger writer.
- `apps/api/config/permissions.php` — role to permission map. `config/state_machines.php` mirrors the
  database's `state_transitions`.
- `apps/api/database/country-packs/` — tax and chart data per country. No rate goes live without the
  country's tax adviser.
