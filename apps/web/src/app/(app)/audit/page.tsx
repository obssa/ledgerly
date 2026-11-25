import { DateRangeFilter } from "@/components/DateRangeFilter";
import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { actionLabel, changesOf } from "@/lib/audit";
import { getAuditLog, verifyAuditLog, getReportPeriod } from "@/lib/data";
import { ExportLink } from "../reports/ReportsNav";

export const metadata = { title: "Audit log" };

const word = (v: unknown) => (typeof v === "string" && /^[a-z_]{1,60}$/.test(v) ? v : undefined);
const uuid = (v: unknown) => (typeof v === "string" && /^[0-9a-f-]{36}$/.test(v) ? v : undefined);

/** Module 10/11: every change, post, void, approval and export, who did it, when, what it was before and after, and why. */
export default async function AuditLogPage({ searchParams }: PageProps<"/audit">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "all");
  const filters = { from: (period.picked.from ?? undefined), to: (period.picked.to ?? undefined), action: word(q.action), user_id: uuid(q.user_id),
    subject_id: uuid(q.subject_id), before_id: typeof q.before_id === "string" && /^\d{1,18}$/.test(q.before_id) ? q.before_id : undefined };
  const [log, check] = await Promise.all([getAuditLog(filters), q.verify === "1" ? verifyAuditLog() : Promise.resolve(null)]);
  const more = log?.next_before_id ? `/audit?${new URLSearchParams(Object.entries({ ...filters, before_id: String(log.next_before_id) }).filter(([, v]) => v) as [string, string][]).toString()}` : null;

  return (
    <>
      <PageTitle eyebrow="Reports / Audit" title="Audit log"
        actions={log ? <ExportLink report="audit-log" params={{ from: filters.from, to: filters.to, action: filters.action, user_id: filters.user_id }} /> : undefined} />
      {!log ? <p className="text-[14px] text-muted">The audit log is not available in the demo.</p> : (
        <>
          <DateRangeFilter picked={period.picked} keep={q} fields={["action", "user_id"]}>
            <div className="min-w-[180px]"><label className="field-label" htmlFor="user_id">Who</label>
              <select className="field" id="user_id" name="user_id" defaultValue={filters.user_id ?? ""}><option value="">Everyone</option>
                {log.people.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
            <div className="min-w-[200px]"><label className="field-label" htmlFor="action">What</label>
              <select className="field" id="action" name="action" defaultValue={filters.action ?? ""}><option value="">Everything</option>
                {log.actions.map((a) => <option key={a} value={a}>{actionLabel(a)}</option>)}</select></div>
      </DateRangeFilter>
          <Card className="mb-4" label="Integrity">
            <div className="flex flex-wrap items-center gap-3">
              {check ? (check.intact
                ? <Pill tone="good">Intact: all {check.rows} entries as written</Pill>
                : <Pill tone="bad">Broken at entry {check.broken_at}</Pill>) : null}
              <Link href="/audit?verify=1" className="btn">Check the chain</Link>
              <span className="text-[13px] text-muted">Each entry carries the fingerprint of the one before it, so a changed or removed entry breaks the chain from there on. Entries can only be added.</span>
            </div>
          </Card>
          <Card className="!p-2" label="Entries">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">Record</th><th scope="col">Changes</th><th scope="col">Why</th></tr></thead>
                <tbody>
                  {log.data.length === 0 && <tr><td colSpan={6} className="text-muted">Nothing matches.</td></tr>}
                  {log.data.map((e) => (
                    <tr key={e.id} className="align-top">
                      <td className="num whitespace-nowrap text-[13px]">{e.at.slice(0, 16).replace("T", " ")}</td>
                      <td className="text-[13px]">{e.user?.name ?? <span className="text-muted">{e.actor_type}</span>}{e.ip && <span className="block text-[11px] text-muted">{e.ip}</span>}</td>
                      <td className="text-[13px] font-semibold">{actionLabel(e.action)}</td>
                      <td className="text-[13px]">{e.subject_type.replace(/_/g, " ")}
                        {e.subject_id && <Link href={`/audit?subject_id=${encodeURIComponent(e.subject_id)}`} className="ml-1 text-[11px] text-brand hover:underline">history</Link>}</td>
                      <td className="text-[12px]">
                        {changesOf(e.before, e.after).map((c) => (
                          <div key={c.key}><span className="text-muted">{c.key.replace(/_/g, " ")}:</span> {c.from && <><span className="line-through">{c.from}</span> → </>}{c.to}</div>
                        ))}
                      </td>
                      <td className="text-[13px]">{e.reason ?? ""}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
          {more && <nav aria-label="Pages" className="mt-3"><Link href={more} className="btn">Older</Link></nav>}
        </>
      )}
    </>
  );
}
