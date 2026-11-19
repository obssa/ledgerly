import { Card, PageTitle } from "@/components/ui";
import { getBalanceSheet, getCashFlow, getChangesInEquity, getNotes, getProfitAndLoss, getReportPeriod } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { periodLabel } from "@/lib/reports";
import { PeriodForm } from "../ReportsNav";
import { PrintPack } from "./PrintPack";

export const metadata = { title: "Financial statements and notes" };

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const METHOD: Record<string, string> = { straight_line: "straight line", reducing_balance: "reducing balance" };

/**
 * Module 10: the notes pack. The four statements of a period and the notes behind them, from the books as they stand,
 * on one page to print or save as a PDF for the owners, the bank or the auditor.
 */
export default async function NotesPackPage({ searchParams }: PageProps<"/reports/notes-pack">) {
  const period = await getReportPeriod(await searchParams);
  const [notes, pl, bs, cf, ce] = await Promise.all([getNotes(period), getProfitAndLoss(period), getBalanceSheet(period.to), getCashFlow(period), getChangesInEquity(period)]);
  if (!notes) {
    return (
      <>
        <PageTitle eyebrow="Reports / Financial statements" title="Financial statements and notes" />
        <p className="text-[14px] text-muted">Not available in the demo, for your role, or for that period.</p>
      </>
    );
  }
  const cur = notes.currency;
  const row = (label: string, amount: string | null | undefined, strong = false) => (
    <tr className={strong ? "font-bold" : undefined}><td>{label}</td><td className="num text-right">{amount == null ? "" : formatAmount(amount, cur)}</td></tr>
  );

  return (
    <>
      <PageTitle eyebrow="Reports / Financial statements" title="Financial statements and notes" actions={<PrintPack />} />
      <div className="print:hidden"><PeriodForm from={period.from} to={period.to} /></div>
      <div className="flex max-w-[900px] flex-col gap-4">
        <Card label="Cover">
          <h2 className="text-[22px] font-extrabold">{notes.company}</h2>
          <p className="mt-1 text-sm text-muted">Financial statements for {periodLabel(notes.from, notes.to)}, in {cur}, prepared from the books as they stand.</p>
        </Card>

        {pl && (
          <Card label="Statement of profit or loss">
            <h2 className="mb-2 text-[17px] font-bold">Statement of profit or loss</h2>
            <table className="tbl"><tbody>
              {row("Revenue", pl.totals.income.current)}
              {row("Cost of sales", pl.totals.cost_of_sales.current)}
              {row("Gross profit", pl.totals.gross_profit.current, true)}
              {row("Operating expenses", pl.totals.expenses.current)}
              {row("Profit for the period", pl.totals.net_profit.current, true)}
            </tbody></table>
          </Card>
        )}

        {bs && (
          <Card label="Statement of financial position">
            <h2 className="mb-2 text-[17px] font-bold">Statement of financial position at {period.to}</h2>
            <table className="tbl"><tbody>
              {bs.sections.assets.map((a) => row(`${a.code} ${a.name}`, a.amount))}
              {row("Total assets", bs.equation.assets, true)}
              {bs.sections.liabilities.map((a) => row(`${a.code} ${a.name}`, a.amount))}
              {row("Total liabilities", bs.equation.liabilities, true)}
              {bs.sections.equity.map((a) => row(`${a.code} ${a.name}`, a.amount))}
              {row("Profit to date", bs.equation.profit_to_date)}
              {row("Total liabilities and equity", bs.equation.liabilities_and_equity, true)}
            </tbody></table>
          </Card>
        )}

        {cf && (
          <Card label="Statement of cash flows">
            <h2 className="mb-2 text-[17px] font-bold">Statement of cash flows</h2>
            <table className="tbl"><tbody>
              {row("Net cash from operating activities", cf.totals.operating)}
              {row("Net cash from investing activities", cf.totals.investing)}
              {row("Net cash from financing activities", cf.totals.financing)}
              {row("Net change in cash", cf.net_change, true)}
              {row("Cash at the start", cf.opening_cash)}
              {row("Cash at the end", cf.closing_cash, true)}
            </tbody></table>
          </Card>
        )}

        {ce && (
          <Card label="Statement of changes in equity">
            <h2 className="mb-2 text-[17px] font-bold">Statement of changes in equity</h2>
            <table className="tbl">
              <thead><tr><th scope="col">Equity</th><th scope="col" className="!text-right">Opening</th><th scope="col" className="!text-right">Movement</th><th scope="col" className="!text-right">Closing</th></tr></thead>
              <tbody>
                {ce.lines.map((l) => (
                  <tr key={l.name}><td>{l.name}</td><td className="num text-right">{formatAmount(l.opening, cur)}</td><td className="num text-right">{formatAmount(l.movement, cur)}</td><td className="num text-right">{formatAmount(l.closing, cur)}</td></tr>
                ))}
              </tbody>
            </table>
          </Card>
        )}

        <Card label="Notes">
          <h2 className="mb-2 text-[17px] font-bold">Notes to the financial statements</h2>
          <h3 className="mt-3 text-[15px] font-bold">1. Accounting policies</h3>
          <ul className="mt-1 list-disc pl-5 text-sm">
            <li>The statements are prepared under {notes.policies.framework}, on the {notes.policies.basis} basis, in {cur}.</li>
            <li>The financial year starts in {MONTHS[(notes.policies.fiscal_year_start_month || 1) - 1]}.</li>
            <li>Inventory is measured at the lower of cost and estimated selling price less costs to complete and sell; cost is {notes.policies.inventory.toLowerCase()}.</li>
            <li>Property, plant and equipment is carried at cost less accumulated depreciation{notes.policies.depreciation_methods.length > 0 ? `, depreciated by the ${notes.policies.depreciation_methods.map((m) => METHOD[m] ?? m).join(" or ")} method over its useful life` : ""}.</li>
          </ul>

          <h3 className="mt-4 text-[15px] font-bold">2. Property, plant and equipment</h3>
          <table className="tbl mt-1"><tbody>
            {row("Cost at the start", notes.ppe.cost.opening)}
            {row("Additions", notes.ppe.cost.additions)}
            {row("Disposals", notes.ppe.cost.disposals)}
            {row("Cost at the end", notes.ppe.cost.closing, true)}
            {row("Accumulated depreciation at the start", notes.ppe.depreciation.opening)}
            {row("Charged for the period", notes.ppe.depreciation.charged)}
            {row("Released on disposals", notes.ppe.depreciation.released)}
            {row("Accumulated depreciation at the end", notes.ppe.depreciation.closing, true)}
            {row("Carrying amount at the start", notes.ppe.carrying_opening)}
            {row("Carrying amount at the end", notes.ppe.carrying_closing, true)}
          </tbody></table>

          {([["3. Trade and other receivables", notes.receivables], ["4. Inventories", notes.inventory], ["5. Trade and other payables", notes.payables],
            ["6. Taxes payable", notes.taxes], ["7. Borrowings", notes.loans]] as const).map(([title, note]) => (
            <div key={title}>
              <h3 className="mt-4 text-[15px] font-bold">{title}</h3>
              {note.lines.length === 0 ? <p className="mt-1 text-sm text-muted">None at the end of the period.</p> : (
                <table className="tbl mt-1"><tbody>
                  {note.lines.map((l) => row(`${l.code} ${l.name}`, l.amount))}
                  {row("Total", note.total, true)}
                </tbody></table>
              )}
            </div>
          ))}
          <p className="mt-4 text-[12px] text-muted">These notes are drawn from the books; add any judgements, commitments and events after the period before the statements are signed.</p>
        </Card>
      </div>
    </>
  );
}
