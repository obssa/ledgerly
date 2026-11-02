import "server-only";
import { cookies } from "next/headers";
import { isDemoMode, ledgerly, type Invoice, type ProfitAndLoss } from "./api";
import { demoInvoices, demoProfitAndLoss } from "./demo";

/**
 * Server-side data access. In demo mode returns sample data; otherwise calls the API with the session's
 * token and selected company (cookies set by the sign-in flow, to be built in phase 1).
 */
async function context() {
  const jar = await cookies();
  return { token: jar.get("ledgerly_token")?.value, companyId: jar.get("ledgerly_company")?.value };
}

export async function getInvoices(): Promise<Invoice[]> {
  if (isDemoMode) return demoInvoices;
  return (await ledgerly.invoices(await context())).data;
}

export async function getProfitAndLoss(q: { from: string; to: string; compare_from?: string; compare_to?: string }): Promise<ProfitAndLoss> {
  if (isDemoMode) return demoProfitAndLoss;
  return ledgerly.profitAndLoss(q, await context());
}
