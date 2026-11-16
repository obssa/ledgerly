import "server-only";
import { redirect } from "next/navigation";
import { connection } from "next/server";
import { ApiError, isDemoMode, ledgerly, type Account, type ArAgeing, type BankAccount, type BankRegister, type StatementLines, type Reconciliation, type Reconciliations, type BinCard, type Item, type StockOnHand, type Warehouse, type Bill, type Expense, type WithholdingAdvice, type BillDetail, type Supplier, type Company, type CompanySettings, type Currencies, type Customer, type CustomerStatement, type DemoOptions, type Dimension, type Invoice, type InvoiceDetail, type Numbering, type OpeningBalances, type PaymentReceived, type ProfitAndLoss } from "./api";
import { demoCompany, demoCompanySettings, demoCurrencies, demoCustomers, demoDimensions, demoTaxRates, demoNumbering, demoInvoices, demoProfitAndLoss } from "./demo";
import { getSession } from "./session";

/**
 * Server-side data access. In demo mode returns sample data; otherwise calls the API with the session's
 * token and selected company (HttpOnly cookies set by the sign-in flow, src/app/(auth)/actions.ts).
 *
 * connection() first: whether this is demo mode is decided per request, so one built image can run either
 * way. Callers render inside a <Suspense> boundary (src/app/(app)/layout.tsx).
 */
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

/** The profile and fiscal settings of the company being worked in. In the demo nothing can be changed. */
export async function getCompanySettings(): Promise<CompanySettings & { demo: boolean }> {
  await connection();
  if (isDemoMode()) return { ...demoCompanySettings, demo: true };
  return { ...(await withApi((ctx) => ledgerly.companySettings(ctx))), demo: false };
}

export async function getCurrencies(): Promise<Currencies> {
  await connection();
  if (isDemoMode()) return demoCurrencies;
  return withApi((ctx) => ledgerly.currencies(ctx));
}

/** The opening balances and the accounts they can be entered on. null in the demo, which has no ledger to change. */
export async function getOpeningBalances(): Promise<{ opening: OpeningBalances; accounts: Account[] } | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi(async (ctx) => {
    const [opening, accounts] = await Promise.all([ledgerly.openingBalances(ctx), ledgerly.accounts(ctx)]);
    return { opening, accounts: accounts.data };
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
  customers: { id: string; name: string; tax_id: string | null; currency: string | null; payment_terms_days: number; default_account_id?: string | null }[];
  accounts: { id: string; code: string; name: string }[];
  taxRates: { id: string; code: string; name: string; rate: string }[];
  currencies: string[];
  baseCurrency: string;
  /** Manual numbering: the number is typed from the invoice book. */
  manualNumbers: boolean;
  /** For an expense: the bank, cash and wallet accounts it can be paid from. */
  moneyAccounts?: { id: string; code: string; name: string; currency: string | null }[];
  /** Items to pick on a line, and the warehouses stock lines use. */
  items?: { id: string; name: string; type: string; unit: string; price: string | null; account_id: string | null }[];
  warehouses?: { id: string; name: string }[];
  demo: boolean;
}

/** What the new-invoice screen offers: active customers, income accounts, sales tax rates in force, currencies. */
export async function getInvoiceOptions(): Promise<InvoiceOptions> {
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
  return withApi(async (ctx) => {
    const today = new Date().toISOString().slice(0, 10);
    const [customers, accounts, rates, currencies, numbering, items, warehouses] = await Promise.all([
      ledgerly.customers({}, ctx), ledgerly.accounts(ctx), ledgerly.taxRates(ctx), ledgerly.currencies(ctx), ledgerly.numbering(ctx),
      ledgerly.items({}, ctx), ledgerly.warehouses(ctx),
    ]);
    return {
      customers: customers.data.map((c) => ({ id: c.id, name: c.name, tax_id: c.tax_id, currency: c.currency, payment_terms_days: c.payment_terms_days })),
      accounts: accounts.data.filter((a) => a.type === "income" && a.is_active).map((a) => ({ id: a.id, code: a.code, name: a.name })),
      taxRates: rates.data
        .filter((r) => r.kind !== "withholding" && r.effective_from <= today && (r.effective_to === null || r.effective_to >= today))
        .map((r) => ({ id: r.id, code: r.code, name: r.name, rate: r.rate })),
      currencies: [currencies.base_currency, ...currencies.data.filter((c) => c.is_enabled).map((c) => c.currency)],
      baseCurrency: currencies.base_currency,
      manualNumbers: numbering.data.find((n) => n.doc_type === "invoice")?.mode === "manual",
      items: items.data.map((i) => ({ id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.sale_price, account_id: i.income_account_id })),
      warehouses: warehouses.data.filter((w) => w.is_active).map((w) => ({ id: w.id, name: w.name })),
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
  return withApi(async (ctx) => {
    const today = new Date().toISOString().slice(0, 10);
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
      items: items.data.map((i) => ({ id: i.id, name: i.name, type: i.type, unit: i.unit, price: i.purchase_price, account_id: i.expense_account_id })),
      warehouses: warehouses.data.filter((w) => w.is_active).map((w) => ({ id: w.id, name: w.name })),
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

export async function getExpenses(): Promise<Expense[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.expenses(ctx))).data;
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

/** Expense and asset accounts a supplier's bills may default to. */
export async function getCostAccounts(): Promise<{ id: string; code: string; name: string }[]> {
  await connection();
  if (isDemoMode()) return [];
  const { data } = await withApi((ctx) => ledgerly.accounts(ctx));
  return data.filter((a) => a.is_active && (a.type === "expense" || a.type === "asset")).map((a) => ({ id: a.id, code: a.code, name: a.name }));
}

export async function getSupplierCredits() {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.supplierCredits(ctx))).data;
}

/** A supplier's open bills in one currency, by due date: what a supplier credit can be applied to. */
export async function getOpenBills(contactId: string, currency: string): Promise<Bill[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.openBills(contactId, ctx))).data.filter((b) => b.currency === currency);
}

export async function getBills(): Promise<Bill[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.bills(ctx))).data;
}

/** One bill, or null when it is not one of this company's. */
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

export async function getRefunds() {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.refunds(ctx))).data;
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

export async function getPaymentsMade(): Promise<PaymentReceived[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.paymentsMade(ctx))).data;
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

export async function getApAgeing(asOf?: string): Promise<ArAgeing | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.apAgeing(asOf, ctx));
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

export async function getCreditNotes(): Promise<(Invoice & { original?: { id: string; number: string | null } | null })[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.creditNotes(ctx))).data;
}

/** A customer's open invoices in one currency, oldest first: where a credit note's leftover credit can go. */
export async function getOpenInvoices(contactId: string, currency: string): Promise<Invoice[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.openInvoices(contactId, ctx))).data.filter((i) => i.currency === currency);
}

export async function getPaymentsReceived(): Promise<PaymentReceived[]> {
  await connection();
  if (isDemoMode()) return [];
  return (await withApi((ctx) => ledgerly.paymentsReceived(ctx))).data;
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

export async function getArAgeing(asOf?: string): Promise<ArAgeing | null> {
  await connection();
  if (isDemoMode()) return null;
  return withApi((ctx) => ledgerly.arAgeing(asOf, ctx));
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

export async function getInvoices(): Promise<Invoice[]> {
  await connection();
  if (isDemoMode()) return demoInvoices;
  return (await withApi((ctx) => ledgerly.invoices(ctx))).data;
}

export async function getProfitAndLoss(q: { from: string; to: string; compare_from?: string; compare_to?: string }): Promise<ProfitAndLoss> {
  await connection();
  if (isDemoMode()) return demoProfitAndLoss;
  return withApi((ctx) => ledgerly.profitAndLoss(q, ctx));
}

export interface ShellContext {
  companyName: string;
  companyNote: string;
  userName: string;
  demo: boolean;
  /** Every company the person can switch to, the current one included. */
  companies: { id: string; name: string }[];
  companyId: string;
  organisationId: string | null;
}

/** The company being worked on, and who is signed in, for the app shell. */
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
  const { name, companyId } = await getSession();
  const { data } = await withApi(({ token }) => ledgerly.companies(token));
  const company: Company | undefined = data.find((c) => c.id === companyId);
  if (!company) redirect("/onboarding");
  return {
    companyName: company.trading_name ?? company.legal_name,
    companyNote: company.base_currency,
    userName: name ?? "",
    demo: false,
    companies: data.map((c) => ({ id: c.id, name: c.trading_name ?? c.legal_name })),
    companyId: company.id,
    organisationId: company.organisation_id,
  };
}
