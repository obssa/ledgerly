import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getTimeEntries, getTimeOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { LogTime, RemoveTime } from "../projects/TimeForms";

export const metadata = { title: "Timesheets" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);

/** Module 9a: hours logged against projects: one's own, or everyone's for whoever manages projects. */
export default async function TimesheetsPage({ searchParams }: PageProps<"/time">) {
  const q = await searchParams;
  const filters = { from: date(q.from), to: date(q.to), project_id: typeof q.project === "string" && /^[0-9a-f-]{36}$/.test(q.project) ? q.project : undefined };
  const [entries, options] = await Promise.all([getTimeEntries(filters), getTimeOptions()]);
  const total = (entries?.data ?? []).reduce((t, e) => t + Number(e.hours), 0);

  return (
    <>
      <PageTitle eyebrow="Operations" title="Timesheets" />
      {!entries || !options ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
        <>
          <Card className="mb-4" label="Log hours">
            <h2 className="mb-3 text-[15px] font-bold">Log hours</h2>
            {options.projects.length === 0 ? <p className="text-[14px] text-muted">There are no active projects yet: <Link href="/projects" className="font-semibold text-brand hover:underline">add one</Link>.</p>
              : <LogTime projects={options.projects} projectId={filters.project_id} />}
          </Card>
          <form className="mb-4 flex flex-wrap items-end gap-2.5">
            <div className="min-w-[200px]">
              <label className="field-label" htmlFor="project">Project</label>
              <select className="field" id="project" name="project" defaultValue={filters.project_id ?? ""}>
                <option value="">All</option>
                {options.projects.map((p) => <option key={p.id} value={p.id}>{p.code} {p.name}</option>)}
              </select>
            </div>
            <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={filters.from} /></div>
            <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={filters.to} /></div>
            <button type="submit" className="btn">Show</button>
          </form>
          <Card className="!p-2" label="Hours">
            <h2 className="px-3 pt-2 text-[15px] font-bold">{entries.can.manage ? "Everyone's hours" : "My hours"}</h2>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead>
                  <tr><th scope="col">Day</th><th scope="col">Project</th><th scope="col">Who</th><th scope="col">What</th><th scope="col" className="!text-right">Hours</th><th scope="col" className="!text-right">Rate</th><th scope="col">Billing</th></tr>
                </thead>
                <tbody>
                  {entries.data.length === 0 && <tr><td colSpan={7} className="text-muted">No hours logged.</td></tr>}
                  {entries.data.map((e) => (
                    <tr key={e.id}>
                      <td className="num">{e.work_date}</td>
                      <td><Link href={`/projects/${encodeURIComponent(e.project.id)}`} className="hover:underline"><span className="num">{e.project.code}</span> {e.project.name}</Link></td>
                      <td>{e.user.name}</td>
                      <td>{e.note ?? ""}</td>
                      <td className="num text-right">{e.hours}</td>
                      <td className="num text-right">{e.rate ? formatAmount(e.rate) : <span className="text-muted">project&apos;s</span>}</td>
                      <td>
                        {e.invoice ? <Link href={`/invoices/${encodeURIComponent(e.invoice.id)}`}><Pill tone="good">Billed{e.invoice.number ? ` ${e.invoice.number}` : ""}</Pill></Link>
                          : e.billable ? <span className="flex items-center gap-2"><Pill tone="warn">To bill</Pill><RemoveTime id={e.id} /></span>
                          : <span className="flex items-center gap-2"><Pill>Not billable</Pill><RemoveTime id={e.id} /></span>}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={4}>Total</td><td className="num text-right">{total.toFixed(2)}</td><td colSpan={2} /></tr></tfoot>
              </table>
            </div>
          </Card>
        </>
      )}
    </>
  );
}
