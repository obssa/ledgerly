import "server-only";
import type { DocumentHistory } from "./history";
import { cache } from "react";
import { redirect } from "next/navigation";
import { connection } from "next/server";
import { ApiError, isDemoMode, ledgerly, type Account, type BankRule, type RecurringInvoice, type CashForecast, type Kpis, type ArAgeing, type BankAccount, type BankRegister, type StatementLines, type FiscalYear, type RevaluationPreview, type RevaluationRun, type CloseChecklist, type GeneralLedger, type JournalReport, type TrialBalanceRow, type JournalRequest, type RecurringJournal, type ReviewQueue, type TaxWorksheet, type TaxReturn, type TaxCalendar, type BalanceSheet, type AuditLog, type ProfitByDimension, type DocumentAnalysis, type CashFlowStatement, type ChangesInEquity, type FinancialHealth, type Project, type ProjectDetail, type TimeEntry, type FixedAssetRegister, type FixedAssetDetail, type AdjustedTrialBalance, type JournalDetail, type JournalRow, type ChartAccount, type CashCount, type ChequeRegister, type Reconciliation, type Reconciliations, type BinCard, type Item, type StockOnHand, type Warehouse, type Bill, type Expense, type WithholdingAdvice, type BillDetail, type Supplier, type Company, type CompanySettings, type Currencies, type Customer, type CustomerStatement, type DemoOptions, type Dimension, type Invoice, type InvoiceDetail, type QuoteDetail, type BackOrders, type PurchaseOrderDetail, type PurchaseOrderSummary, type GoodsReceipt, type GoodsReceiptSummary, type UnbilledGoods, type SalesReceipt, type DeliveryNote, type DeliveryNoteSummary, type UninvoicedGoods, type QuoteKind, type LandedCosts, type TaxDepreciationYear, type PostDatedCheques, type Attachment, type AttachmentKind, type QuoteSummary, type Numbering, type OpeningBalances, type PaymentReceived, type ProfitAndLoss } from "./api";
import { paged, type ListQuery, type Paged } from "./lists";
import type { TransactionReportData, BankPaymentFile, Bin, LeaseDetail, LeaseRegister, Tag, TagKind, TagReport, TransactionTags, DonorReport, IndustryTemplates, DirectCashFlow, FinancialNotes, ItemAssembly, ExpenseClaim, ExpenseClaimList, PurchaseRequisition, RequisitionList, InvoiceWriteOffs, LateInterestPreview, PriceList, PriceListDetail, ScheduledPayments, ScheduledPaymentDetail } from "./api";
import { demoCompany, demoCompanySettings, demoCurrencies, demoCustomers, demoDimensions, demoTaxRates, demoNumbering, demoInvoices, demoProfitAndLoss } from "./demo";
import { getSession } from "./session";
import { todayIn } from "@/lib/dates";
import type { AsOfPreset, PeriodContext, RangePreset } from "./periods";
import { apiDates, pickAsOf, pickRange, type PickedAsOf, type PickedRange } from "./periodParams";
import { sortSessions, type SignedInSession } from "./sessions";
import type { Subscription } from "./subscription";

/**
 * Server-side data access. In demo mode returns sample data; otherwise calls the API with the session's
 * token and selected company (HttpOnly cookies set by the sign-in flow, src/app/(auth)/actions.ts).
 *
 * connection() first: whether this is demo mode is decided per request, so one built image can run either
 * way. Callers render inside a <Suspense> boundary (src/app/(app)/layout.tsx).
 */
/**
 * Lookups that several parts of one page make (the company menu, the user menu, the printed header, the subscription
 * notice, the report period, the page itself): asked of the API once per request, not once per part. React's cache()
 * lasts for one server request, so a change shows on the next page. The overview alone asked for the company list
 * three times and the settings twice.
 */
const companiesOf = cache((token: string) => ledgerly.companies(token));
const settingsOf = cache((token: string, companyId: string) => ledgerly.companySettings({ token, companyId }));
const fiscalYearsOf = cache((token: string, companyId: string) => ledgerly.fiscalYears({ token, companyId }));
const subscriptionOf = cache((token: string, companyId: string) => ledgerly.subscription({ token, companyId }));

async function withApi<T>(call: (ctx: { token: string; companyId: string }) => Promise<T>): Promise<T> {
  const { token, companyId } = await getSession();
  if (!token) redirect("/sign-in");
  if (!companyId) redirect("/onboarding");
  try {
    return await call({ token, companyId });
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) redirect("/sign-in"); // token expired or signed out elsewhere
    // Owners and admins work in a company only with an authenticator app set up (SECURITY.md S2).
    if (error instanceof ApiError && error.status === 403 && (error.body as { type?: string } | null)?.type === "MfaEnrolmentRequired") {
      redirect("/security");
    }
    // No usable company: not chosen yet, or not a member (any more).
    if (error instanceof ApiError && (error.status === 400 || (error.status === 403 && (error.body as { type?: string } | null)?.type === "NotAMember"))) {
      redirect("/onboarding");
    }
    // A member whose role does not include this page.
    if (error instanceof ApiError && error.status === 403) redirect("/no-access");
    throw error;
  }
}

/** Whether the signed-in person has an authenticator app set up. */
export async function getSecurityStatus(): Promise<{ mfaEnabled: boolean; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { mfaEnabled: false, demo: true };
  const { user } = await withApi(({ token }) => ledgerly.me(token));
  return { mfaEnabled: user.mfa_enabled, demo: false };
}

/**
 * The devices the signed-in person is signed in on, this one first. Null in demo mode and for the shared demo
 * accounts, whose sessions belong to every visitor.
 */
export async function getDeviceSessions(): Promise<SignedInSession[] | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(({ token }) => ledgerly.sessions(token).then((r) => sortSessions(r.data)).catch((error: unknown) => {
    if (error instanceof ApiError && error.status === 403 && (error.body as { type?: string } | null)?.type === "DemoAccount") return null;
    throw error;
  }));
}

/** The profile and fiscal settings of the company being worked in. In the demo nothing can be changed. */
export async function getCompanySettings(): Promise<CompanySettings & { demo: boolean }> {
  await connection();
  if (isDemoMode()) return { ...demoCompanySettings, demo: true };
  return { ...(await withApi((ctx) => settingsOf(ctx.token, ctx.companyId))), demo: false };
}

export async function getCurrencies(): Promise<Currencies> {
  await connection();
  if (isDemoMode()) return demoCurrencies;
  return withApi((ctx) => ledgerly.currencies(ctx));
}

/** Entries of the books, newest first, 50 a page. */
export async function getJournal(q: { from?: string; to?: string; source?: string; search?: string; page?: string; earlier?: string }): Promise<{ data: JournalRow[]; page: number; more: boolean; can: { create: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], page: 1, more: false, can: { create: false }, demo: true };
  return { ...(await withApi((ctx) => ledgerly.journal(q, ctx))), demo: false };
}

/** Manual journals waiting for approval (or decided, when asked). */
export async function getJournalRequests(status = "pending"): Promise<{ data: JournalRequest[]; pending: number }> {
  await connection();
  if (isDemoMode()) return { data: [], pending: 0 };
  return withApi((ctx) => ledgerly.journalRequests(status, ctx));
}

/** Module 9c: the fixed asset register, and what the next depreciation run would post; null in the demo or for a role that does not see it. */
export async function getFixedAssets(periodEnd?: string): Promise<{ register: FixedAssetRegister; preview: { period_end: string; lines: { asset_id: string; code: string; name: string; amount: string }[]; total: string } | null } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi(async (ctx) => {
      const register = await ledgerly.fixedAssets(ctx);
      const preview = await ledgerly.depreciationPreview(periodEnd ?? register.next_period_end, ctx).catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 422) return null;
        throw error;
      });
      return { register, preview };
    });
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return null;
    throw error;
  }
}

export async function getFixedAsset(id: string): Promise<FixedAssetDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.fixedAsset(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && [403, 404].includes(error.status)) return null;
    throw error;
  }
}

/** The accounts and locations an asset form offers. */
export async function getAssetOptions(): Promise<{ assetAccounts: Account[]; depreciationAccounts: Account[]; expenseAccounts: Account[]; locations: Dimension[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [accounts, dimensions] = await Promise.all([ledgerly.accounts(ctx), ledgerly.dimensions(ctx)]);
    const active = accounts.data.filter((a) => a.is_active);
    return {
      assetAccounts: active.filter((a) => a.type === "asset" && a.subtype === "fixed_asset"),
      depreciationAccounts: active.filter((a) => a.type === "asset" && a.subtype === "contra_asset" && a.system_key !== "doubtful_debts_allowance"),
      expenseAccounts: active.filter((a) => a.type === "expense"),
      locations: dimensions.data.filter((d) => d.kind === "location" && d.is_active),
    };
  });
}

/** Module 9a: the projects with their profit; null in the demo or for a role that does not see projects. */
export async function getProjects(): Promise<{ data: Project[]; can: { manage: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.projects(ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return null;
    throw error;
  }
}

/** Hours: one's own, or everyone's for whoever manages projects; null in the demo or for a role that logs no time. */
export async function getTimeEntries(q: { project_id?: string; from?: string; to?: string }): Promise<{ data: TimeEntry[]; can: { manage: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.timeEntries(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 422)) return null;
    throw error;
  }
}

/** Active projects to issue stock to, and the cost accounts to charge it to; null in the demo or without the projects. */
export async function getIssueOptions(): Promise<{ projects: { id: string; code: string; name: string }[]; costAccounts: { id: string; code: string; name: string }[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi(async (ctx) => {
      const [dimensions, accounts] = await Promise.all([ledgerly.dimensions(ctx), ledgerly.accounts(ctx)]);
      return {
        projects: dimensions.data.filter((d) => d.kind === "project" && d.is_active).map((d) => ({ id: d.id, code: d.code, name: d.name })),
        costAccounts: accounts.data.filter((a) => a.is_active && a.type === "expense").map((a) => ({ id: a.id, code: a.code, name: a.name })),
      };
    });
  } catch {
    return null;
  }
}

/** Active projects to log hours against, and income accounts to bill them to. */
export async function getTimeOptions(): Promise<{ projects: { id: string; code: string; name: string }[]; incomeAccounts: { id: string; code: string; name: string }[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [dimensions, accounts] = await Promise.all([ledgerly.dimensions(ctx), ledgerly.accounts(ctx)]);
    return {
      projects: dimensions.data.filter((d) => d.kind === "project" && d.is_active).map((d) => ({ id: d.id, code: d.code, name: d.name })),
      incomeAccounts: accounts.data.filter((a) => a.type === "income" && a.is_active).map((a) => ({ id: a.id, code: a.code, name: a.name })),
    };
  });
}

export async function getProject(id: string, q: { from?: string; to?: string }): Promise<ProjectDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.project(id, q, ctx));
  } catch (error) {
    if (error instanceof ApiError && [403, 404, 422].includes(error.status)) return null;
    throw error;
  }
}

/** Module 7: a tax worksheet and the filed returns; null in the demo, or for a role that does not see tax. */
export async function getTaxCentre(q: { kind?: string; from?: string; to?: string }): Promise<{ sheet: TaxWorksheet; returns: TaxReturn[]; calendar: TaxCalendar } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi(async (ctx) => {
      const [sheet, returns, calendar] = await Promise.all([ledgerly.taxWorksheet(q, ctx), ledgerly.taxReturns(ctx), ledgerly.taxCalendar(ctx)]);
      return { sheet, returns: returns.data, calendar };
    });
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 422)) return null;
    throw error;
  }
}

export async function getTaxReturn(id: string): Promise<TaxReturn | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.taxReturn(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 403)) return null;
    throw error;
  }
}

/** The review queue of unusual entries for a period; null in the demo. */
export async function getReviewQueue(q: { from?: string; to?: string; reviewed?: string }): Promise<ReviewQueue | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.reviewQueue(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return null;
    throw error;
  }
}

export async function getRecurringJournals(): Promise<{ data: RecurringJournal[]; due: number; can: { create: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], due: 0, can: { create: false }, demo: true };
  return { ...(await withApi((ctx) => ledgerly.recurringJournals(ctx))), demo: false };
}

export async function getRecurringJournal(id: string): Promise<RecurringJournal | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.recurringJournal(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function getJournalRequest(id: string): Promise<JournalRequest | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.journalRequest(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function getJournalEntry(id: string): Promise<JournalDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.journalEntry(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** What the manual journal form needs: the accounts it may use, the active dimensions, and the company's currency. */
export async function getJournalOptions(): Promise<{ accounts: Account[]; dimensions: Dimension[]; baseCurrency: string } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [accounts, dimensions, currencies] = await Promise.all([ledgerly.accounts(ctx), ledgerly.dimensions(ctx), ledgerly.currencies(ctx)]);
    return { accounts: accounts.data, dimensions: dimensions.data.filter((d) => d.is_active), baseCurrency: currencies.base_currency };
  });
}

/** The chart of accounts with balances (archived accounts too, when asked). */
export async function getChart(archived = false): Promise<{ data: ChartAccount[]; kinds: Record<string, string[]>; can: { manage: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], kinds: {}, can: { manage: false }, demo: true };
  return { ...(await withApi((ctx) => ledgerly.chart(archived, ctx))), demo: false };
}

/** Module 1: the industry templates and what each would add to the chart. null in the demo, which has no chart to change. */
export async function getIndustryTemplates(): Promise<IndustryTemplates | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.industryTemplates(ctx));
}

/** The opening balances and the accounts they can be entered on. null in the demo, which has no ledger to change. */
export async function getOpeningBalances(): Promise<{
  opening: OpeningBalances; accounts: Account[]; customers: { id: string; name: string }[]; suppliers: { id: string; name: string }[]; currencies: string[];
} | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [opening, accounts, customers, suppliers, currencies] = await Promise.all([
      ledgerly.openingBalances(ctx), ledgerly.accounts(ctx), ledgerly.customers({}, ctx), ledgerly.suppliers({}, ctx), ledgerly.currencies(ctx),
    ]);
    return {
      opening, accounts: accounts.data,
      customers: customers.data.map((c) => ({ id: c.id, name: c.name })),
      suppliers: suppliers.data.map((s) => ({ id: s.id, name: s.name })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
    };
  });
}

export async function getDimensions(): Promise<Dimension[]> {
  await connection();
  if (isDemoMode()) return demoDimensions;
  return (await withApi((ctx) => ledgerly.dimensions(ctx))).data;
}

/** Numbering of the document types in use so far (invoices, credit notes, bills, expenses). */
export async function getNumbering(): Promise<Numbering[]> {
  await connection();
  const types = ["invoice", "credit_note", "bill", "expense"];
  if (isDemoMode()) return demoNumbering;
  return (await withApi((ctx) => ledgerly.numbering(ctx))).data.filter((n) => types.includes(n.doc_type));
}

export async function getCustomers(q: { search?: string; archived?: boolean }): Promise<{ data: Customer[]; can: { create: boolean; edit: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) {
    const s = q.search?.toLowerCase();
    return { data: demoCustomers.filter((c) => !s || c.name.toLowerCase().includes(s)), can: { create: false, edit: false }, demo: true };
  }
  const result = await withApi((ctx) => ledgerly.customers({ search: q.search || undefined, include_archived: q.archived ? "1" : undefined }, ctx));
  return { ...result, demo: false };
}

/** One customer, or null when it is not one of this company's customers. */
export async function getCustomer(id: string): Promise<(Customer & { demo: boolean }) | null> {
  await connection();
  if (isDemoMode()) {
    const c = demoCustomers.find((d) => d.id === id);
    return c ? { ...c, recent_invoices: [], can: { create: false, edit: false }, demo: true } : null;
  }
  try {
    return { ...(await withApi((ctx) => ledgerly.customer(id, ctx))), demo: false };
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export interface InvoiceOptions {
  /** The customers for an invoice, the suppliers for a bill. */
  customers: { id: string; name: string; tax_id: string | null; currency: string | null; payment_terms_days: number; default_account_id?: string | null; price_list_id?: string | null }[];
  /** 080: the active price lists, each in its currency, with a price per item it lists. */
  priceLists?: { id: string; currency: string; prices: Record<string, string> }[];
  accounts: { id: string; code: string; name: string }[];
  taxRates: { id: string; code: string; name: string; rate: string }[];
  currencies: string[];
  baseCurrency: string;
  /** Manual numbering: the number is typed from the invoice book. */
  manualNumbers: boolean;
  /** For an expense: the bank, cash and wallet accounts it can be paid from. */
  moneyAccounts?: { id: string; code: string; name: string; currency: string | null }[];
  /** Items to pick on a line, and the warehouses stock lines use. */
  items?: { id: string; name: string; type: string; unit: string; price: string | null; account_id: string | null; units?: { id: string; name: string; factor: string; barcode?: string | null }[]; tracking?: string; barcode?: string | null }[];
  warehouses?: { id: string; name: string }[];
  /** Active projects a line can be tagged with (job costing). */
  projects?: { id: string; code: string; name: string }[];
  /** The company's active members, for the salesperson on an invoice. */
  people?: { id: string; name: string }[];
  demo: boolean;
}

/** Active projects, for tagging document lines. */
const activeProjects = async (ctx: { token: string; companyId: string }) =>
  (await ledgerly.dimensions(ctx)).data.filter((d) => d.kind === "project" && d.is_active).map((d) => ({ id: d.id, code: d.code, name: d.name }));

/** What the new-invoice screen offers: active customers, income accounts, sales tax rates in force, currencies. */
/** What the invoice form offers; a quote or sales order uses the same, with its own numbering mode. */
export async function getInvoiceOptions(numberedAs: "invoice" | "quote" | "sales_order" = "invoice"): Promise<InvoiceOptions> {
  await connection();
  if (isDemoMode()) {
    return {
      customers: demoCustomers.map((c) => ({ id: c.id, name: c.name, tax_id: c.tax_id, currency: c.currency, payment_terms_days: c.payment_terms_days })),
      accounts: [{ id: "sales", code: "4000", name: "Sales" }],
      taxRates: demoTaxRates,
      currencies: ["ETB", "USD"],
      baseCurrency: "ETB",
      manualNumbers: false,
      demo: true,
    };
  }
  const today = await getCompanyToday();
  return withApi(async (ctx) => {
    const [customers, accounts, rates, currencies, numbering, items, warehouses, people] = await Promise.all([
      ledgerly.customers({}, ctx), ledgerly.accounts(ctx), ledgerly.taxRates(ctx), ledgerly.currencies(ctx), ledgerly.numbering(ctx),
      ledgerly.items({}, ctx), ledgerly.warehouses(ctx), ledgerly.people(ctx),
    ]);
    return {
      people: people.data,
      customers: customers.data.map((c) => ({ id: c.id, name: c.name, tax_id: c.tax_id, currency: c.currency, payment_terms_days: c.payment_terms_days, price_list_id: c.price_list_id ?? null })),
      priceLists: await ledgerly.priceListPrices(ctx).then((r) => r.data, (error) => {
        if (error instanceof ApiError && error.status === 403) return [];
        throw error;
      }),
      accounts: accounts.data.filter((a) => a.type === "income" && a.is_active).map((a) => ({ id: a.id, code: a.code, name: a.name })),
      taxRates: rates.data
        .filter((r) => r.kind !== "withholding" && r.effective_from <= today && (r.effective_to === null || r.effective_to >= today))
        .map((r) => ({ id: r.id, code: r.code, name: r.name, rate: r.rate })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
      baseCurrency: currencies.base_currency,
      manualNumbers: numbering.data.find((n) => n.doc_type === numberedAs)?.mode === "manual",
      items: items.data.map((i) => ({ id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.sale_price, account_id: i.income_account_id, units: i.units ?? [], barcode: i.barcode ?? null })),
      warehouses: warehouses.data.filter((w) => w.is_active).map((w) => ({ id: w.id, name: w.name })),
      projects: await activeProjects(ctx),
      demo: false,
    };
  });
}

/** What the new-bill screen offers: active suppliers, cost accounts (expense, fixed asset, prepayment), VAT and TOT in force. */
export async function getBillOptions(): Promise<InvoiceOptions> {
  await connection();
  if (isDemoMode()) {
    return { customers: [], accounts: [], taxRates: demoTaxRates, currencies: ["ETB"], baseCurrency: "ETB", manualNumbers: false, demo: true };
  }
  const today = await getCompanyToday();
  return withApi(async (ctx) => {
    const [suppliers, accounts, rates, currencies, numbering, items, warehouses] = await Promise.all([
      ledgerly.suppliers({}, ctx), ledgerly.accounts(ctx), ledgerly.taxRates(ctx), ledgerly.currencies(ctx), ledgerly.numbering(ctx),
      ledgerly.items({}, ctx), ledgerly.warehouses(ctx),
    ]);
    return {
      customers: suppliers.data.map((s) => ({ id: s.id, name: s.name, tax_id: s.tax_id, currency: s.currency, payment_terms_days: s.payment_terms_days, default_account_id: s.default_expense_account_id })),
      accounts: accounts.data
        .filter((a) => a.is_active && (a.type === "expense" || (a.type === "asset" && ["fixed_asset", "prepayment"].includes(a.subtype ?? ""))))
        .map((a) => ({ id: a.id, code: a.code, name: a.name })),
      taxRates: rates.data
        .filter((r) => (r.kind === "vat" || r.kind === "tot") && r.effective_from <= today && (r.effective_to === null || r.effective_to >= today))
        .map((r) => ({ id: r.id, code: r.code, name: r.name, rate: r.rate })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
      baseCurrency: currencies.base_currency,
      manualNumbers: numbering.data.find((n) => n.doc_type === "bill")?.mode === "manual",
      items: items.data.map((i) => ({ id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.purchase_price, account_id: i.expense_account_id, units: i.units ?? [], tracking: i.tracking ?? "none" })),
      warehouses: warehouses.data.filter((w) => w.is_active).map((w) => ({ id: w.id, name: w.name })),
      projects: await activeProjects(ctx),
      demo: false,
    };
  });
}

/** The new-expense screen: what a bill offers, plus the accounts the money can come from. */
export async function getExpenseOptions(): Promise<InvoiceOptions> {
  const options = await getBillOptions();
  if (options.demo) return options;
  const { data } = await withApi((ctx) => ledgerly.accounts(ctx));
  return {
    ...options,
    manualNumbers: false,
    moneyAccounts: data.filter((a) => a.is_active && ["bank", "cash", "mobile_money"].includes(a.subtype ?? ""))
      .map((a) => ({ id: a.id, code: a.code, name: a.name, currency: a.currency })),
  };
}

/** The delivery notes, newest first (071). */
/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getDeliveryNotes(q: ListQuery = {}): Promise<Paged<DeliveryNoteSummary>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.deliveryNotes(ctx, q)));
}

/** One delivery note, or null when it is not one of this company's (071). */
export async function getDeliveryNote(id: string): Promise<DeliveryNote | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.deliveryNote(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** Goods delivered not invoiced, line by line (071). */
export async function getUninvoicedGoods(): Promise<UninvoicedGoods | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.uninvoicedGoods(ctx));
}

/** What the cash sale screen offers (070): the invoice form's customers, income accounts, items and taxes, and the money accounts. */
export async function getSalesReceiptOptions(): Promise<InvoiceOptions> {
  const options = await getInvoiceOptions();
  if (options.demo) return options;
  const { data } = await withApi((ctx) => ledgerly.accounts(ctx));
  return {
    ...options,
    manualNumbers: false,
    moneyAccounts: data.filter((a) => a.is_active && ["bank", "cash", "mobile_money"].includes(a.subtype ?? ""))
      .map((a) => ({ id: a.id, code: a.code, name: a.name, currency: a.currency })),
  };
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getSalesReceipts(q: ListQuery = {}): Promise<Paged<SalesReceipt>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.salesReceipts(ctx, q)));
}

/** One cash sale, or null when it is not one of this company's (070). */
export async function getSalesReceipt(id: string): Promise<SalesReceipt | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.salesReceipt(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getExpenses(q: ListQuery = {}): Promise<Paged<Expense>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.expenses(ctx, q)));
}

export async function getExpense(id: string): Promise<Expense | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.expense(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** Module 3: the bank payment file for a day (a preview: not audited); null when the role cannot make it. */
export async function getBankPaymentFile(q: { date: string; account_id: string; method: string }): Promise<BankPaymentFile | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.bankPaymentFile(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return null;
    throw error;
  }
}

export async function getBankAccounts(): Promise<{ data: BankAccount[]; can: { manage: boolean; transfer: boolean; deposit: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], can: { manage: false, transfer: false, deposit: false }, demo: true };
  return { ...(await withApi((ctx) => ledgerly.bankAccounts(ctx))), demo: false };
}

export async function getBankRegister(id: string, q: { from?: string; to?: string }): Promise<BankRegister | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.bankRegister(id, q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** A money account's statement lines on one tab, and the accounts a line can be categorised to (only for those who review). */
/** 077: the recurring invoices. */
export async function getRecurringInvoices(): Promise<{ data: RecurringInvoice[]; can: { manage: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.recurringInvoices(ctx));
}

/** 076: the bank rules, with the accounts and money accounts a rule may name. */
export async function getBankRules(): Promise<{ rules: BankRule[]; can: { manage: boolean }; accounts: Account[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [rules, accounts] = await Promise.all([ledgerly.bankRules(ctx), ledgerly.accounts(ctx)]);
    return { rules: rules.data, can: rules.can, accounts: accounts.data };
  });
}

export async function getStatement(id: string, status: string): Promise<{ lines: StatementLines; accounts: Account[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi(async (ctx) => {
      const lines = await ledgerly.statementLines(id, status, ctx);
      return { lines, accounts: lines.can.review ? (await ledgerly.accounts(ctx)).data : [] };
    });
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** A money account's reconciliations: the one in progress, the history, and where the last one ended. */
export async function getReconciliations(accountId: string): Promise<Reconciliations | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.reconciliations(accountId, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

export async function getReconciliation(id: string): Promise<Reconciliation | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.reconciliation(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** A cash box's counts, newest first. */
export async function getCashCounts(accountId: string): Promise<{ data: CashCount[]; can: { count: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.cashCounts(accountId, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** A bank account's cheques written (out) or received (in). */
export async function getCheques(accountId: string, direction: "out" | "in"): Promise<ChequeRegister | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.cheques(accountId, direction, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

export async function getItems(q: { search?: string; archived?: boolean }): Promise<{ data: Item[]; can: { edit: boolean; adjust: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], can: { edit: false, adjust: false }, demo: true };
  return { ...(await withApi((ctx) => ledgerly.items({ search: q.search || undefined, include_archived: q.archived ? "1" : undefined }, ctx))), demo: false };
}

export async function getItem(id: string): Promise<Item | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.item(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function getWarehouses(): Promise<Warehouse[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.warehouses(ctx))).data;
}

export async function getStock(): Promise<StockOnHand | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.stock(ctx));
}

/** 074: stock on hand by batch, the first to expire first; within_days keeps only what expires by then. */
export async function getStockBatches(withinDays?: number) {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.stockBatches({ within_days: withinDays }, ctx));
}

export async function getReorder() {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.reorder(ctx))).data;
}

export async function getInventoryValuation(asOf?: string) {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.inventoryValuation(asOf, ctx));
}

export async function getBinCard(q: { item_id: string; warehouse_id: string; from: string; to: string }): Promise<BinCard | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.binCard(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/**
 * The suppliers a form offers to choose from (an item's preferred supplier, 075), or none for a role that may not read
 * contacts: the form still opens, and the API checks again on save.
 */
/** Active customers to choose from, or none for a role that cannot list them (a 403 never breaks the page). */
/** 087: an item's parts list and builds; null in the demo or for a role that cannot see stock. */
export async function getItemAssembly(id: string): Promise<ItemAssembly | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.itemAssembly(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && (error.status === 403 || error.status === 404)) return null;
      throw error;
    }
  });
}

/** 084: the member's own requisitions (everyone's for those who decide or order); null for a role that cannot ask. */
export async function getRequisitions(q: ListQuery = {}): Promise<RequisitionList | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.requisitions(ctx, q);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return null;
      throw error;
    }
  });
}

/** One requisition, or null when it is not found for this member. */
export async function getRequisition(id: string): Promise<PurchaseRequisition | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.requisition(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && (error.status === 404 || error.status === 403)) return null;
      throw error;
    }
  });
}

/** 083: the member's own expense claims (everyone's for someone who sees bills); null for a role that cannot claim. */
export async function getExpenseClaims(q: ListQuery = {}): Promise<ExpenseClaimList | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.expenseClaims(ctx, q);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return null;
      throw error;
    }
  });
}

/** One expense claim, or null when it is not found for this member. */
export async function getExpenseClaim(id: string): Promise<ExpenseClaim | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.expenseClaim(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && (error.status === 404 || error.status === 403)) return null;
      throw error;
    }
  });
}

/** The price lists (080); none in the demo. */
export async function getPriceLists(): Promise<{ data: PriceList[]; can: { manage: boolean } }> {
  await connection();
  if (isDemoMode()) return { data: [], can: { manage: false } };
  return withApi((ctx) => ledgerly.priceLists(ctx));
}

/** One price list, or null when it is not one of this company's. */
export async function getPriceList(id: string): Promise<PriceListDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.priceList(id, ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 404) return null;
      throw error;
    }
  });
}

/** Price lists in use to put a customer on, or none for a role that cannot see them (a 403 never breaks the page). */
export async function getPriceListChoices(): Promise<{ id: string; name: string; currency: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  return withApi(async (ctx) => {
    try {
      return (await ledgerly.priceLists(ctx)).data.filter((l) => l.is_active).map((l) => ({ id: l.id, name: l.name, currency: l.currency }));
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return [];
      throw error;
    }
  });
}

export async function getCustomerChoices(): Promise<{ id: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  return withApi(async (ctx) => {
    try {
      return (await ledgerly.customers({}, ctx)).data.map((c) => ({ id: c.id, name: c.name }));
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return [];
      throw error;
    }
  });
}

export async function getSupplierChoices(): Promise<{ id: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  return withApi(async (ctx) => {
    try {
      return (await ledgerly.suppliers({}, ctx)).data.map((s) => ({ id: s.id, name: s.name }));
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return [];
      throw error;
    }
  });
}

export async function getSuppliers(q: { search?: string; archived?: boolean }): Promise<{ data: Supplier[]; can: { create: boolean; edit: boolean; bill: boolean }; demo: boolean }> {
  await connection();
  if (isDemoMode()) return { data: [], can: { create: false, edit: false, bill: false }, demo: true };
  const result = await withApi((ctx) => ledgerly.suppliers({ search: q.search || undefined, include_archived: q.archived ? "1" : undefined }, ctx));
  return { ...result, demo: false };
}

/** One supplier, or null when it is not one of this company's. */
export async function getSupplier(id: string): Promise<(Supplier & { demo: boolean }) | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return { ...(await withApi((ctx) => ledgerly.supplier(id, ctx))), demo: false };
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** 093: the expense accounts a grant's budget lines can be on. */
export async function getExpenseAccounts(): Promise<{ id: string; code: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  const { data } = await withApi((ctx) => ledgerly.accounts(ctx));
  return data.filter((a) => a.is_active && a.type === "expense").map((a) => ({ id: a.id, code: a.code, name: a.name }));
}

/** 093: a grant's donor report; null when the project is not a grant, or not this company's. */
export async function getDonorReport(id: string, q: { from?: string; to?: string }): Promise<DonorReport | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.donorReport(id, q, ctx));
  } catch (error) {
    if (error instanceof ApiError && [403, 404, 422].includes(error.status)) return null;
    throw error;
  }
}

/** Expense and asset accounts a supplier's bills may default to. */
export async function getCostAccounts(): Promise<{ id: string; code: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  const { data } = await withApi((ctx) => ledgerly.accounts(ctx));
  return data.filter((a) => a.is_active && (a.type === "expense" || a.type === "asset")).map((a) => ({ id: a.id, code: a.code, name: a.name }));
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getSupplierCredits(q: ListQuery = {}) {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.supplierCredits(ctx, q)));
}

/** A supplier's open bills in one currency, by due date: what a supplier credit can be applied to. */
export async function getOpenBills(contactId: string, currency: string): Promise<Bill[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.openBills(contactId, ctx))).data.filter((b) => b.currency === currency);
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getBills(q: ListQuery = {}): Promise<Paged<Bill>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.bills(ctx, q)));
}

/** One bill, or null when it is not one of this company's. */
/** 095: the lease register; null in the demo without an API, or when the role cannot see it. */
export async function getLeases(): Promise<LeaseRegister | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.leases(ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return null;
    throw error;
  }
}

/** 095: one lease with its schedule; null when it is not one of this company's. */
export async function getLease(id: string): Promise<LeaseDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.lease(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && [403, 404].includes(error.status)) return null;
    throw error;
  }
}

type Choice = { id: string; code: string; name: string };
/** 095: what a lease is set up and paid with: the accounts by kind, the suppliers (lessors) and the bank accounts in the base currency. */
export async function getLeaseOptions(): Promise<{ liability: Choice[]; expense: Choice[]; fixedAsset: Choice[]; accumulated: Choice[]; banks: Choice[]; lessors: { id: string; name: string }[] }> {
  await connection();
  if (isDemoMode()) return { liability: [], expense: [], fixedAsset: [], accumulated: [], banks: [], lessors: [] };
  const [{ data }, currencies, suppliers] = await withApi((ctx) => Promise.all([ledgerly.accounts(ctx), ledgerly.currencies(ctx), ledgerly.suppliers({}, ctx)]));
  const live = data.filter((a) => a.is_active);
  const pick = (f: (a: (typeof live)[number]) => boolean) => live.filter(f).map((a) => ({ id: a.id, code: a.code, name: a.name }));
  return {
    liability: pick((a) => a.type === "liability" && !a.system_key), expense: pick((a) => a.type === "expense"),
    fixedAsset: pick((a) => a.type === "asset" && a.subtype === "fixed_asset"), accumulated: pick((a) => a.type === "asset" && a.subtype === "contra_asset" && a.system_key !== "doubtful_debts_allowance"),
    banks: pick((a) => ["bank", "cash", "mobile_money"].includes(a.subtype ?? "") && (a.currency ?? currencies.base_currency) === currencies.base_currency),
    lessors: suppliers.data.map((s) => ({ id: s.id, name: s.name })),
  };
}

/** 094: the company's tags; null in the demo without an API. */
/** 098: the bins of every warehouse, with the items kept in each; null in the demo. */
export async function getBins(): Promise<{ data: Bin[]; can: { manage: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.bins(ctx));
}

export async function getTags(): Promise<{ data: Tag[]; can: { manage: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.tags(ctx));
}

/** 094: a transaction's tags and the ones it can have; null in the demo, or when the role cannot see them. */
export async function getTransactionTags(kind: TagKind, id: string): Promise<TransactionTags | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.transactionTags(kind, id, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 404)) return null;
    throw error;
  }
}

/** 094: profit by tag for a period; null in the demo, or when the role cannot see reports. */
export async function getTagReport(from: string, to: string): Promise<TagReport | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.tagReport(from, to, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) return null;
    throw error;
  }
}

/** The files kept with a document (063); null in the demo without an API, or when the role cannot see them. */
export async function getAttachments(kind: AttachmentKind, id: string): Promise<{ data: Attachment[]; can: { add: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.attachments(kind, id, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 404)) return null;
    throw error;
  }
}

/** The post-dated cheque register (062) with what its form offers; null in the demo without an API. */
export async function getPostDatedCheques(): Promise<{
  cheques: PostDatedCheques; customers: { id: string; name: string }[]; suppliers: { id: string; name: string }[];
  banks: { id: string; code: string; name: string }[]; currencies: string[];
} | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [cheques, customers, suppliers, accounts, currencies] = await Promise.all([
      ledgerly.postDatedCheques(ctx), ledgerly.customers({}, ctx), ledgerly.suppliers({}, ctx), ledgerly.accounts(ctx), ledgerly.currencies(ctx),
    ]);
    return {
      cheques,
      customers: customers.data.map((c) => ({ id: c.id, name: c.name })),
      suppliers: suppliers.data.map((s) => ({ id: s.id, name: s.name })),
      banks: accounts.data.filter((a) => a.is_active && a.subtype === "bank").map((a) => ({ id: a.id, code: a.code, name: a.name })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
    };
  });
}

/** A fiscal year's tax depreciation beside the books (061); null in the demo without an API. */
export async function getTaxDepreciation(yearEnd?: string): Promise<TaxDepreciationYear | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.taxDepreciation(yearEnd, ctx));
  } catch (error) {
    // A date that is not a month end: the current fiscal year instead.
    if (error instanceof ApiError && error.status === 422) return withApi((ctx) => ledgerly.taxDepreciation(undefined, ctx));
    throw error;
  }
}

/** Landed costs of a bill (060); null in the demo without an API. */
export async function getLandedCosts(billId: string): Promise<LandedCosts | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.landedCosts(billId, ctx));
}

/** Who created, changed, posted and corrected a document, and what changed; null where the role cannot see it. */
export async function getDocumentHistory(kind: "invoice" | "bill", id: string): Promise<DocumentHistory | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => (kind === "bill" ? ledgerly.billHistory(id, ctx) : ledgerly.invoiceHistory(id, ctx)));
  } catch (error) {
    if (error instanceof ApiError && [403, 404].includes(error.status)) return null;
    throw error;
  }
}

export async function getBill(id: string): Promise<(BillDetail & { demo: boolean }) | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return { ...(await withApi((ctx) => ledgerly.bill(id, ctx))), demo: false };
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** One invoice, or null when it is not one of this company's. */
export async function getInvoice(id: string): Promise<(InvoiceDetail & { demo: boolean }) | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return { ...(await withApi((ctx) => ledgerly.invoice(id, ctx))), demo: false };
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export interface ReceiveOptions {
  customers: { id: string; name: string }[];
  customer: { id: string; name: string; currency: string | null } | null;
  /** The chosen customer's open invoices, oldest first. */
  invoices: Invoice[];
  accounts: { id: string; code: string; name: string; currency: string | null }[];
  currencies: string[];
  baseCurrency: string;
  demo: boolean;
}

/** What the receive-payment screen offers: customers, the chosen one's open invoices, accounts money can go into. */
export async function getReceiveOptions(customerId: string | null): Promise<ReceiveOptions> {
  await connection();
  if (isDemoMode()) {
    return { customers: demoCustomers.map((c) => ({ id: c.id, name: c.name })), customer: null, invoices: [], accounts: [], currencies: ["ETB"], baseCurrency: "ETB", demo: true };
  }
  return withApi(async (ctx) => {
    const [customers, accounts, currencies] = await Promise.all([ledgerly.customers({}, ctx), ledgerly.accounts(ctx), ledgerly.currencies(ctx)]);
    const customer = customers.data.find((c) => c.id === customerId) ?? null;
    const invoices = customer ? (await ledgerly.openInvoices(customer.id, ctx)).data : [];
    return {
      customers: customers.data.map((c) => ({ id: c.id, name: c.name })),
      customer: customer ? { id: customer.id, name: customer.name, currency: customer.currency } : null,
      invoices,
      accounts: accounts.data
        .filter((a) => a.is_active && (a.system_key === "undeposited_funds" || ["bank", "cash", "mobile_money"].includes(a.subtype ?? "")))
        .map((a) => ({ id: a.id, code: a.code, name: a.name, currency: a.currency })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
      baseCurrency: currencies.base_currency,
      demo: false,
    };
  });
}

export interface PayOptions {
  suppliers: { id: string; name: string }[];
  supplier: { id: string; name: string; currency: string | null; tax_id: string | null } | null;
  /** The chosen supplier's open bills, by due date. */
  bills: Bill[];
  accounts: { id: string; code: string; name: string; currency: string | null }[];
  currencies: string[];
  baseCurrency: string;
  advice: WithholdingAdvice | null;
  demo: boolean;
}

/** What the pay-bills screen offers: suppliers, the chosen one's open bills and withholding advice, accounts to pay from. */
export async function getPayOptions(supplierId: string | null): Promise<PayOptions> {
  await connection();
  if (isDemoMode()) {
    return { suppliers: [], supplier: null, bills: [], accounts: [], currencies: ["ETB"], baseCurrency: "ETB", advice: null, demo: true };
  }
  return withApi(async (ctx) => {
    const [suppliers, accounts, currencies] = await Promise.all([ledgerly.suppliers({}, ctx), ledgerly.accounts(ctx), ledgerly.currencies(ctx)]);
    const supplier = suppliers.data.find((s) => s.id === supplierId) ?? null;
    const [bills, advice] = supplier
      ? await Promise.all([ledgerly.openBills(supplier.id, ctx), ledgerly.withholdingAdvice(supplier.id, ctx)])
      : [{ data: [] as Bill[] }, null];
    return {
      suppliers: suppliers.data.map((s) => ({ id: s.id, name: s.name })),
      supplier: supplier ? { id: supplier.id, name: supplier.name, currency: supplier.currency, tax_id: supplier.tax_id } : null,
      bills: bills.data,
      accounts: accounts.data
        .filter((a) => a.is_active && ["bank", "cash", "mobile_money"].includes(a.subtype ?? ""))
        .map((a) => ({ id: a.id, code: a.code, name: a.name, currency: a.currency })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
      baseCurrency: currencies.base_currency,
      advice,
      demo: false,
    };
  });
}

/** Bank, cash and wallet accounts in one currency: where a refund is paid from or into. */
export async function getMoneyAccounts(currency: string): Promise<{ id: string; code: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  const [{ data }, currencies] = await withApi((ctx) => Promise.all([ledgerly.accounts(ctx), ledgerly.currencies(ctx)]));
  return data.filter((a) => a.is_active && ["bank", "cash", "mobile_money"].includes(a.subtype ?? "") && (a.currency ?? currencies.base_currency) === currency)
    .map((a) => ({ id: a.id, code: a.code, name: a.name }));
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getRefunds(q: ListQuery = {}) {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.refunds(ctx, q)));
}

export async function getRefund(id: string) {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.refund(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getPaymentsMade(q: ListQuery = {}): Promise<Paged<PaymentReceived>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.paymentsMade(ctx, q)));
}

/** Module 3: scheduled supplier payments (103), those still to pay first. */
export async function getScheduledPayments(status?: "scheduled" | "paid" | "cancelled"): Promise<ScheduledPayments | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.scheduledPayments({ status }, ctx));
}

/** For confirming a scheduled payment: what the country's rules say about withholding on it, and the base currency. */
export async function getConfirmContext(supplierId: string): Promise<{ advice: WithholdingAdvice | null; baseCurrency: string }> {
  await connection();
  if (isDemoMode()) return { advice: null, baseCurrency: "ETB" };
  return withApi(async (ctx) => {
    const [advice, currencies] = await Promise.all([ledgerly.withholdingAdvice(supplierId, ctx).catch(() => null), ledgerly.currencies(ctx)]);
    return { advice, baseCurrency: currencies.base_currency };
  });
}

export async function getScheduledPayment(id: string): Promise<ScheduledPaymentDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.scheduledPayment(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function getPaymentMade(id: string) {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.paymentMade(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

export async function getApAgeing(asOf?: string, periods: { interval?: number; periods?: number } = {}): Promise<ArAgeing | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.apAgeing(asOf, ctx, periods));
}

export async function getSupplierStatement(id: string, q: { from?: string; to?: string }) {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.supplierStatement(id, q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getCreditNotes(q: ListQuery = {}): Promise<Paged<(Invoice & { original?: { id: string; number: string | null } | null })>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.creditNotes(ctx, q)));
}

/** A customer's open invoices in one currency, oldest first: where a credit note's leftover credit can go. */
export async function getOpenInvoices(contactId: string, currency: string): Promise<Invoice[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.openInvoices(contactId, ctx))).data.filter((i) => i.currency === currency);
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getPaymentsReceived(q: ListQuery = {}): Promise<Paged<PaymentReceived>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.paymentsReceived(ctx, q)));
}

export async function getPaymentReceived(id: string): Promise<(PaymentReceived & { unallocated: string; can: { allocate: boolean; reverse: boolean; refund: boolean } }) | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.paymentReceived(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** The demo sign-in buttons, or null when demo sign-in is off. Never breaks the sign-in page. */
export async function getDemoOptions(): Promise<DemoOptions | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    const options = await ledgerly.demoOptions();
    return options.roles.length > 0 ? options : null;
  } catch {
    return null;
  }
}

export async function getArAgeing(asOf?: string, periods: { interval?: number; periods?: number } = {}): Promise<ArAgeing | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.arAgeing(asOf, ctx, periods));
}

export async function getCustomerStatement(id: string, q: { from?: string; to?: string }): Promise<CustomerStatement | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.customerStatement(id, q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** Quotes or sales orders, newest first; none in the demo without an API. */
export async function getQuotes(kind: QuoteKind, q: ListQuery = {}): Promise<Paged<QuoteSummary>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.quotes(kind, ctx, q)));
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getPurchaseOrders(q: ListQuery = {}): Promise<Paged<PurchaseOrderSummary>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.purchaseOrders(ctx, q)));
}

/** One purchase order, or null when it is not one of this company's (068). */
export async function getPurchaseOrder(id: string): Promise<PurchaseOrderDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.purchaseOrder(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** The goods received notes, newest first (069). */
/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getGoodsReceipts(q: ListQuery = {}): Promise<Paged<GoodsReceiptSummary>> {
  await connection();
  return paged(isDemoMode() ? { data: [] } : await withApi((ctx) => ledgerly.goodsReceipts(ctx, q)));
}

/** One goods received note, or null when it is not one of this company's (069). */
export async function getGoodsReceipt(id: string): Promise<GoodsReceipt | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.goodsReceipt(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) return null;
    throw error;
  }
}

/** Goods received not invoiced, line by line (069). */
export async function getUnbilledGoods(): Promise<UnbilledGoods | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.unbilledGoods(ctx));
}

/** What the issued purchase orders still wait for, and the stock their items have (068). */
export async function getOpenPurchaseOrders(): Promise<BackOrders | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.openPurchaseOrders(ctx));
}

/** What the confirmed sales orders still owe, and the stock their items have (067). */
export async function getBackOrders(): Promise<BackOrders | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.backOrders(ctx));
}

export async function getQuote(kind: QuoteKind, id: string): Promise<QuoteDetail | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.quote(kind, id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** A page of the list for a period (lib/lists.ts); every row, newest first, when none is given. */
export async function getInvoices(q: ListQuery = {}): Promise<Paged<Invoice>> {
  await connection();
  return paged(isDemoMode() ? { data: demoInvoices } : await withApi((ctx) => ledgerly.invoices(ctx, q)));
}

/** The general ledger for a period (one account, or all; optionally one location, class or project). null in the demo. */
/** The Journal report for a period (open ends for All dates); null in the demo or for a period that ends before it starts. */
export async function getJournalReport(q: { from?: string; to?: string; source?: string }): Promise<JournalReport | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.journalReport(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return null;
    throw error;
  }
}

export async function getGeneralLedger(q: Record<string, string | undefined>): Promise<GeneralLedger | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.generalLedger(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) return null;
    throw error;
  }
}

/** Revaluation runs, newest first. */
export async function getRevaluations(): Promise<{ data: RevaluationRun[]; can: { revalue: boolean } } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.revaluations(ctx));
}

/** What a revaluation at a date would restate, or the API's message (a missing rate, a date not before today). */
export async function getRevaluationPreview(asOf: string, rates: Record<string, string>): Promise<{ preview?: RevaluationPreview; error?: string; missing?: string[] }> {
  await connection();
  if (isDemoMode()) return {};
  try {
    return { preview: await withApi((ctx) => ledgerly.revaluationPreview(asOf, rates, ctx)) };
  } catch (error) {
    if (error instanceof ApiError && error.status === 422) {
      const errors = ((error.body ?? {}) as { errors?: Record<string, string[]> }).errors ?? {};
      return { error: Object.values(errors)[0]?.[0] ?? error.message, missing: Object.keys(errors).filter((k) => k.startsWith("rates.")).map((k) => k.slice(6)) };
    }
    throw error;
  }
}

/** The auditor's worksheet for a fiscal year; null in the demo or when it is not this company's. */
export async function getAdjustedTrialBalance(id: string): Promise<AdjustedTrialBalance | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.adjustedTrialBalance(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}

/** The fiscal years, and (for the one that can be closed now) what closing it moves to retained earnings. */
export async function getYearEnd(): Promise<{ years: FiscalYear[]; closing: { year: FiscalYear; net_profit: string; lines: { code: string; name: string; type: string; balance: string }[] } | null } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const years = (await ledgerly.fiscalYears(ctx)).data;
    const closable = years.find((y) => y.can_close);
    if (!closable) return { years, closing: null };
    try {
      const preview = await ledgerly.yearClosing(closable.id, ctx);
      return { years, closing: { year: closable, ...preview } };
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return { years, closing: null };
      throw error;
    }
  });
}

/** The close checklist for a period end; null in the demo or for roles that do not close periods. */
export async function getCloseChecklist(periodEnd?: string): Promise<CloseChecklist | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.closeChecklist(periodEnd, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 422)) return null;
    throw error;
  }
}

export async function getTrialBalance(asOf: string): Promise<TrialBalanceRow[] | null> {
  await connection();
  if (isDemoMode()) return null;
  return (await withApi((ctx) => ledgerly.trialBalance(asOf, ctx))).data;
}

/**
 * What report periods are worked out from: the company's today (its time zone), its calendar, its fiscal year start
 * and fiscal years. One settings request; a role that may not read the settings gets the fiscal years alone.
 */
export async function getPeriodContext(): Promise<PeriodContext> {
  await connection();
  if (isDemoMode()) return { today: todayIn(demoCompanySettings.timezone), calendar: demoCompanySettings.calendar, fiscalStartMonth: demoCompanySettings.fiscal_year_start_month, fiscalYears: demoCompanySettings.fiscal_years };
  try {
    const s = await withApi((ctx) => settingsOf(ctx.token, ctx.companyId));
    return { today: todayIn(s.timezone), calendar: s.calendar, fiscalStartMonth: s.fiscal_year_start_month, fiscalYears: s.fiscal_years };
  } catch (error) {
    if (!(error instanceof ApiError)) throw error;
    const years = await withApi((ctx) => fiscalYearsOf(ctx.token, ctx.companyId)).catch(() => ({ data: [] as FiscalYear[] }));
    const first = years.data[0]?.starts_on;
    return { today: todayIn(), calendar: "gregorian", fiscalStartMonth: first ? Number(first.slice(5, 7)) : 1, fiscalYears: years.data };
  }
}

type Query = Record<string, string | string[] | undefined>;

/**
 * The period a report shows (lib/periodParams.ts): the preset or dates in its URL, else `fallback` (by default the
 * current fiscal year to date). `from`/`to` are what the API is sent (open ends widened); `picked` is what the page and
 * its header say ("All dates", "Last month").
 */
export async function getReportPeriod(q: Query, fallback: RangePreset = "this_fiscal_year_to_date", allowed?: readonly RangePreset[]):
  Promise<{ from: string; to: string; picked: PickedRange; ctx: PeriodContext }> {
  const ctx = await getPeriodContext();
  const picked = pickRange(q, ctx, fallback, allowed);
  return { ...apiDates(picked), picked, ctx };
}

/** The date a report "as of" shows: the preset or date in its URL, else `fallback` (today). */
/** A transaction report (TransactionReport in the API); null in the demo, or when the role may not see it. */
export async function getTransactionReport(q: { report: string; from?: string; to?: string; contact_id?: string; status?: string; created_by?: string }): Promise<TransactionReportData | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.transactionReport(q, ctx));
  } catch (error) {
    if (error instanceof ApiError && (error.status === 403 || error.status === 422)) return null;
    throw error;
  }
}

export async function getReportAsOf(q: Query, fallback: AsOfPreset = "today"): Promise<{ asOf: string; picked: PickedAsOf; ctx: PeriodContext }> {
  const ctx = await getPeriodContext();
  const picked = pickAsOf(q, ctx, fallback);
  return { asOf: picked.asOf, picked, ctx };
}

/** Module 10 statements; null in the demo, or when the period is refused. */
async function statement<T>(call: (ctx: { token: string; companyId: string }) => Promise<T>): Promise<T | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi(call);
  } catch (error) {
    if (error instanceof ApiError && (error.status === 422 || error.status === 403)) return null;
    throw error;
  }
}

export const getBalanceSheet = (asOf: string): Promise<BalanceSheet | null> => statement((ctx) => ledgerly.balanceSheet(asOf, ctx));
export const getCashFlow = (q: { from: string; to: string }): Promise<CashFlowStatement | null> => statement((ctx) => ledgerly.cashFlow(q, ctx));
/** Module 10: the notes to the financial statements for a period. */
export const getNotes = (q: { from: string; to: string }): Promise<FinancialNotes | null> => statement((ctx) => ledgerly.notes(q, ctx));
/** Module 10: the same period by the direct method. */
export const getDirectCashFlow = (q: { from: string; to: string }): Promise<DirectCashFlow | null> => statement((ctx) => ledgerly.directCashFlow(q, ctx));
/** The home page's key figures, or none for a role that may not read reports (the tiles are left out). */
/** An invoice's bad-debt write-offs (078); null in the demo or for a role that cannot see them. */
export async function getWriteOffs(invoiceId: string): Promise<InvoiceWriteOffs | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.writeOffs(invoiceId, ctx);
    } catch (error) {
      if (error instanceof ApiError && (error.status === 403 || error.status === 404)) return null;
      throw error;
    }
  });
}

/** Interest on late payment for an invoice (079); null in the demo or for a role that cannot see it. */
export async function getLateInterest(invoiceId: string): Promise<LateInterestPreview | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.lateInterest(invoiceId, ctx);
    } catch (error) {
      if (error instanceof ApiError && (error.status === 403 || error.status === 404)) return null;
      throw error;
    }
  });
}

export async function getKpis(): Promise<Kpis | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    try {
      return await ledgerly.kpis(ctx);
    } catch (error) {
      if (error instanceof ApiError && error.status === 403) return null;
      throw error;
    }
  });
}

export const getCashForecast = (weeks: number): Promise<CashForecast | null> => statement((ctx) => ledgerly.cashForecast(weeks, ctx));
export const getChangesInEquity = (q: { from: string; to: string }): Promise<ChangesInEquity | null> => statement((ctx) => ledgerly.changesInEquity(q, ctx));
export const getProfitByDimension = (q: { kind: string; from: string; to: string }): Promise<ProfitByDimension | null> => statement((ctx) => ledgerly.profitByDimension(q, ctx));
export const getCustomReport = (q: Record<string, string | undefined>) => statement((ctx) => ledgerly.customReport(q, ctx));

/**
 * The subscription, for the notice across the app and Settings. Never redirects and never fails a page: anything
 * unexpected (signed out, no company, the demo without an API) is simply no subscription to show.
 */
export async function getSubscription(): Promise<Subscription | null> {
  await connection();
  if (isDemoMode()) return null;
  const { token, companyId } = await getSession();
  if (!token || !companyId) return null;
  return subscriptionOf(token, companyId).catch(() => null);
}
export const getDocumentAnalysis = (side: "sales" | "purchases", q: { group: string; from: string; to: string }): Promise<DocumentAnalysis | null> =>
  statement((ctx) => ledgerly.documentAnalysis(side, q, ctx));
export const getAuditLog = (q: Record<string, string | undefined>): Promise<AuditLog | null> => statement((ctx) => ledgerly.auditLog(q, ctx));
export const verifyAuditLog = () => statement((ctx) => ledgerly.verifyAuditLog(ctx));
export const getStockMovement = (q: { from: string; to: string; warehouse_id?: string }) => statement((ctx) => ledgerly.stockMovement(q, ctx));
export const getStockAgeing = (q: { as_of?: string; warehouse_id?: string }) => statement((ctx) => ledgerly.stockAgeing(q, ctx));
export const getSavedReports = () => statement(async (ctx) => (await ledgerly.savedReports(ctx)).data);
export async function getSavedReport(id: string) {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.savedReport(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
  }
}
export const getMembers = () => statement((ctx) => ledgerly.members(ctx));
export const getFinancialHealth = (q: { from: string; to: string }): Promise<FinancialHealth | null> => statement((ctx) => ledgerly.financialHealth(q, ctx));

export async function getProfitAndLoss(q: { from: string; to: string; compare_from?: string; compare_to?: string; basis?: "accrual" | "cash" }): Promise<ProfitAndLoss> {
  await connection();
  if (isDemoMode()) return demoProfitAndLoss;
  return withApi((ctx) => ledgerly.profitAndLoss(q, ctx));
}

export interface ShellContext {
  companyName: string;
  companyNote: string;
  userName: string;
  demo: boolean;
  /** Every company the person can switch to, the current one included; archived ones are read-only. */
  companies: { id: string; name: string; archived?: boolean }[];
  companyId: string;
  organisationId: string | null;
}

/** The company being worked on, and who is signed in, for the app shell. */
/** Today in the company's time zone: the same date the API takes as today (CurrentCompany::today()). */
export async function getCompanyToday(): Promise<string> {
  return (await getCompanyDay()).today;
}

/** Today in the company's time zone and the calendar its dates are shown in, from one settings request. */
export async function getCompanyDay(): Promise<{ today: string; calendar: CompanySettings["calendar"] }> {
  await connection();
  if (isDemoMode()) return { today: todayIn(demoCompanySettings.timezone), calendar: demoCompanySettings.calendar };
  try {
    const settings = await withApi((ctx) => settingsOf(ctx.token, ctx.companyId));
    return { today: todayIn(settings.timezone), calendar: settings.calendar };
  } catch (error) {
    // A role without company.view still gets a date (Gregorian, the default); redirects pass through.
    if (error instanceof ApiError) return { today: todayIn(), calendar: "gregorian" };
    throw error;
  }
}

export async function getShellContext(): Promise<ShellContext> {
  await connection();
  if (isDemoMode()) {
    return {
      companyName: demoCompany.legal_name,
      companyNote: `${demoCompany.city} · ${demoCompany.base_currency} · VAT registered`,
      userName: "Hana Bekele",
      demo: true,
      companies: [{ id: demoCompany.id, name: demoCompany.trading_name }],
      companyId: demoCompany.id,
      organisationId: null,
    };
  }
  const { name, companyId, demo } = await getSession();
  const { data } = await withApi(({ token }) => companiesOf(token));
  const company: Company | undefined = data.find((c) => c.id === companyId);
  if (!company) redirect("/onboarding");
  // The demo was started fresh by its owner: everyone in the demo goes on in the new demo company.
  if (demo && company.status === "archived" && data.some((c) => c.status !== "archived")) redirect("/demo-company");
  return {
    companyName: company.trading_name ?? company.legal_name,
    companyNote: company.base_currency,
    userName: name ?? "",
    demo: false,
    // Earlier demo companies are not listed in the demo.
    companies: data.filter((c) => !(demo && c.status === "archived")).map((c) => ({ id: c.id, name: c.trading_name ?? c.legal_name, archived: c.status === "archived" })),
    companyId: company.id,
    organisationId: company.organisation_id,
  };
}
