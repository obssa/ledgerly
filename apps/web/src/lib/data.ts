import "server-only";
import { redirect } from "next/navigation";
import { connection } from "next/server";
import { ApiError, isDemoMode, ledgerly, type Account, type Company, type CompanySettings, type Currencies, type Dimension, type Invoice, type Numbering, type OpeningBalances, type ProfitAndLoss } from "./api";
import { demoCompany, demoCompanySettings, demoCurrencies, demoDimensions, demoNumbering, demoInvoices, demoProfitAndLoss } from "./demo";
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
