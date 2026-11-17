import Link from "next/link";
import { notFound } from "next/navigation";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getCustomers, getProject, getTimeOptions } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { milestoneTotal, PROJECT_BILLING, PROJECT_STATUSES } from "@/lib/projects";
import { ProjectForm } from "../ProjectForm";
import { BillTime } from "../TimeForms";
import { AddMilestone, MilestoneControls } from "../MilestoneForms";

export const metadata = { title: "Project" };

const date = (v: unknown) => (typeof v === "string" && /^\d{4}-\d{2}-\d{2}$/.test(v) ? v : undefined);
const STATUS = Object.fromEntries(PROJECT_STATUSES) as Record<string, string>;
const BILLING = Object.fromEntries(PROJECT_BILLING) as Record<string, string>;
const DOC: Record<string, [string, string]> = {
  invoice: ["Invoice", "invoices"], credit_note: ["Credit note", "invoices"], sales_receipt: ["Cash sale", "invoices"],
  bill: ["Bill", "bills"], vendor_credit: ["Supplier credit", "bills"], expense: ["Expense", "expenses"],
};

/** What a budget or contract leaves: the budget less the actual, as a figure and a share. */
function Against({ label, actual, budget }: { label: string; actual: string; budget: string | null }) {
  if (!budget || budget === "0.00") return <div><dt className="text-muted">{label}</dt><dd className="num">—</dd></div>;
  const share = Math.round((Number(actual) / Number(budget)) * 100);
  return <div><dt className="text-muted">{label}</dt><dd className="num">{formatAmount(budget)} <span className={`text-[13px] ${share > 100 ? "text-bad" : "text-muted"}`}>({share}% used)</span></dd></div>;
}

/** Module 9a: one project, its income, costs and profit (all time or a period), account by account, and its documents. */
const MILESTONE = { planned: "Planned", drafted: "On a draft invoice", invoiced: "Invoiced", paid: "Paid" } as const;

export default async function ProjectPage({ params, searchParams }: PageProps<"/projects/[id]">) {
  const { id } = await params;
  const q = await searchParams;
  const period = { from: date(q.from), to: date(q.to) };
  const [p, customers, timeOptions] = await Promise.all([getProject(id, period), getCustomers({}), getTimeOptions()]);
  if (!p) notFound();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/projects" className="hover:underline">Projects</Link> / <span className="num">{p.code}</span></>}
        title={<>{p.name} <Pill tone={p.status === "active" ? "good" : p.status === "on_hold" ? "warn" : "neutral"}>{STATUS[p.status] ?? p.status}</Pill></>} />
      <form className="mb-4 flex flex-wrap items-end gap-2.5">
        <div><label className="field-label" htmlFor="from">From</label><input className="field" id="from" name="from" type="date" defaultValue={period.from} /></div>
        <div><label className="field-label" htmlFor="to">To</label><input className="field" id="to" name="to" type="date" defaultValue={period.to} /></div>
        <button type="submit" className="btn">Show</button>
        {(period.from || period.to) && <Link href={`/projects/${encodeURIComponent(p.id)}`} className="btn">All time</Link>}
      </form>
      <Card className="mb-4" label="Profit">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Income</dt><dd className="num text-[18px] font-bold">{formatAmount(p.income)}</dd></div>
          <div><dt className="text-muted">Costs</dt><dd className="num text-[18px] font-bold">{formatAmount(p.costs)}</dd></div>
          <div><dt className="text-muted">Profit</dt><dd className={`num text-[18px] font-bold ${p.profit.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(p.profit)}</dd></div>
          <Against label="Contract value" actual={p.income} budget={p.contract_value} />
          <Against label="Income budget" actual={p.income} budget={p.budget_income} />
          <Against label="Cost budget" actual={p.costs} budget={p.budget_cost} />
        </dl>
        <p className="mt-3 text-[13px] text-muted">
          {p.customer ? <>For <Link href={`/customers/${encodeURIComponent(p.customer.id)}`} className="font-semibold hover:underline">{p.customer.name}</Link>. </> : "Internal. "}
          {BILLING[p.billing]}{p.starts_on && <>, from <span className="num">{p.starts_on}</span></>}{p.ends_on && <> to <span className="num">{p.ends_on}</span></>}.
          {" "}Read from the ledger lines tagged with the project{period.from || period.to ? ", for the period above" : ""}.
        </p>
      </Card>
      {(p.billing === "fixed_fee" || (p.milestones ?? []).length > 0) && (
        <Card className="mb-4 !p-2" label="Milestones">
          <div className="flex flex-wrap items-baseline justify-between gap-2 px-3 pt-2">
            <h2 className="text-[15px] font-bold">Milestones</h2>
            {p.contract_value && (
              <span className="text-[13px] text-muted">Scheduled <span className="num font-semibold">{formatAmount(milestoneTotal(p.milestones ?? []))}</span> of the contract&apos;s <span className="num">{formatAmount(p.contract_value)}</span></span>
            )}
          </div>
          {(p.milestones ?? []).length === 0 ? (
            <p className="px-3 py-2 text-[13px] text-muted">No milestones yet. Split the contract into the stages you invoice.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Milestone</th><th scope="col">Due</th><th scope="col" className="!text-right">Amount</th><th scope="col">Billing</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>
                  {(p.milestones ?? []).map((m) => (
                    <tr key={m.id} className="align-top">
                      <td className="font-semibold">{m.name}</td>
                      <td className="num whitespace-nowrap">{m.due_on ?? ""}</td>
                      <td className="num text-right">{formatAmount(m.amount)}</td>
                      <td>
                        <Pill tone={m.status === "paid" ? "good" : m.status === "planned" ? "neutral" : "warn"}>{MILESTONE[m.status]}</Pill>
                        {m.invoice && <Link href={`/invoices/${encodeURIComponent(m.invoice.id)}`} className="ml-2 text-[13px] font-semibold text-brand hover:underline">{m.invoice.number ?? "Draft"}</Link>}
                      </td>
                      <td className="text-right">{p.can.manage && <MilestoneControls projectId={p.id} milestone={m} incomeAccounts={timeOptions?.incomeAccounts ?? []} canBill={Boolean(p.customer)} />}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {p.can.manage && <div className="border-t border-line px-3 py-3"><AddMilestone projectId={p.id} hasContractValue={Boolean(p.contract_value)} /></div>}
        </Card>
      )}
      <Card className="mb-4" label="Time">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-[15px] font-bold">Time</h2>
          <Link href={`/time?project=${encodeURIComponent(p.id)}`} className="text-[13px] font-semibold text-brand hover:underline">The hours, one by one</Link>
        </div>
        <dl className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">Hours logged</dt><dd className="num">{p.time.logged_hours}</dd></div>
          <div><dt className="text-muted">Billable, not billed</dt><dd className="num font-semibold">{p.time.unbilled_hours}</dd></div>
          <div><dt className="text-muted">Their value</dt><dd className="num font-semibold">{formatAmount(p.time.unbilled_value)}</dd></div>
          {p.hourly_rate && <div><dt className="text-muted">Hourly rate</dt><dd className="num">{formatAmount(p.hourly_rate)}</dd></div>}
        </dl>
        {p.time.unpriced && <p className="mt-2 text-[13px] text-warn">Some hours have no rate and the project has none: give it an hourly rate below before billing.</p>}
        {p.can.manage && p.customer && p.time.unbilled_hours !== "0.00" && timeOptions && (
          <div className="mt-4 border-t border-line pt-4"><BillTime projectId={p.id} incomeAccounts={timeOptions.incomeAccounts} /></div>
        )}
      </Card>
      <Card className="mb-4 !p-2" label="By account">
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Account</th><th scope="col" className="!text-right">Amount</th></tr></thead>
            <tbody>
              {p.accounts.length === 0 && <tr><td colSpan={2} className="text-muted">Nothing tagged with this project yet.</td></tr>}
              {p.accounts.map((a) => (
                <tr key={a.account_id}>
                  <td><Link href={`/reports/general-ledger?account_id=${encodeURIComponent(a.account_id)}&project_id=${encodeURIComponent(p.id)}`} className="hover:underline"><span className="num">{a.code}</span> {a.name}</Link>
                    <span className="ml-2 text-[12px] text-muted">{a.type}</span></td>
                  <td className="num text-right">{formatAmount(a.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <Card className="mb-4 !p-2" label="Documents">
        <h2 className="px-3 pt-2 text-[15px] font-bold">Documents tagged with it</h2>
        <div className="overflow-x-auto">
          <table className="tbl">
            <thead><tr><th scope="col">Date</th><th scope="col">Document</th><th scope="col">Customer or supplier</th><th scope="col" className="!text-right">On this project</th></tr></thead>
            <tbody>
              {p.documents.length === 0 && <tr><td colSpan={4} className="text-muted">None yet.</td></tr>}
              {p.documents.map((d) => (
                <tr key={d.id}>
                  <td className="num">{d.date}</td>
                  <td><Link href={`/${DOC[d.type]?.[1] ?? "invoices"}/${encodeURIComponent(d.id)}`} className="hover:underline">{DOC[d.type]?.[0] ?? d.type} {d.number ?? ""}</Link></td>
                  <td>{d.contact}</td>
                  <td className="num text-right">{d.currency} {formatAmount(d.amount)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {p.can.manage && (
        <Card label="Edit">
          <h2 className="mb-3 text-[15px] font-bold">Edit</h2>
          <ProjectForm project={p} customers={customers.data.map((c) => ({ id: c.id, name: c.name }))} />
        </Card>
      )}
    </>
  );
}
