import { getCompanySettings, getShellContext } from "@/lib/data";
import { footerLine, preparedLabel } from "@/lib/reportHeader";
import { getSession } from "@/lib/session";

/**
 * The top of every report, on screen and on paper, as QuickBooks heads its reports: the company's logo and name (and
 * its legal name when that differs), the report's name, its period or date, and any filter narrowing it. The CSV and
 * Excel files open with the same lines (lib/reportHeader.ts).
 */
export async function ReportHeader({ title, period, filters }: { title: string; period: string; filters?: (string | null | undefined)[] }) {
  const [settings, shell] = await Promise.all([getCompanySettings().catch(() => null), getShellContext()]);
  const company = shell.companyName;
  const legal = settings?.legal_name;
  const shown = (filters ?? []).filter((f): f is string => Boolean(f));
  return (
    <header className="report-header mb-4 text-center">
      {settings?.has_logo && (
        // eslint-disable-next-line @next/next/no-img-element -- the company's own logo, served by this app with its own cache rules
        <img src="/company-logo" alt="" className="mx-auto mb-2 max-h-[48px] max-w-[180px] object-contain" />
      )}
      <div className="text-[17px] font-extrabold">{company}</div>
      {legal && legal !== company && <div className="text-[12px] text-muted">{legal}</div>}
      <h2 className="mt-1 text-[15px] font-bold">{title}</h2>
      <div className="num text-[13px] text-muted">{period}</div>
      {shown.length > 0 && <div className="text-[12px] text-muted">{shown.join(" · ")}</div>}
    </header>
  );
}

/** The foot of every report: the basis it is kept on, its currency, and when and by whom it was prepared. */
export async function ReportFooter({ basis, currency }: { basis?: "accrual" | "cash" | null; currency?: string | null }) {
  const [settings, session] = await Promise.all([getCompanySettings().catch(() => null), getSession()]);
  const prepared = preparedLabel(new Date(), settings?.timezone || "Africa/Addis_Ababa", settings?.calendar ?? "gregorian", session.name);
  const line = footerLine({
    company: "", title: "", period: "", prepared,
    basis: basis === null ? null : basis ?? settings?.accounting_basis ?? "accrual",
    currency: currency === null ? null : currency ?? settings?.base_currency ?? null,
  });
  return <p className="report-footer mt-4 border-t border-line pt-2 text-center text-[12px] text-muted">{line}</p>;
}
