import "server-only";
import { redirect } from "next/navigation";
import { connection } from "next/server";
import { ApiError, isDemoMode, ledgerly, type Account, type ArAgeing, type Company, type CompanySettings, type Currencies, type Customer, type CustomerStatement, type Dimension, type Invoice, type InvoiceDetail, type Numbering, type OpeningBalances, type PaymentReceived, type ProfitAndLoss } from "./api";
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
    if (error instanceof ApiError && (error.status === 400 || error.status === 403)) redirect("/onboarding"); // no usable company
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

/** Numbering of the document types in use so far (invoices and credit notes). */
export async function getNumbering(): Promise<Numbering[]> {
  await connection();
  const types = ["invoice", "credit_note"];
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
  customers: { id: string; name: string; tax_id: string | null; currency: string | null; payment_terms_days: number }[];
  accounts: { id: string; code: string; name: string }[];
  taxRates: { id: string; code: string; name: string; rate: string }[];
  currencies: string[];
  baseCurrency: string;
  /** Manual numbering: the number is typed from the invoice book. */
  manualNumbers: boolean;
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
    const [customers, accounts, rates, currencies, numbering] = await Promise.all([
      ledgerly.customers({}, ctx), ledgerly.accounts(ctx), ledgerly.taxRates(ctx), ledgerly.currencies(ctx), ledgerly.numbering(ctx),
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
      demo: false,
    };
  });
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

export async function getPaymentReceived(id: string): Promise<PaymentReceived | null> {
  await connection();
  if (isDemoMode()) return null;
  try {
    return await withApi((ctx) => ledgerly.paymentReceived(id, ctx));
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return null;
    throw error;
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
