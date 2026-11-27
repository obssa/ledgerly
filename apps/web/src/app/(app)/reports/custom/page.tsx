import { ReportFooter, ReportHeader } from "@/components/ReportHeader";
import { exportPeriod } from "@/lib/periodParams";
import { DateRangeFilter } from "@/components/DateRangeFilter";
import { rangeLabel } from "@/lib/periods";
import { Card, PageTitle } from "@/components/ui";
import { getCustomReport, getDimensions, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { CUSTOM_COLUMNS, CUSTOM_MEASURES, CUSTOM_ROWS, CUSTOM_TYPES, columnLabel, customOptions } from "@/lib/reports";
import { ExportLink, ReportsNav } from "../ReportsNav";

export const metadata = { title: "Custom report" };

/**
 * Module 10: a report put together here, from the ledger lines of the period: what the rows are, whether the columns are
 * the whole period or each month or quarter, which figure, and filters. A plain GET form, so it works without JavaScript
 * and the address can be saved, exported or scheduled like any other report.
 */
export default async function CustomReportPage({ searchParams }: PageProps<"/reports/custom">) {
  const q = await searchParams;
  const o = customOptions(q);
  const period = await getReportPeriod(q);
  const [r, dims] = await Promise.all([getCustomReport({ ...o, from: period.from, to: period.to }), getDimensions()]);
  const ofKind = (kind: string) => (dims ?? []).filter((d) => d.kind === kind && d.is_active);
  const rowLabel = CUSTOM_ROWS.find(([v]) => v === o.rows)?.[1] ?? "Account";
  const select = (name: string, label: string, options: readonly (readonly [string, string])[], value: string | undefined, empty?: string) => (
    <div><label className="field-label" htmlFor={name}>{label}</label>
      <select className="field" id={name} name={name} defaultValue={value ?? ""}>
        {empty && <option value="">{empty}</option>}
        {options.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
      </select></div>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Management" title="Custom report" actions={<ExportLink report="custom" params={{ ...o, ...exportPeriod(period.picked) }} />} />
      <DateRangeFilter picked={period.picked} keep={q} fields={["account_type", "class_id", "columns", "location_id", "measure", "project_id", "rows"]}>
        {select("rows", "Rows", CUSTOM_ROWS, o.rows)}
        {select("columns", "Columns", CUSTOM_COLUMNS, o.columns)}
        {select("measure", "Figure", CUSTOM_MEASURES, o.measure)}
        {select("account_type", "Accounts", CUSTOM_TYPES, o.account_type, "All")}
        {ofKind("project").length > 0 && select("project_id", "Project", ofKind("project").map((d) => [d.id, `${d.code} ${d.name}`] as const), o.project_id, "All")}
        {ofKind("location").length > 0 && select("location_id", "Location", ofKind("location").map((d) => [d.id, `${d.code} ${d.name}`] as const), o.location_id, "All")}
        {ofKind("class").length > 0 && select("class_id", "Class", ofKind("class").map((d) => [d.id, `${d.code} ${d.name}`] as const), o.class_id, "All")}
      </DateRangeFilter>
      <div className="flex flex-wrap items-start gap-4">
        <ReportsNav current="/reports/custom" />
        {!r ? <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p> : (
          <Card className="!p-2 min-w-0 flex-[4_1_560px]" label="Custom report">
            <div className="px-3 pt-3"><ReportHeader title="Custom report" period={rangeLabel(period.picked, period.ctx)} filters={[CUSTOM_MEASURES.find(([v]) => v === o.measure)?.[1]]} /></div>
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">{rowLabel}</th>
                  {r.columns.length > 1 && r.columns.map((c) => <th key={c} scope="col" className="!text-right">{columnLabel(c)}</th>)}
                  <th scope="col" className="!text-right">Total</th></tr></thead>
                <tbody>
                  {r.rows.length === 0 && <tr><td colSpan={r.columns.length + 2} className="text-muted">Nothing posted that matches, in the period.</td></tr>}
                  {r.rows.map((row) => (
                    <tr key={row.key ?? "none"}>
                      <td className={row.key ? "" : "text-muted"}>{row.code && <span className="num text-muted">{row.code} </span>}{row.label}</td>
                      {r.columns.length > 1 && row.values.map((v, i) => <td key={i} className="num text-right">{formatAmount(v)}</td>)}
                      <td className="num text-right font-semibold">{formatAmount(row.total)}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t-2 border-ink font-bold"><td>Total</td>
                  {r.columns.length > 1 && r.totals.map((t, i) => <td key={i} className="num text-right">{formatAmount(t)}</td>)}
                  <td className="num text-right">{formatAmount(r.total)}</td></tr></tfoot>
              </table>
            </div>
            <p className="px-3 pb-2 text-[13px] text-muted">From the ledger lines of the period, year-end closing left out. &quot;By its own side&quot; shows assets and expenses as debits less credits, and liabilities, equity and income as credits less debits.</p>
            <ReportFooter currency={r.currency} />
          </Card>
        )}
      </div>
    </>
  );
}
