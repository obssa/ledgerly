import { DateRangeFilter } from "@/components/DateRangeFilter";
import { CLOSED_RANGE_PRESETS } from "@/lib/periods";
import Link from "@/components/Link";
import { Card, PageTitle, Pill, Segmented } from "@/components/ui";
import { getTaxCentre, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { FileReturn } from "./TaxForms";
import { Worksheet } from "./Worksheet";
import type { FilingStatus } from "@/lib/api";

export const metadata = { title: "Tax centre" };

const KINDS = [["vat", "VAT"], ["withholding", "Withholding tax"], ["tot", "Turnover tax"]] as const;
const STATUS: Record<FilingStatus, [string, "good" | "warn" | "bad" | "neutral"]> = {
  paid: ["Filed and paid", "good"], filed: ["Filed, not paid", "warn"], overdue: ["Overdue", "bad"], due_soon: ["Due soon", "warn"], due: ["Due", "neutral"], open: ["Period open", "neutral"],
};

/** Module 7: the VAT and withholding worksheets of any period, built from the ledger, filing a return and the returns filed. */
export default async function TaxCentrePage({ searchParams }: PageProps<"/tax">) {
  const q = await searchParams;
  const period = await getReportPeriod(q, "last_month", CLOSED_RANGE_PRESETS);
  const kind = q.kind === "withholding" || q.kind === "tot" ? q.kind : "vat";
  const centre = await getTaxCentre({ kind, from: period.from, to: period.to });

  return (
    <>
      <PageTitle eyebrow="Compliance" title="Tax centre" />
      <Segmented label="Return" items={KINDS.map(([v, l]) => ({ key: v, href: v === "vat" ? "/tax" : `/tax?kind=${v}`, label: l, active: kind === v }))} />
      {!centre ? (
        <p className="text-[14px] text-muted">
          {kind === "tot" ? <>This company has no turnover tax: add a TOT rate under Tax rates if your country has one (your tax adviser confirms the rate). <a href="/tax" className="font-semibold text-brand hover:underline">Back to VAT</a></> : "Not available in the demo, or for your role."}
        </p>
      ) : (
        <>
          <DateRangeFilter picked={period.picked} keep={q} allowed={CLOSED_RANGE_PRESETS} fields={["kind"]}>
            <input type="hidden" name="kind" value={kind} />
      </DateRangeFilter>
          {(centre.calendar.overdue > 0 || centre.calendar.due_soon > 0) && (
            <p role="status" className={`mb-4 rounded-lg px-3 py-2 text-[13px] font-semibold ${centre.calendar.overdue > 0 ? "bg-bad-tint text-bad" : "bg-warn-tint text-warn"}`}>
              {centre.calendar.overdue > 0 && <>{centre.calendar.overdue} return{centre.calendar.overdue === 1 ? "" : "s"} overdue. </>}
              {centre.calendar.due_soon > 0 && <>{centre.calendar.due_soon} due within ten days.</>} See the filing calendar below.
            </p>
          )}
          <Worksheet sheet={centre.sheet} />
          {(centre.sheet.filed ?? []).length > 0 ? (
            <p role="status" className="mb-4 rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">
              Filed: {(centre.sheet.filed ?? []).map((f) => (
                <Link key={f.id} href={`/tax/returns/${encodeURIComponent(f.id)}`} className="mr-2 underline">{f.period_start} to {f.period_end} (on {f.filed_on})</Link>
              ))}
            </p>
          ) : centre.sheet.can?.file ? (
            <Card className="mb-4 max-w-[900px]" label="File the return">
              <h2 className="mb-3 text-[15px] font-bold">Record the return as filed</h2>
              <FileReturn kind={centre.sheet.kind} from={centre.sheet.from} to={centre.sheet.to} />
            </Card>
          ) : null}
          <Card className="mb-4 !p-2" label="Filing calendar">
            <h2 className="px-3 pt-2 text-[15px] font-bold">Filing calendar</h2>
            <div className="grid gap-4 overflow-x-auto md:grid-cols-2">
              {KINDS.filter(([k]) => centre.calendar.kinds[k]).map(([k, label]) => (
                <table key={k} className="tbl">
                  <caption className="px-3 pt-2 text-left text-[14px] font-bold">{label}</caption>
                  <thead><tr><th scope="col">Period</th><th scope="col">Due</th><th scope="col">Where it stands</th></tr></thead>
                  <tbody>
                    {(centre.calendar.kinds[k]?.periods ?? []).slice(0, 6).map((p) => (
                      <tr key={p.period_start}>
                        <td><Link href={`/tax?${new URLSearchParams({ kind: k, from: p.period_start, to: p.period_end }).toString()}`} className="num hover:underline">{p.period_start.slice(0, 7)}</Link></td>
                        <td className="num">{p.due_on}</td>
                        <td>{p.return ? <Link href={`/tax/returns/${encodeURIComponent(p.return.id)}`}><Pill tone={STATUS[p.status][1]}>{STATUS[p.status][0]}</Pill></Link> : <Pill tone={STATUS[p.status][1]}>{STATUS[p.status][0]}</Pill>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ))}
            </div>
            {KINDS.map(([k]) => centre.calendar.kinds[k]?.note).filter(Boolean).map((note) => <p key={note} className="px-3 pb-1 text-[12px] text-muted">{note}</p>)}
            {Object.keys(centre.calendar.kinds).length === 0 && <p className="px-3 pb-2 text-[13px] text-muted">The country pack sets no filing dates for this company&apos;s country yet.</p>}
          </Card>
          <Card className="mb-4" label="Tax audit file">
            <h2 className="mb-2 text-[15px] font-bold">Tax audit file</h2>
            <p className="mb-3 text-[13px] text-muted">For the tax authority or an auditor: every document and every ledger line of the period above, as CSV.</p>
            <div className="flex flex-wrap gap-2">
              {(["documents", "ledger"] as const).map((part) => (
                <a key={part} className="btn" href={`/tax/audit-file?${new URLSearchParams({ part, from: centre.sheet.from, to: centre.sheet.to }).toString()}`}>
                  {part === "documents" ? "Download the documents" : "Download the ledger lines"}
                </a>
              ))}
            </div>
          </Card>
          <Card className="!p-2" label="Returns filed">
            <h2 className="px-3 pt-2 text-[15px] font-bold">Returns filed</h2>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Return</th><th scope="col">Period</th><th scope="col">Filed</th><th scope="col">Reference</th><th scope="col" className="!text-right">Net</th><th scope="col">Paid</th></tr></thead>
                <tbody>
                  {centre.returns.length === 0 && <tr><td colSpan={6} className="text-muted">None yet.</td></tr>}
                  {centre.returns.map((r) => (
                    <tr key={r.id}>
                      <td><Link href={`/tax/returns/${encodeURIComponent(r.id)}`} className="font-semibold hover:underline">{r.kind === "vat" ? "VAT" : "Withholding"}</Link></td>
                      <td className="num">{r.period_start} to {r.period_end}</td><td className="num">{r.filed_on}</td><td>{r.reference ?? ""}</td>
                      <td className="num text-right">{formatAmount(r.net)}</td>
                      <td>{r.settled_on ? <Pill tone="good">{r.settled_on}</Pill> : <Pill tone="warn">Not yet</Pill>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </>
  );
}
