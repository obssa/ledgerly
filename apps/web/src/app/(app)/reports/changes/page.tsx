import { rangeLabel } from "@/lib/periods";
import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getAuditLog, getCompanySettings, getReportPeriod } from "@/lib/data";
import { actionLabel, changesOf } from "@/lib/history";
import { ReportsNav } from "../ReportsNav";

export const metadata = { title: "Changes" };

const KIND: Record<string, string> = { invoice: "Invoice", credit_note: "Credit note", bill: "Bill", vendor_credit: "Supplier credit", expense: "Expense", sales_receipt: "Cash sale", company: "Books" };
const HREF: Record<string, string> = { invoice: "/invoices", credit_note: "/invoices", bill: "/bills", vendor_credit: "/bills", expense: "/expenses", sales_receipt: "/sales-receipts" };

/**
 * Changes: every invoice, bill or credit that was edited, voided or credited, newest first. For each: which document,
 * who changed it and when, the reason they gave, and exactly what changed (each field from old to new, lines removed
 * and added). Read from the audit log, which nobody can change.
 */
export default async function ChangesReport({ searchParams }: PageProps<"/reports/changes">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "all");
  const filters = { from: period.picked.from ?? undefined, to: period.picked.to ?? undefined, user_id: typeof q.user_id === "string" && /^[0-9a-f-]{36}$/.test(q.user_id) ? q.user_id : undefined,
    before_id: typeof q.before_id === "string" && /^\d{1,18}$/.test(q.before_id) ? q.before_id : undefined };
  const [log, settings] = await Promise.all([getAuditLog({ ...filters, group: "document_changes" }), getCompanySettings()]);
  const zone = settings.timezone || "Africa/Addis_Ababa";
  const when = (iso: string) => new Date(iso).toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit", timeZone: zone });
  const more = (id: number) => `/reports/changes?${new URLSearchParams(Object.entries({ ...filters, before_id: String(id) }).filter(([, v]) => v) as [string, string][]).toString()}`;

  return (
    <>
      <PageTitle eyebrow="Reports / Audit" title="Changes" description="Every edit, void and credit of an invoice or bill: who made it, when, why, and exactly what changed." />
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/changes" />
        <div className="min-w-0 flex-[4_1_560px]">
          {!log ? <p className="text-[14px] text-muted">Shown to owners, admins and accountants.</p> : (
            <>
              <DateRangeFilter picked={period.picked} keep={q} fields={["user_id"]}>
                <div><label className="field-label" htmlFor="user_id">Changed by</label>
                  <select className="field" id="user_id" name="user_id" defaultValue={filters.user_id ?? ""}>
                    <option value="">Anyone</option>
                    {log.people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                  </select></div>
              </DateRangeFilter>
              <Card className="!p-2" label="Changes">
                <div className="px-3 pt-3"><ReportHeader title="Changes: who changed what" period={rangeLabel(period.picked, period.ctx.calendar)} /></div>
                <div className="overflow-x-auto">
                  <table className="tbl">
                    <thead><tr><th scope="col">When</th><th scope="col">Document</th><th scope="col">What happened</th><th scope="col">By</th><th scope="col">What changed</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                      {log.data.length === 0 && <tr><td colSpan={6} className="py-8 text-center text-muted">No changes in this period.</td></tr>}
                      {log.data.map((e) => {
                        const changes = changesOf({ id: e.id, action: e.action, user_id: e.user?.id ?? null, before: e.before, after: e.after, reason: e.reason, created_at: e.at });
                        const doc = e.document;
                        const href = doc && HREF[doc.type] ? `${HREF[doc.type]}/${encodeURIComponent(doc.id)}` : null;
                        return (
                          <tr key={e.id} className="align-top">
                            <td className="num">{when(e.at)}</td>
                            <td>
                              <span className="block text-[12px] text-muted">{KIND[doc?.type ?? e.subject_type] ?? e.subject_type}</span>
                              {href ? <Link href={href} className="num font-semibold text-ink hover:underline">{doc?.number ?? "Draft"}</Link> : <span className="num font-semibold">{doc?.number ?? "—"}</span>}
                            </td>
                            <td><Pill tone={e.action === "voided" ? "bad" : e.action === "edited_after_posting" ? "warn" : "neutral"}>{actionLabel(e.action)}</Pill></td>
                            <td>{e.user?.name ?? "Evenbooks"}</td>
                            <td className="!whitespace-normal text-[13px]">
                              {changes.length === 0 ? <span className="text-muted">—</span> : (
                                <ul className="grid gap-0.5">
                                  {changes.map((c, i) => c.kind === "field" ? (
                                    <li key={i}><span className="text-muted">{c.label}:</span> <span className="num line-through decoration-faint">{c.from}</span> → <span className="num font-semibold">{c.to}</span></li>
                                  ) : (
                                    <li key={i} className={c.change === "added" ? "text-good" : "text-bad"}>
                                      <span className="sr-only">{c.change === "added" ? "Line added: " : "Line removed: "}</span>
                                      <span aria-hidden="true">{c.change === "added" ? "+ " : "− "}</span><span className="num">{c.text}</span>
                                    </li>
                                  ))}
                                </ul>
                              )}
                            </td>
                            <td className="!whitespace-normal text-[13px]">{e.reason ?? <span className="text-muted">—</span>}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
                {log.more && log.next_before_id && <p className="p-3"><Link href={more(log.next_before_id)} className="btn">Earlier changes</Link></p>}
                <ReportFooter basis={null} currency={null} />
              </Card>
            </>
          )}
        </div>
      </div>
    </>
  );
}
